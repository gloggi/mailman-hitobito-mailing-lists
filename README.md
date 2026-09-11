# hitobito → Hostpoint Mailman sync

A cronjob that makes each GNU Mailman 3 list on Hostpoint match the corresponding
hitobito mailing list: same members, same sender policy.

Mailman Core's REST API is not reachable on Hostpoint. So this drives the Postorius
web forms (`csv_view`, `mass_subscribe`, `mass_removal`,
`settings/message_acceptance`) with a logged-in session. Plain PHP, no composer.

## Setup

1. **Create the list** in the Hostpoint Control Panel under *E-Mail →
   Mailinglisten*. Lists cannot be created through this tool: Postorius reserves
   list creation for superusers. Note the administrator address and password you
   set — that account is the list's owner and is what the sync logs in as. Use the
   same administrator for every list you want synced.
2. **Mark the hitobito list** by putting the Mailman address in its **Herausgeber**
   (`publisher`) field, e.g. `leitung@mydomain.ch`. That is the entire opt-in: no
   publisher address, no sync. Several targets may be named, separated by commas
   and/or whitespace — `list1@gryfensee.ch, list2@gryfensee.ch` syncs the same
   people into both lists. A publisher pointing at a list that doesn't exist in
   Postorius is reported and skipped, and one holding ordinary text (its documented
   use) opts the list out entirely.
3. **Create a service token** in hitobito with the *Mailinglisten* permission, on
   the layer where the mailing list lives.
4. `cp config.example.php config.php`, fill it in, `chmod 600 config.php`. Put the
   directory outside the web root (e.g. `~/private/mailman-sync/`).
5. `php sync.php --dry-run -v` and read the output before the first real run.
6. Add the cronjob in the Control Panel under *Erweitert → Cronjobs*, hourly:
   `0 * * * *` -> `php /home/<user>/mailman-hitobito-mailing-lists/sync.php`
   Hostpoint mails you anything the job prints, and the job prints nothing unless
   something went wrong.

## What it syncs

| hitobito | Mailman |
|---|---|
| subscribers' `list_emails` | list members — **full sync**, addresses not in hitobito are unsubscribed |
| *Abonnenten dürfen auf die Liste schreiben* | `default_member_action` = `accept`, otherwise `hold` |
| *Alle dürfen auf die Liste schreiben* | `default_nonmember_action` = `accept`, otherwise `hold` |
| *Zusätzliche Absender* + `extra_senders` | `accept_these_nonmembers` |

Every address hitobito returns for a person is subscribed, main and label-matched
extras alike. `*@domain` entries become `^.*@domain$` regexes.

The sync **owns** those three sender-policy fields — edit them in Postorius and the
next run puts them back. Everything else on that settings page is echoed back
untouched. List owners and moderators are a separate Mailman role and are never
touched by member operations.

**What can't be synced:** hitobito also lets anyone who may edit the subscription
post to the list. That's a role check with no API representation, so it does not
transfer. People who relied on it need to be subscribers of a list with
*Abonnenten dürfen schreiben*, or be named under *Zusätzliche Absender*.

## Flags

- `--dry-run` — report the diff, write nothing.
- `-v` — print every list. Without it a successful run prints nothing, so cron only
  mails you when something is wrong.
- `--force` — allow emptying a list. Without it, a hitobito list that reports zero
  subscribers against a non-empty Mailman list is skipped and flagged, so an API
  hiccup can't wipe a live list.

Exit code is non-zero if anything failed. A list that exists in hitobito but not in
Postorius is reported without failing the run — that's expected until you create it.

## Tests

```
php -d zend.assertions=1 test.php
```

Silence means pass. The `-d` is required: Hostpoint's php.ini sets
`zend.assertions=-1`, which compiles `assert()` out, and the suite refuses to run
rather than report a fake pass.

The settings round-trip fixture in `test.php` is modelled on Django's rendering, not
copied from a live page. After the first run that writes sender policy, open the
list's *Message acceptance* page once and confirm nothing but the three fields
changed.
