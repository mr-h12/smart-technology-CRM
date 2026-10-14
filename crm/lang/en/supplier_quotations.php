<?php

declare(strict_types=1);

return [
    'not_found' => 'This supplier quotation was not found.',

    // D-118: the two rate fields, named inside a validation message.
    'attributes' => [
        'included_tax_percents' => 'tax rates',
        'included_tax_percent_item' => 'tax rate',
    ],

    // OpenAPI §5's envelope message for a 400. Every module carries its own
    // `errors.invalid_request`; the specific cause is in `details`, below.
    'errors' => [
        'lines_need_currency' => 'An offer with priced lines must name their currency (and its total).',
        'not_decimal_text' => 'A tax-inclusive price must be a plain decimal number, such as 114 or 114.50.',
        'no_tax_rate' => 'Type at least one tax rate for prices that include tax.',
        'rates_need_flag' => 'Tax rates are typed only when the prices include tax.',
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
