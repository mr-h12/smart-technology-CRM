<?php

declare(strict_types=1);

/*
| Module 2's user-facing messages. §14.2 requires Arabic and English from the
| first release, and `lang/ar/admin.php` carries the identical key set —
| `LocaleTest` compares the two in both directions, because the defect it was
| written for was a file present in one language and absent in the other.
|
| Machine codes are not translated: `OpenAPI §5.1`'s `error.code` and the
| `details[].code` beside it are contract values a client matches on. Only the
| `message` a person reads lives here.
*/

return [
    'errors' => [
        // OpenAPI §6.1/§6.2 — a 400, which means the client built a URL this
        // API does not offer, not that somebody typed something wrong.
        'invalid_request' => 'The request could not be understood.',
    ],

    'list_query' => [
        'not_a_positive_integer' => 'This value must be a whole number greater than zero.',
        'above_maximum' => 'This value is above the maximum this list allows.',
        'unknown_filter' => 'This list does not offer that filter.',
        'unknown_sort_field' => 'This list does not offer that sort field.',
    ],

    'fx_rate' => [
        'unknown_currency' => 'This system does not offer that currency.',
        'same_currency' => 'An exchange rate needs two different currencies.',
        'already_recorded' => 'This pair already has a rate effective at that moment.',
    ],
];
