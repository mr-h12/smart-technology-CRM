<?php

declare(strict_types=1);

namespace Tests\Feature\Localisation;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `D-132`: the report PDF prints a deal status and a customer response in Arabic, from `lang/ar/pdf.php`.
 * The words are the SPA's (`deals.status.*`, `reports.response.*`); this guard stops the two copies drifting.
 */
final class ReportPdfLabelsTest extends TestCase
{
    private const STATUSES = [
        'lead', 'contacted', 'waiting_customer_request', 'supplier_rfq', 'supplier_quotation', 'quotation_sent',
        'negotiations', 'won', 'purchasing', 'delivery', 'delivery_complete', 'lost',
    ];

    /** @return array<string, array{0: string}> */
    public static function locales(): array
    {
        return ['ar' => ['ar'], 'en' => ['en']];
    }

    #[DataProvider('locales')]
    public function test_that_the_pdf_labels_equal_the_spa_labels(string $locale): void
    {
        /** @var array{deals: array{status: array<string, string>}, reports: array{response: array<string, string>}} $spa */
        $spa = json_decode((string) file_get_contents(resource_path("js/locales/{$locale}.json")), true, 512, JSON_THROW_ON_ERROR);
        /** @var array{report: array{deal_status: array<string, string>, response: array<string, string>}} $pdf */
        $pdf = require lang_path("{$locale}/pdf.php");

        $statuses = array_intersect_key($spa['deals']['status'], array_flip(self::STATUSES));
        self::assertCount(count(self::STATUSES), $statuses, 'The SPA lost a deal status.');
        self::assertSame($statuses, $pdf['report']['deal_status']);
        self::assertSame($spa['reports']['response'], $pdf['report']['response']);
    }
}
