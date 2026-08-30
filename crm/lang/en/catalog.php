<?php

declare(strict_types=1);

return [
    'errors' => [
        'invalid_request' => 'This request could not be understood.',
    ],

    'not_found' => 'This catalog item was not found.',

    // OpenAPI §6.1/§6.2 — one message per detail code, so `details` says what
    // the closed set of HTTP codes cannot.
    'list_query' => [
        'not_a_positive_integer' => 'This value must be a whole number greater than zero.',
        'above_maximum' => 'This page size is larger than the maximum allowed.',
        'unknown_sort_field' => 'This list cannot be sorted by that field.',
        'repeated_sort_field' => 'A field can only be used once when sorting.',
        'unknown_filter' => 'This list does not offer that filter.',
        'unknown_group' => 'This list cannot be grouped by that field.',
        'not_a_boolean' => 'This filter accepts only true or false.',
        'not_a_code' => 'This filter value is not a valid code.',
        'not_a_string' => 'This value must be text.',
    ],
];
