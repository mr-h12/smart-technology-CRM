<?php

declare(strict_types=1);

return [
    // `422 business_rule_blocked` — §5.6's block, and its neighbour `D-09`
    // forces. Keyed by `QuotationNotPriceable::$reason`; the owner confirmed
    // `fx_rate_missing` as a distinct code on 2026-09-11.
    'errors' => [
        'supplier_price_missing' => 'This supplier line has no usable price, so the quotation cannot be saved.',
        'fx_rate_missing' => 'No exchange rate is recorded to convert this supplier line. Record the rate first.',
    ],

    // §5.6's warning — carried in `meta.warnings` on a successful create.
    'warnings' => [
        'quantity_exceeds_recorded' => 'The requested quantity exceeds what the supplier recorded.',
    ],

    // `422 validation_failed` raised by the use case, not by a rule.
    'validation' => [
        'unknown_deal' => 'This deal was not found.',
        'customer_not_the_deals' => 'The customer must be the deal\'s customer.',
    ],
];
