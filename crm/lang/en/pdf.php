<?php

declare(strict_types=1);

/*
 * Module 9 — every word the customer's PDF says (§14.6, `D-89`).
 *
 * The percentages are placeholders, never part of the label: `discount_percent`
 * and `tax_percent` are per-quotation fields, and a label reading "Discount 5%"
 * is wrong on the first quotation that discounts anything else.
 */
return [
    'title' => 'Quotation :code',

    'header' => [
        'date' => 'Date',
        'to' => 'To',
        'attention' => 'Att',
        'subject' => 'Subject',
    ],

    'intro' => 'We have the pleasure to provide you with the following offer:',

    'table' => [
        'serial' => 'S',
        'item' => 'Item',
        'unit_price' => 'Unit Price',
        'quantity' => 'Qty.',
        'line_total' => 'Total price',
    ],

    'totals' => [
        'subtotal' => 'Subtotal',
        'additional' => 'Delivery & Installation',
        'discount' => 'Discount (:percent%)',
        'tax_base' => 'Amount before tax',
        'tax' => 'VAT (:percent%)',
        'net' => 'Net amount',
        'rounding' => 'Rounding',
        'final' => 'Total',
    ],

    'conditions' => [
        'currency' => 'Offer currency: :currency.',
        'payment' => 'Payment: :terms',
        'warranty' => 'Warranty: :terms',
        'delivery' => 'Delivery: :terms',
        'validity' => 'Offer validity: valid until :date.',
    ],

    'footer' => [
        'page' => 'Page :current of :total',
    ],

    'closing' => 'Please do not hesitate to contact us in case you have any questions.',
    'regards' => 'Best Regards,',
];
