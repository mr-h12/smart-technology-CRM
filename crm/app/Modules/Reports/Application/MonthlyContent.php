<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application;

use App\Modules\Admin\Application\Settings\CompanyToday;
use App\Modules\Deals\Domain\Contracts\DealFactsInterface;
use App\Modules\Quotations\Domain\Contracts\QuotationReaderInterface;
use App\Modules\Reports\Domain\Report;

/**
 * `D-140` RPT-MN: company-wide counts over the month's company-time days, no
 * money — the deals created in it by their status now, and the quotation
 * versions sent in it (`sent_at`): won = `accepted` + `partial`, lost =
 * `rejected` + `expired`, by their status now. Live for a draft, frozen at submit.
 */
final readonly class MonthlyContent
{
    public function __construct(
        private DealFactsInterface $deals,
        private QuotationReaderInterface $quotations,
        private CompanyToday $today,
    ) {}

    /** @return array{deals_by_status: array<string, int>, quotations: array{sent: int, won: int, lost: int}} */
    public function of(Report $report): array
    {
        $period = $report->utcPeriod($this->today->zone());
        $sent = $this->quotations->statusCountsSentBetween(...$period);

        return [
            'deals_by_status' => $this->deals->statusCountsCreatedBetween(...$period),
            'quotations' => [
                'sent' => array_sum($sent),
                'won' => ($sent['accepted'] ?? 0) + ($sent['partial'] ?? 0),
                'lost' => ($sent['rejected'] ?? 0) + ($sent['expired'] ?? 0),
            ],
        ];
    }
}
