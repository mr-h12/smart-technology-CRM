<?php

declare(strict_types=1);

return [
    'errors' => [
        'invalid_request' => 'This request could not be understood.',
    ],

    'not_found' => 'This supplier was not found.',

    // D-85's import file (F-09 · 1.4) — the messages `App\Support\Csv\CsvReader`
    // raises, in the suppliers' words.
    'import' => [
        'empty_file' => 'This file is empty. The first row must name the columns.',
        'unknown_columns' => 'This file has columns the importer does not accept: :columns.',
        'duplicate_columns' => 'This file names the same field in more than one column: :columns.',
        'missing_name_column' => 'This file has no "name" column, and a supplier cannot be imported without one.',
    ],

    // OpenAPI §6.1/§6.2 — one message per detail code, so `details` says what
    // the closed set of HTTP codes cannot.
    'list_query' => [
        'not_a_positive_integer' => 'This value must be a whole number greater than zero.',
        'above_maximum' => 'This page size is larger than the maximum allowed.',
        'unknown_sort_field' => 'This list cannot be sorted by that field.',
        'repeated_sort_field' => 'A field can only be used once when sorting.',
        'unknown_filter' => 'This list does not offer that filter.',
        'not_a_boolean' => 'This filter accepts only true or false.',
        'not_a_code' => 'This filter value is not a valid code.',
        'not_a_string' => 'The search term must be text.',
    ],
];
