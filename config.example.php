<?php

// Copy to config.php on the server and chmod 600 — it holds two secrets.

return [
    // Integration: https://pbs.puzzle.ch   Production: https://db.scout.ch
    'hitobito_url' => 'https://db.scout.ch',
    // Service token with the "mailing lists" permission on the mailing list's layer.
    'hitobito_token' => '',
    // Only mailing lists belonging to these groups are considered.
    'group_ids' => [1234],

    'postorius_url' => 'https://lists.hostpoint.ch',
    // The list administrator address and password you entered in the Hostpoint
    // Control Panel when creating the lists. It must be owner of every list synced.
    'postorius_user' => '',
    'postorius_password' => '',

    // Optional. Senders allowed to post to *every* synced list, on top of each
    // hitobito list's own "Zusätzliche Absender". `*@domain` becomes a regex.
    'extra_senders' => [],
];
