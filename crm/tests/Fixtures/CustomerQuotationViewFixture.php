<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Modules\Pdf\Domain\View\CustomerAdditionalLine;
use App\Modules\Pdf\Domain\View\CustomerQuotationLine;
use App\Modules\Pdf\Domain\View\CustomerQuotationView;

/**
 * One customer view, built once — Module 9, Point 2.4.
 *
 * Two suites need the same quotation: the HTML tests, which run anywhere, and
 * the real render, which runs only inside the `pdf` image. A fixture rather
 * than a copy in each, so the document they check is the same document.
 */
final class CustomerQuotationViewFixture
{
    /**
     * @param  array<string, mixed>  $overrides  any constructor argument, by name
     */
    public static function make(array $overrides = []): CustomerQuotationView
    {
        $defaults = [
            'code' => 'QT-2026-0001',
            'quotationDate' => '2026-07-14',
            'validUntil' => '2026-08-13',
            'currencyCode' => 'EGP',
            'customerName' => 'Vegatrone',
            'customerContact' => 'Mr. Medhat',
            'companyName' => 'Smart Technology for Integrated Systems',
            'companyAddress' => '5 El-Fath St, Wezarra Station, Boulkly, Alexandria, Egypt',
            'companyPhones' => '035829952 · 01070764779',
            'subject' => 'IT Offer',
            'signatoryName' => 'Ahmed Essam',
            'lines' => [
                new CustomerQuotationLine(1, 'Formatter M428dw', '2', '5219.30', '10438.60'),
                new CustomerQuotationLine(2, 'Toner CF259A', '3', '6332.50', '18997.50'),
                new CustomerQuotationLine(3, 'Fuser RM2-5399', '1', '5418.00', '5418.00'),
            ],
            'additionalItems' => [new CustomerAdditionalLine(1, 'Delivery & Installation', '250.00')],
            'subtotal' => '34854.10',
            'additionalTotal' => '250.00',
            'discountPercent' => '5',
            'discountAmount' => '1742.71',
            'taxBase' => '33111.39',
            'taxPercent' => '14',
            'taxAmount' => '4635.59',
            'netAmount' => '37746.98',
            'finalTotal' => '37996.98',
            'roundingDiff' => '0.00',
            'paymentTerms' => '50% advance, balance upon delivery.',
            'warranty' => 'One year.',
            'deliveryTerms' => 'Within two weeks.',
        ];

        /** @var array<string, mixed> $arguments */
        $arguments = array_merge($defaults, $overrides);

        return new CustomerQuotationView(...$arguments); // @phpstan-ignore-line argument.type
    }

    /**
     * An Arabic quotation — the same document, in the language §1 makes the
     * default, with the prose a customer would actually read.
     */
    public static function arabic(): CustomerQuotationView
    {
        return self::make([
            'customerName' => 'شركة فيجاترون',
            'customerContact' => 'الأستاذ مدحت',
            'subject' => 'عرض أجهزة وطابعات',
            'signatoryName' => 'أحمد عصام',
            'lines' => [
                new CustomerQuotationLine(1, 'وحدة تثبيت لطابعة HP LaserJet M428dw', '2', '5219.30', '10438.60'),
                new CustomerQuotationLine(2, 'خرطوشة حبر CF259A', '3', '6332.50', '18997.50'),
                new CustomerQuotationLine(3, 'وحدة صهر RM2-5399', '1', '5418.00', '5418.00'),
            ],
            'additionalItems' => [new CustomerAdditionalLine(1, 'التوصيل والتركيب', '250.00')],
            'paymentTerms' => '٥٠٪ مقدمًا والباقي عند التسليم.',
            'warranty' => 'سنة واحدة.',
            'deliveryTerms' => 'خلال أسبوعين من تاريخ الطلب.',
        ]);
    }
}
