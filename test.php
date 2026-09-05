<?php

declare(strict_types=1);

// Offline checks for the logic that could corrupt real lists. Run: php test.php
// Silence means everything passed.

// Hostpoint ships zend.assertions=-1, which compiles assert() out entirely — the
// suite would pass without running one check. Refuse rather than lie.
if (ini_get('zend.assertions') !== '1') {
    fwrite(STDERR, "assertions are disabled; run: php -d zend.assertions=1 test.php\n");
    exit(1);
}

define('SYNC_TEST', 1);
require __DIR__ . '/sync.php';

// ---------------------------------------------------------------- sender rules

assert(sender_pattern('someone@example.org') === 'someone@example.org');
assert(sender_pattern('  Someone@Example.org ') === 'someone@example.org');
assert(sender_pattern('*@gloggi.ch') === '^.*@gloggi\.ch$');
assert(sender_pattern('*@lists.gloggi.ch') === '^.*@lists\.gloggi\.ch$');

assert(split_senders('a@b.ch;c@d.ch, e@f.ch') === ['a@b.ch', 'c@d.ch', 'e@f.ch']);
assert(split_senders('') === []);

// publisher may name several target lists, comma- and/or whitespace-separated.
assert(split_addresses('list1@gryfensee.ch, list2@gryfensee.ch') === ['list1@gryfensee.ch', 'list2@gryfensee.ch']);
assert(split_addresses("  List1@Gryfensee.ch\tlist2@gryfensee.ch ") === ['list1@gryfensee.ch', 'list2@gryfensee.ch']);
assert(split_addresses('a@b.ch;c@d.ch') === ['a@b.ch', 'c@d.ch']);
// A publisher used for its documented purpose is not an opt-in.
assert(split_addresses('Abteilungsleitung Gloggi') === []);
assert(split_addresses('') === []);

// ------------------------------------------------------------ hitobito parsing

$payload = json_decode(<<<'JSON'
{"data": [
  {"id": "1", "type": "mailing_lists", "attributes": {
    "name": "Kerngruppe", "publisher": "Kerngruppe@Gloggi.ch",
    "subscribers_may_post": true, "anyone_may_post": false,
    "additional_sender": "webform@gloggi.ch;*@partner.example",
    "subscribers": [
      {"primary_group_name": "Abteilung", "list_emails": ["Anna@example.org", "anna.private@example.net"]},
      {"primary_group_name": "Abteilung", "list_emails": ["beat@example.org"]},
      {"primary_group_name": "Abteilung", "list_emails": []}
    ]}},
  {"id": "2", "type": "mailing_lists", "attributes": {
    "name": "Kerngruppe Eltern", "publisher": "kerngruppe@gloggi.ch",
    "subscribers_may_post": false, "anyone_may_post": true,
    "additional_sender": "",
    "subscribers": [{"list_emails": ["carla@example.org"]}]}},
  {"id": "3", "type": "mailing_lists", "attributes": {
    "name": "Nicht synchronisiert", "publisher": "Abteilungsleitung",
    "subscribers_may_post": true, "anyone_may_post": false,
    "subscribers": [{"list_emails": ["dora@example.org"]}]}},
  {"id": "4", "type": "mailing_lists", "attributes": {
    "name": "Zwei Ziele", "publisher": "list1@gryfensee.ch, list2@gryfensee.ch",
    "subscribers_may_post": false, "anyone_may_post": false,
    "additional_sender": "",
    "subscribers": [{"list_emails": ["emil@example.org"]}]}}
]}
JSON, true, 512, JSON_THROW_ON_ERROR);

$targets = parse_lists($payload['data'], ['*@gloggi.ch']);

// A publisher that isn't an email address is not an opt-in; one naming two lists yields both.
assert(array_keys($targets) === ['kerngruppe@gloggi.ch', 'list1@gryfensee.ch', 'list2@gryfensee.ch']);
assert($targets['list1@gryfensee.ch'] == $targets['list2@gryfensee.ch']);
assert(array_keys($targets['list1@gryfensee.ch']['emails']) === ['emil@example.org']);
$target = $targets['kerngruppe@gloggi.ch'];

// Addresses are lowercased, everyone's extra addresses count, people without any don't break it,
// and both lists pointing at the same address are merged.
assert(array_keys($target['emails']) === [
    'anna@example.org', 'anna.private@example.net', 'beat@example.org', 'carla@example.org',
]);

// Merged: list 1 allows subscribers to post, list 2 allows anyone. Most permissive wins.
assert($target['member_action'] === 'accept');
assert($target['nonmember_action'] === 'accept');

// extra_senders is merged into every list, without duplicating what hitobito already said.
assert(array_keys($target['senders']) === [
    'webform@gloggi.ch', '^.*@partner\.example$', '^.*@gloggi\.ch$',
]);
assert(count(parse_lists($payload['data'], ['*@gloggi.ch', '*@gloggi.ch'])['kerngruppe@gloggi.ch']['senders']) === 3);

// ---------------------------------------------------------------- member diffs

$current = parse_csv_members("Anna@example.org\nbeat@example.org\nold@example.org\n\n");
assert(array_keys($current) === ['anna@example.org', 'beat@example.org', 'old@example.org']);

$diff = diff_members($current, $target['emails']);
assert($diff['add'] === ['anna.private@example.net', 'carla@example.org']);
assert($diff['remove'] === ['old@example.org']);

// Case differences alone are not a change.
assert(diff_members(parse_csv_members("ANNA@example.org"), ['anna@example.org' => true])
    === ['add' => [], 'remove' => []]);

// Postorius links to a list by list_id, not by its address.
assert(list_id('kerngruppe@gloggi.ch') === 'kerngruppe.gloggi.ch');
assert(list_url('https://lists.hostpoint.ch', 'kerngruppe@gloggi.ch', 'csv_view/')
    === 'https://lists.hostpoint.ch/mailman3/lists/kerngruppe.gloggi.ch/csv_view/');

// -------------------------------------------------------- settings round-trip

// Shaped like Django's rendering of MessageAcceptanceForm, preceded by a decoy form.
$settingsHtml = <<<'HTML'
<html><body>
<form action="/search" method="get"><input type="text" name="q" value="find"></form>
<form method="post">
  <input type="hidden" name="csrfmiddlewaretoken" value="TOKEN123">
  <textarea name="acceptable_aliases" cols="40" rows="10">
info@gloggi.ch
^.*@alias\.example$</textarea>
  <input type="radio" name="require_explicit_destination" value="True" checked>
  <input type="radio" name="require_explicit_destination" value="False">
  <input type="radio" name="administrivia" value="True">
  <input type="radio" name="administrivia" value="False" checked>
  <input type="radio" name="default_member_action" value="hold" checked>
  <input type="radio" name="default_member_action" value="reject">
  <input type="radio" name="default_member_action" value="accept">
  <input type="radio" name="default_nonmember_action" value="hold" checked>
  <input type="radio" name="default_nonmember_action" value="accept">
  <textarea name="accept_these_nonmembers" cols="40" rows="10">
webform@gloggi.ch</textarea>
  <textarea name="hold_these_nonmembers" cols="40" rows="10">
</textarea>
  <input type="number" name="max_message_size" value="40">
  <input type="checkbox" name="emergency">
  <select name="filter_action">
    <option value="discard">Discard</option>
    <option value="reject" selected>Reject</option>
  </select>
  <input type="submit" name="save" value="Save">
</form>
</body></html>
HTML;

$fields = serialize_form($settingsHtml, ['default_member_action', 'default_nonmember_action', 'accept_these_nonmembers']);

// The decoy form is not the one we serialize.
assert(!array_key_exists('q', $fields));

// Every field of the real form round-trips, with Django's leading textarea newline dropped.
assert($fields['acceptable_aliases'] === "info@gloggi.ch\n^.*@alias\\.example$");
assert($fields['accept_these_nonmembers'] === 'webform@gloggi.ch');
assert($fields['hold_these_nonmembers'] === '');
assert($fields['max_message_size'] === '40');
assert($fields['csrfmiddlewaretoken'] === 'TOKEN123');

// Only the checked radio of each group survives, and a select reports its selected option.
assert($fields['require_explicit_destination'] === 'True');
assert($fields['administrivia'] === 'False');
assert($fields['default_member_action'] === 'hold');
assert($fields['filter_action'] === 'reject');

// An unchecked checkbox and the submit button are not submitted.
assert(!array_key_exists('emergency', $fields));
assert(!array_key_exists('save', $fields));

// Nothing outside our three fields is touched when we rewrite the form.
$before = $fields;
$fields['default_member_action'] = 'accept';
$fields['default_nonmember_action'] = 'accept';
$fields['accept_these_nonmembers'] = implode("\n", array_keys($target['senders']));
assert(array_keys(array_diff_assoc($fields, $before))
    === ['default_member_action', 'default_nonmember_action', 'accept_these_nonmembers']);

assert(lines_to_set("a@b.ch\n\n  c@d.ch  \n") === ['a@b.ch' => true, 'c@d.ch' => true]);

// Django expects repeated names for multi-value fields, not PHP's name[0]=.
assert(encode_form(['a' => 'x y', 'b' => ['1', '2']]) === 'a=x+y&b=1&b=2');

echo "";
