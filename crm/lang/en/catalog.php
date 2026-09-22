<?php

declare(strict_types=1);

return [
    'errors' => [
        'invalid_request' => 'This request could not be understood.',
    ],

    'not_found' => 'This catalog item was not found.',

    // D-86 (F-10 · 1.7) — the suppliers linked by hand on the item.
    'validation' => [
        'unknown_supplier' => 'One of these suppliers is not in the system.',
    ],

    // D-86's import file (F-10 · 1.5) — the messages `App\Support\Csv\CsvReader`
    // raises, in the catalog's words.
    'import' => [
        'empty_file' => 'This file is empty. The first row must name the columns.',
        'unknown_columns' => 'This file has columns the importer does not accept: :columns.',
        'duplicate_columns' => 'This file names the same field in more than one column: :columns.',
        'missing_name_column' => 'This file has no "name" column. Every catalog file needs one, even when a service leaves it empty.',
    ],

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
