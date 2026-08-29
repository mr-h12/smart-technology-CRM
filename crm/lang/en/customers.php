<?php

declare(strict_types=1);

return [
    'errors' => [
        'invalid_request' => 'This request could not be understood.',
    ],

    'not_found' => 'This customer was not found.',

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

    // Point 3.3 — `POST /customers` and `PATCH /customers/{id}`.
    'validation' => [
        'name_not_blank' => 'A customer name cannot be only spaces.',
        'unknown_owner' => 'That sales owner is not a user of this system.',
        'status_is_derived' => 'Customer status is set by the system from the customer\'s deals, and cannot be edited here.',
        'archive_has_its_own_action' => 'Archiving a customer is a separate action, not a field on this form.',
        'assign_has_its_own_action' => 'Transferring a customer to another owner is a separate action, not a field on this form.',
    ],

    // Field names as a person reading a validation message would say them.
    'attributes' => [
        'name' => 'customer name',
        'sector' => 'sector',
        'region' => 'region',
        'contact_person' => 'contact person',
        'phone' => 'phone',
        'phone2' => 'second phone',
        'whatsapp' => 'WhatsApp number',
        'email' => 'email',
        'start_date' => 'first engagement date',
        'notes' => 'notes',
        'sales_owner_id' => 'sales owner',
        'customer_status' => 'customer status',
        'is_archived' => 'archived',
        'is_incomplete' => 'incomplete',
    ],
];
