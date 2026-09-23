<?php

declare(strict_types=1);

return [
    // `OpenAPI §5.1` — one 404 for absent or out of reach (Point 3.5).
    'not_found' => 'This quotation was not found.',

    // `422 business_rule_blocked` — §5.6's block, and its neighbour `D-09`
    // forces. Keyed by `QuotationNotPriceable::$reason`; the owner confirmed
    // `fx_rate_missing` as a distinct code on 2026-09-11.
    // `OpenAPI §6.1`/`§6.2` — `GET /quotations` (Point 5.2). The envelope's
    // Point 5.5 — `group_by=employee`'s `null` group: a deal nobody owns.
    'groups' => [
        'unassigned' => 'Unassigned',
    ],

    // message is `errors.invalid_request`; the cause is in `details`, below.
    'list_query' => [
        'not_a_positive_integer' => 'This value must be a whole number greater than zero.',
        'above_maximum' => 'This page size is larger than the maximum allowed.',
        'unknown_parameter' => 'This list does not offer that parameter.',
        'unknown_filter' => 'This list does not offer that filter.',
        'unknown_status' => 'This is not a quotation status.',
        'unknown_bucket' => 'The bucket must be active or history.',
        'not_a_uuid' => 'This filter value is not an id.',
        'not_a_code' => 'This filter value is not a currency code.',
        'unknown_field' => 'Suggestions exist for payment_terms, warranty and delivery_terms only.',
        'not_an_amount' => 'This amount must be a non-negative number.',
        'currency_required' => 'Amount filters and the total sort need filter[currency].',
        'not_a_date' => 'This date must be a calendar date written as YYYY-MM-DD.',
        'after_to' => 'The start of the date range is after its end.',
        'unknown_sort_field' => 'This list cannot be sorted by that field.',
        'repeated_sort_field' => 'A field can only be used once when sorting.',
        'unknown_group' => 'This list can only be grouped by employee or customer.',
    ],

    'errors' => [
        'invalid_request' => 'This request could not be understood.',
        'supplier_price_missing' => 'This supplier line has no usable price — the line is gone, or its supplier quotation has no currency — so the quotation cannot be saved.',
        'fx_rate_missing' => 'No exchange rate is recorded to convert this supplier line. Record the rate first.',
        // `PATCH /quotations/{id}` — `QuotationWriteRefused` (Point 3.6).
        'if_match_required' => 'Send the quotation\'s current etag in If-Match.',
        'stale_version' => 'This quotation was changed by someone else. Reload it and apply your edit again.',
        'quotation_not_draft' => 'Only a draft quotation can be edited here.',
        // `QuotationStatusTransition` (Point 4.1) — §6.4's arrows, `OpenAPI §5.1` 409.
        'invalid_transition' => 'This quotation cannot move to that status from where it is now.',
        'version_exists' => 'A newer version of this quotation already exists. Continue on that one.',
        // `D-90` rule a (Module 10 · 1.3) — `OpenAPI §5.1` 422 `business_rule_blocked`.
        'deal_not_ready_to_send' => 'This quotation\'s deal has not reached the supplier quotation stage yet, so the quotation cannot be sent.',
    ],

    // §5.6's warning — carried in `meta.warnings` on a successful create.
    'warnings' => [
        'quantity_exceeds_recorded' => 'The requested quantity exceeds what is left of the supplier\'s offer.',
        'supplier_price_changed' => 'Supplier price has changed — review pricing.',
    ],

    // `422 validation_failed` raised by the use case, not by a rule.
    'validation' => [
        'unknown_deal' => 'This deal was not found.',
        'customer_not_the_deals' => 'The customer must be the deal\'s customer.',
        'note_not_blank' => 'A return note cannot be only spaces.',
    ],
];
