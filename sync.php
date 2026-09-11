<?php

declare(strict_types=1);

/**
 * Syncs mailing list members and sender policy from hitobito into GNU Mailman 3
 * on Hostpoint, by driving the Postorius web forms. See README.md.
 */

const CHUNK_SIZE = 50;   // addresses per mass_subscribe POST — Postorius calls Core once per address
const PAGE_SIZE = 5;    // hitobito lists per page; each one carries its whole subscriber list
const SETTINGS_SECTION = 'message_acceptance';
// Resolving the subscribers of a page takes hitobito well over a minute. Nothing here
// is interactive, so one generous timeout beats tuning it per request.
const HTTP_TIMEOUT = 300;

/** Most permissive last. Only 'accept' and 'hold' are ever produced from hitobito. */
const ACTION_RANK = ['discard' => 0, 'reject' => 1, 'hold' => 2, 'defer' => 3, 'accept' => 4];

// ---------------------------------------------------------------- pure helpers

/** `*@example.com` becomes a Mailman regex; anything else is a plain address. */
function sender_pattern(string $entry): string
{
    $entry = strtolower(trim($entry));
    if (!str_starts_with($entry, '*@')) {
        return $entry;
    }
    return '^.*@' . str_replace('.', '\.', substr($entry, 2)) . '$';
}

/** hitobito validates additional_sender as comma- or semicolon-separated entries. */
function split_senders(string $additional): array
{
    $parts = preg_split('/[,;]/', $additional) ?: [];
    return array_values(array_filter(array_map('trim', $parts), fn ($s) => $s !== ''));
}

/**
 * The publisher field may name several target lists, separated by commas,
 * semicolons or whitespace. Anything that isn't an email address is ignored, so a
 * publisher used for its documented purpose simply doesn't opt the list in.
 */
function split_addresses(string $publisher): array
{
    $parts = preg_split('/[\s,;]+/', strtolower(trim($publisher))) ?: [];
    return array_values(array_filter($parts, fn ($p) => (bool) filter_var($p, FILTER_VALIDATE_EMAIL)));
}

/**
 * Turns one page of JSON:API `data` into `address => target`, where a target is
 * `{emails, senders, member_action, nonmember_action, names}` and emails/senders
 * are sets (`value => true`). Lists without an email address in `publisher` are
 * skipped: publisher is the opt-in marker. A list naming several addresses is
 * synced to each of them.
 */
function parse_lists(array $data, array $extraSenders): array
{
    $targets = [];
    foreach ($data as $item) {
        $attr = $item['attributes'] ?? [];
        $addresses = split_addresses((string) ($attr['publisher'] ?? ''));
        if ($addresses === []) {
            continue;
        }

        $emails = [];
        foreach ($attr['subscribers'] ?? [] as $person) {
            foreach ($person['list_emails'] ?? [] as $email) {
                $email = strtolower(trim((string) $email));
                if ($email !== '') {
                    $emails[$email] = true;
                }
            }
        }

        $senders = [];
        $entries = array_merge(split_senders((string) ($attr['additional_sender'] ?? '')), $extraSenders);
        foreach ($entries as $entry) {
            $senders[sender_pattern($entry)] = true;
        }

        $target = [
            'emails' => $emails,
            'senders' => $senders,
            'member_action' => ($attr['subscribers_may_post'] ?? false) ? 'accept' : 'hold',
            'nonmember_action' => ($attr['anyone_may_post'] ?? false) ? 'accept' : 'hold',
            'names' => [(string) ($attr['name'] ?? '')],
        ];

        foreach ($addresses as $address) {
            $targets[$address] = isset($targets[$address])
                ? merge_targets($targets[$address], $target)
                : $target;
        }
    }
    return $targets;
}

/** Two hitobito lists pointing at one address: union the sets, keep the more permissive action. */
function merge_targets(array $a, array $b): array
{
    return [
        'emails' => $a['emails'] + $b['emails'],
        'senders' => $a['senders'] + $b['senders'],
        'member_action' => ACTION_RANK[$a['member_action']] >= ACTION_RANK[$b['member_action']]
            ? $a['member_action'] : $b['member_action'],
        'nonmember_action' => ACTION_RANK[$a['nonmember_action']] >= ACTION_RANK[$b['nonmember_action']]
            ? $a['nonmember_action'] : $b['nonmember_action'],
        'names' => array_merge($a['names'], $b['names']),
    ];
}

/** csv_view writes one address per row. Returns a set keyed by lowercased address. */
function parse_csv_members(string $csv): array
{
    $set = [];
    foreach (preg_split('/\R/', $csv) ?: [] as $line) {
        $row = str_getcsv($line, ',', '"', '\\');
        $email = strtolower(trim((string) ($row[0] ?? '')));
        if ($email !== '' && str_contains($email, '@')) {
            $set[$email] = true;
        }
    }
    return $set;
}

/** Both arguments are sets keyed by lowercased address. */
function diff_members(array $current, array $desired): array
{
    return [
        'add' => array_keys(array_diff_key($desired, $current)),
        'remove' => array_keys(array_diff_key($current, $desired)),
    ];
}

function extract_csrf(string $html): string
{
    if (!preg_match('/name="csrfmiddlewaretoken"\s+value="([^"]+)"/', $html, $m)) {
        throw new RuntimeException('no CSRF token in response');
    }
    return $m[1];
}

/**
 * Serializes the whole form containing $mustContain, the way a browser would:
 * every named input/select/textarea, checked radios and checkboxes only.
 *
 * This has to be faithful. Postorius validates the entire settings section, so a
 * field we fail to send is cleaned to empty and saved as a change — silently
 * wiping settings someone tuned by hand.
 */
function serialize_form(string $html, array $mustContain): array
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    $xpath = new DOMXPath($doc);

    foreach ($xpath->query('//form') ?: [] as $form) {
        $fields = serialize_form_node($xpath, $form);
        foreach ($mustContain as $name) {
            if (!array_key_exists($name, $fields)) {
                continue 2;
            }
        }
        return $fields;
    }
    throw new RuntimeException('no form containing ' . implode(', ', $mustContain));
}

function serialize_form_node(DOMXPath $xpath, DOMNode $form): array
{
    $fields = [];
    foreach ($xpath->query('.//input | .//select | .//textarea', $form) ?: [] as $el) {
        /** @var DOMElement $el */
        $name = $el->getAttribute('name');
        if ($name === '') {
            continue;
        }
        $tag = strtolower($el->nodeName);
        $type = strtolower($el->getAttribute('type'));

        if ($tag === 'input' && in_array($type, ['submit', 'button', 'image', 'reset', 'file'], true)) {
            continue;
        }
        if ($tag === 'input' && in_array($type, ['radio', 'checkbox'], true)) {
            if (!$el->hasAttribute('checked')) {
                continue;
            }
            $value = $el->hasAttribute('value') ? $el->getAttribute('value') : 'on';
        } elseif ($tag === 'textarea') {
            // Django renders <textarea>\n{{ value }}</textarea>; HTML drops that first newline.
            $value = preg_replace('/^\r?\n/', '', $el->textContent);
        } elseif ($tag === 'select') {
            $value = select_value($xpath, $el);
            if ($value === null) {
                continue;
            }
        } else {
            $value = $el->getAttribute('value');
        }

        if (array_key_exists($name, $fields)) {
            $fields[$name] = array_merge((array) $fields[$name], [$value]);
        } else {
            $fields[$name] = $value;
        }
    }
    return $fields;
}

function select_value(DOMXPath $xpath, DOMElement $select): ?string
{
    $first = null;
    foreach ($xpath->query('.//option', $select) ?: [] as $option) {
        /** @var DOMElement $option */
        $value = $option->hasAttribute('value') ? $option->getAttribute('value') : $option->textContent;
        $first ??= $value;
        if ($option->hasAttribute('selected')) {
            return $value;
        }
    }
    return $first;
}

/** ListOfStringsField renders and parses one entry per line. */
function lines_to_set(string $value): array
{
    $set = [];
    foreach (preg_split('/\R/', $value) ?: [] as $line) {
        $line = trim($line);
        if ($line !== '') {
            $set[$line] = true;
        }
    }
    return $set;
}

/** Django wants repeated `name=` pairs for multi-value fields, not PHP's `name[0]=`. */
function encode_form(array $fields): string
{
    $pairs = [];
    foreach ($fields as $name => $value) {
        foreach ((array) $value as $one) {
            $pairs[] = urlencode((string) $name) . '=' . urlencode((string) $one);
        }
    }
    return implode('&', $pairs);
}

// ------------------------------------------------------------------- http/api

function http(CurlHandle $ch, string $url, ?array $post = null, ?string $referer = null, array $headers = []): array
{
    $options = [
        CURLOPT_URL => $url,
        // Django rejects HTTPS POSTs whose Referer isn't same-origin.
        CURLOPT_REFERER => $referer ?? '',
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => HTTP_TIMEOUT,
    ];
    if ($post !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = encode_form($post);
    } else {
        // The handle is reused across requests; this is what resets it back to GET.
        $options[CURLOPT_HTTPGET] = true;
    }
    curl_setopt_array($ch, $options);
    $body = curl_exec($ch);
    if ($body === false) {
        throw new RuntimeException("$url: " . curl_error($ch));
    }
    return [curl_getinfo($ch, CURLINFO_RESPONSE_CODE), (string) $body];
}

function hitobito_targets(CurlHandle $ch, array $config): array
{
    $targets = [];
    for ($page = 1; ; $page++) {
        $query = http_build_query([
            'filter' => [
                'group_id' => ['eq' => implode(',', $config['group_ids'])],
                'publisher' => ['match' => '@'],
            ],
            'extra_fields' => ['mailing_lists' => 'subscribers'],
            'page' => ['size' => PAGE_SIZE, 'number' => $page],
        ]);
        $url = rtrim($config['hitobito_url'], '/') . '/api/mailing_lists?' . $query;
        [$status, $body] = http($ch, $url, null, null, [
            'X-TOKEN: ' . $config['hitobito_token'],
            'Accept: application/vnd.api+json',
        ]);
        if ($status !== 200) {
            throw new RuntimeException("hitobito returned $status for $url");
        }

        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $data = $payload['data'] ?? [];
        foreach (parse_lists($data, $config['extra_senders'] ?? []) as $address => $target) {
            $targets[$address] = isset($targets[$address])
                ? merge_targets($targets[$address], $target)
                : $target;
        }
        if (count($data) < PAGE_SIZE) {
            return $targets;
        }
    }
}

function postorius_login(CurlHandle $ch, string $base, string $user, string $password): void
{
    $url = "$base/accounts/login/";
    [, $body] = http($ch, $url);
    [, $body] = http($ch, $url, [
        'csrfmiddlewaretoken' => extract_csrf($body),
        'login' => $user,
        'password' => $password,
    ], $url);
    if (str_contains($body, 'name="password"')) {
        throw new RuntimeException('Postorius login failed — check postorius_user / postorius_password');
    }
}

/** Postorius addresses a list by its list_id — `kerngruppe@gloggi.ch` is `kerngruppe.gloggi.ch`. */
function list_id(string $address): string
{
    return str_replace('@', '.', $address);
}

function list_url(string $base, string $address, string $path): string
{
    return "$base/mailman3/lists/" . rawurlencode(list_id($address)) . "/$path";
}

/** GETs a form page and returns [csrf token, page body]. */
function form_page(CurlHandle $ch, string $url): array
{
    [$status, $body] = http($ch, $url);
    if ($status !== 200) {
        throw new RuntimeException("GET $url returned $status");
    }
    return [extract_csrf($body), $body];
}

// ----------------------------------------------------------------------- sync

function sync_members(CurlHandle $ch, string $base, string $address, array $diff, bool $dryRun): void
{
    if ($dryRun) {
        return;
    }
    foreach (array_chunk($diff['add'], CHUNK_SIZE) as $chunk) {
        $url = list_url($base, $address, 'mass_subscribe/');
        [$csrf] = form_page($ch, $url);
        http($ch, $url, [
            'csrfmiddlewaretoken' => $csrf,
            'emails' => implode("\n", $chunk),
            'pre_verified' => 'on',
            'pre_confirmed' => 'on',
            'pre_approved' => 'on',
            'send_welcome_message' => 'False',
        ], $url);
    }
    if ($diff['remove'] !== []) {
        $url = list_url($base, $address, 'mass_removal/');
        [$csrf] = form_page($ch, $url);
        http($ch, $url, [
            'csrfmiddlewaretoken' => $csrf,
            'emails' => implode("\n", $diff['remove']),
        ], $url);
    }
}

/**
 * Reads the message acceptance section, and rewrites it only if one of our three
 * fields differs. Everything else on the form is echoed back untouched.
 * Returns a description of what changed, or null.
 */
function sync_sender_policy(CurlHandle $ch, string $base, string $address, array $target, bool $dryRun): ?string
{
    $url = list_url($base, $address, 'settings/' . SETTINGS_SECTION);
    $wanted = [
        'default_member_action' => $target['member_action'],
        'default_nonmember_action' => $target['nonmember_action'],
    ];
    [$csrf, $body] = form_page($ch, $url);
    $fields = serialize_form($body, [...array_keys($wanted), 'accept_these_nonmembers']);

    $changes = [];
    foreach ($wanted as $name => $value) {
        if (($fields[$name] ?? null) !== $value) {
            $changes[] = "$name: " . ($fields[$name] ?? '?') . " → $value";
            $fields[$name] = $value;
        }
    }

    $current = lines_to_set((string) ($fields['accept_these_nonmembers'] ?? ''));
    if (array_diff_key($current, $target['senders']) || array_diff_key($target['senders'], $current)) {
        $changes[] = 'accepted senders: ' . count($current) . ' → ' . count($target['senders']);
        $fields['accept_these_nonmembers'] = implode("\n", array_keys($target['senders']));
    }

    if ($changes === []) {
        return null;
    }
    if (!$dryRun) {
        $fields['csrfmiddlewaretoken'] = $csrf;
        http($ch, $url, $fields, $url);
    }
    return implode(', ', $changes);
}

// ----------------------------------------------------------------------- main

function main(array $argv): int
{
    $dryRun = in_array('--dry-run', $argv, true);
    $verbose = in_array('-v', $argv, true);
    $force = in_array('--force', $argv, true);

    $config = require __DIR__ . '/config.php';
    $base = rtrim($config['postorius_url'], '/');
    $failed = false;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEFILE => '',   // enables the in-memory cookie engine
        CURLOPT_USERAGENT => 'hitobito-mailman-sync',
    ]);

    try {
        $targets = hitobito_targets($ch, $config);
        postorius_login($ch, $base, $config['postorius_user'], $config['postorius_password']);
    } catch (Throwable $e) {
        fwrite(STDERR, 'FATAL: ' . $e->getMessage() . "\n");
        return 1;
    }

    if ($verbose) {
        echo count($targets), " hitobito list(s) with a publisher address\n";
    }

    foreach ($targets as $address => $target) {
        $label = $address . ' (' . implode(', ', $target['names']) . ')';
        try {
            [$status, $csv] = http($ch, list_url($base, $address, 'csv_view/'));
            if ($status !== 200) {
                // Expected until someone creates the list in the Control Panel, so
                // this reports without failing the run.
                fwrite(STDERR, "$label: no such list, or not owned by this account (HTTP $status)\n");
                continue;
            }

            $current = parse_csv_members($csv);
            $diff = diff_members($current, $target['emails']);

            // A hitobito hiccup returning zero subscribers must not empty a live list.
            if ($target['emails'] === [] && $current !== [] && !$force) {
                fwrite(STDERR, "$label: hitobito reports no subscribers but the list has "
                    . count($current) . " — skipped, re-run with --force if this is intended\n");
                $failed = true;
                continue;
            }

            sync_members($ch, $base, $address, $diff, $dryRun);

            if (!$dryRun && ($diff['add'] || $diff['remove'])) {
                [, $csv] = http($ch, list_url($base, $address, 'csv_view/'));
                $left = diff_members(parse_csv_members($csv), $target['emails']);
                if ($left['add'] || $left['remove']) {
                    fwrite(STDERR, "$label: still out of sync after writing — "
                        . count($left['add']) . ' missing, ' . count($left['remove']) . " extra\n");
                    $failed = true;
                }
            }

            $policy = sync_sender_policy($ch, $base, $address, $target, $dryRun);

            if ($verbose || ($dryRun && ($diff['add'] || $diff['remove'] || $policy !== null))) {
                $prefix = $dryRun ? 'would sync' : 'synced';
                echo "$prefix $address: +", count($diff['add']), ' -', count($diff['remove']),
                    ' members', $policy !== null ? "; $policy" : '', "\n";
            }
        } catch (Throwable $e) {
            fwrite(STDERR, "$label: " . $e->getMessage() . "\n");
            $failed = true;
        }
    }

    curl_close($ch);
    return $failed ? 1 : 0;
}

if (!defined('SYNC_TEST')) {
    exit(main($argv));
}
