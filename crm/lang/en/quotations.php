<?php

declare(strict_types=1);

return [
    // `OpenAPI §5.1` — one 404 for absent or out of reach (Point 3.5).
    'not_found' => 'This quotation was not found.',

    // `422 business_rule_blocked` — §5.6's block, and its neighbour `D-09`
    // forces. Keyed by `QuotationNotPriceable::$reason`; the owner confirmed
    // `fx_rate_missing` as a distinct code on 2026-09-11.
    'errors' => [
        'supplier_price_missing' => 'This supplier line has no usable price, so the quotation cannot be saved.',
        'fx_rate_missing' => 'No exchange rate is recorded to convert this supplier line. Record the rate first.',
        // `PATCH /quotations/{id}` — `QuotationWriteRefused` (Point 3.6).
        'if_match_required' => 'Send the quotation\'s current etag in If-Match.',
        'stale_version' => 'This quotation was changed by someone else. Reload it and apply your edit again.',
        'quotation_not_draft' => 'Only a draft quotation can be edited here.',
        // `QuotationStatusTransition` (Point 4.1) — §6.4's arrows, `OpenAPI §5.1` 409.
        'invalid_transition' => 'This quotation cannot move to that status from where it is now.',
        'version_exists' => 'A newer version of this quotation already exists. Continue on that one.',
    ],

    // §5.6's warning — carried in `meta.warnings` on a successful create.
    'warnings' => [
        'quantity_exceeds_recorded' => 'The requested quantity exceeds what the supplier recorded.',
        'supplier_price_changed' => 'Supplier price has changed — review pricing.',
    ],

    // `422 validation_failed` raised by the use case, not by a rule.
    'validation' => [
        'unknown_deal' => 'This deal was not found.',
        'customer_not_the_deals' => 'The customer must be the deal\'s customer.',
    ],
];
