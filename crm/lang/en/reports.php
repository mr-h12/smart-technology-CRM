<?php

declare(strict_types=1);

// Module 13 — the server's report messages (`OpenAPI §5.1`). The screens'
// labels live in `resources/js/locales/*.json` under `reports`.
return [
    'not_found' => 'This report does not exist or is not yours.',

    'attributes' => [
        'type' => 'report type',
        'notes' => 'notes',
        'reason' => 'reason',
        'recipient_ids' => 'recipients',
    ],

    'errors' => [
        'if_match_required' => 'Send the report\'s current version in If-Match.',
        'stale_version' => 'This report was changed since you opened it. Reload it and try again.',
        'report_not_draft' => 'Only a draft or returned report can be changed.',
        'recipient_not_allowed' => 'One of the recipients cannot receive this report.',
        'report_not_awaiting_answer' => 'Only a submitted or viewed report can be acknowledged or returned.',
    ],
];
