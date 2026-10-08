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

    // D-100: the heading over the company's name, and the parties block.
    'heading' => 'Quotation',

    'header' => [
        'attention' => 'Att',
        'subject' => 'Subject',
    ],

    'parties' => [
        'prepared_for' => 'Prepared for',
        'issue_date' => 'Issue date',
        'valid_until' => 'Valid until',
    ],

    'intro' => 'We have the pleasure to provide you with the following offer:',

    'table' => [
        'serial' => '#',
        'item' => 'Description',
        'unit_price' => 'Unit Price',
        'quantity' => 'Qty',
        'line_total' => 'Total',
    ],

    'terms' => [
        'heading' => 'Terms and Conditions',
        // D-103: printed for a ready term the employee left unnamed.
        'labels' => [
            'payment_terms' => 'Payment',
            'warranty' => 'Warranty',
            'delivery_terms' => 'Delivery',
        ],
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
        'term' => ':name: :body',
        'validity' => 'Offer validity: valid until :date.',
    ],

    'footer' => [
        'page' => 'Page :current of :total',
    ],

    'closing' => 'Please do not hesitate to contact us in case you have any questions.',
    'regards' => 'Best Regards,',

    'attributes' => [
        'locale' => 'document language',
    ],

    'errors' => [
        'incomplete' => [
            'company_name' => 'The customer PDF cannot be made yet: the company name is not set in the system settings.',
            'customer_name' => 'The customer PDF cannot be made yet: the quotation\'s customer has no name to print.',
            'line_descriptions' => 'The customer PDF cannot be made yet: line(s) :lines have no description to print.',
        ],
    ],
];
