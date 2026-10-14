<?php

declare(strict_types=1);

/*
 * Module 9 — every word the customer's PDF says (§14.6, `D-89`).
 *
 * No label carries a percentage (`D-107`, `D-118`): `discount_percent` and
 * `tax_percent` are per-quotation fields, and a label reading "Discount 5%"
 * is wrong on the first quotation that discounts anything else.
 */
return [
    'title' => 'Quotation :code',

    // D-107: the centred title, and the info block under it.
    'heading' => 'COMMERCIAL OFFER',

    'info' => [
        'offer_no' => 'Offer No.',
        'issue_date' => 'Issue Date:',
        'prepared_for' => 'Prepared For:',
        'attention' => 'Att.:',
        // D-104: the stored English title before the name.
        'attention_name' => ':title :name',
        'subject' => 'Subject:',
    ],

    'intro' => 'We have the pleasure to provide you with the following offer:',

    'table' => [
        'description' => 'Description',
        'unit' => 'Unit',
        'quantity' => 'Qty',
        'unit_price' => 'Unit Price',
        'vat' => 'VAT',
        'total' => 'Total',
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
        'excl_vat' => 'Total Amount Excl. VAT',
        'discount' => 'Discount',
        'tax' => 'VAT',
        'rounding' => 'Rounding',
        'final' => 'Total',
    ],

    'conditions' => [
        'currency' => 'Offer currency: :currency.',
        'term' => ':name: :body',
        'validity' => 'Offer validity: valid until :date.',
        // D-107: the last term, on a taxed quotation only.
        'vat_included' => 'The price includes VAT.',
    ],

    'footer' => [
        'page' => 'Page :current of :total',
        // D-107: the company block, always in English.
        'address' => 'Address:',
        'tel' => 'Tel:',
        'email' => 'Email:',
    ],

    'closing' => 'Please do not hesitate to contact us in case you have any questions.',
    'regards' => 'Best Regards,',
    'signature' => 'Sales Signature',

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
