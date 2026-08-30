<?php

declare(strict_types=1);

return [
    'errors' => [
        'invalid_request' => 'This request could not be understood.',
    ],

    'not_found' => 'This deal was not found.',

    // OpenAPI §6.1/§6.2 — one message per detail code, so `details` says what
    // the closed set of HTTP codes cannot.
    'list_query' => [
        'not_a_positive_integer' => 'This value must be a whole number greater than zero.',
        'above_maximum' => 'This page size is larger than the maximum allowed.',
        'unknown_sort_field' => 'This list cannot be sorted by that field.',
        'repeated_sort_field' => 'A field can only be used once when sorting.',
        'unknown_filter' => 'This list does not offer that filter.',
        'not_a_code' => 'This filter value is not a valid code.',
        'not_a_string' => 'The search term must be text.',
    ],

    // Point 2.3 — `POST /deals` and `PATCH /deals/{id}`.
    'validation' => [
        'unknown_owner' => 'That owner is not a user of this system.',
        'status_is_derived' => 'A deal\'s status changes through the status action, and cannot be set here.',
        'approval_is_derived' => 'Approval status is set by the system from who creates the request, and cannot be edited here.',
        'code_is_generated' => 'The deal code is generated automatically and cannot be set here.',
        'customer_is_fixed_at_creation' => 'A deal\'s customer is set when it is created and cannot be changed here.',
        'assign_has_its_own_action' => 'Transferring a deal to another owner is a separate action, not a field on this form.',
    ],

    // Field names as a person reading a validation message would say them.
    'attributes' => [
        'customer_id' => 'customer',
        'title' => 'title',
        'source' => 'source',
        'service_type' => 'service type',
        'owner_id' => 'owner',
        'code' => 'code',
        'status' => 'status',
        'approval_status' => 'approval status',
        'rejection_reason' => 'rejection reason',
        'last_activity_at' => 'last activity',
    ],
];
