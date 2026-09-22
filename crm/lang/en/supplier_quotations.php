<?php

declare(strict_types=1);

return [
    'not_found' => 'This supplier quotation was not found.',

    // OpenAPI §5's envelope message for a 400. Every module carries its own
    // `errors.invalid_request`; the specific cause is in `details`, below.
    'errors' => [
        'invalid_request' => 'This request could not be understood.',
    ],

    // OpenAPI §6.1/§6.2 — one message per detail code, so `details` says what
    // the closed set of HTTP codes cannot.
    'list_query' => [
        'not_a_positive_integer' => 'This value must be a whole number greater than zero.',
        'above_maximum' => 'This page size is larger than the maximum allowed.',
        'unknown_sort_field' => 'This list cannot be sorted by that field.',
        'repeated_sort_field' => 'A field can only be used once when sorting.',
        'unknown_filter' => 'This list does not offer that filter.',
        'not_a_uuid' => 'This filter value is not a valid identifier.',
        'not_a_string' => 'This filter value must be text.',
    ],
];
