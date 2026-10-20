<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application;

use App\Modules\Admin\Application\Settings\CompanyToday;
use App\Modules\Deals\Domain\Contracts\DealFactsInterface;
use App\Modules\Identity\Application\Rbac\AuthorizeAction;
use App\Modules\Quotations\Domain\Contracts\QuotationReaderInterface;
use App\Modules\Reports\Domain\Report;

/**
 * `D-140` RPT-MN: company-wide counts over the month's company-time days — the
 * deals created in it by their status now, and the quotation versions sent in it
 * (`sent_at`): won = `accepted` + `partial`, lost = `rejected` + `expired`, by
 * their status now — and `D-141`'s money per currency, its cost and profit only
 * while the author holds `quotation.view_cost_and_margin` (`D-126`). Live for a
 * draft, frozen at submit.
 */
final readonly class MonthlyContent
{
    public function __construct(
        private DealFactsInterface $deals,
        private QuotationReaderInterface $quotations,
        private CompanyToday $today,
        private AuthorizeAction $authorize,
    ) {}

    /**
     * `deals_by_status` is a list in §4.4's pipeline order, so the frozen copy keeps it: `jsonb` reorders an object's keys (`D-140`, the owner, 2026-10-20).
     *
     * @return array{deals_by_status: list<array{status: string, count: int}>, quotations: array{sent: int, won: int, lost: int}, money: list<array{currency: string, won_value: string, cost?: string, profit?: string}>}
     */
    public function of(Report $report): array
    {
        $period = $report->utcPeriod($this->today->zone());
        $deals = $this->deals->statusCountsCreatedBetween(...$period);
        $sent = $this->quotations->statusCountsSentBetween(...$period);
        $money = $this->quotations->wonMoneySentBetween(...$period);

        if (! $this->authorize->decide($report->authorId, 'quotation', 'view_cost_and_margin')->granted) {
            $money = array_map(static fn (array $row): array => ['currency' => $row['currency'], 'won_value' => $row['won_value']], $money);
        }

        return [
            'deals_by_status' => array_map(static fn (string $status, int $count): array => ['status' => $status, 'count' => $count], array_keys($deals), $deals),
            'quotations' => [
                'sent' => array_sum($sent),
                'won' => array_sum(array_intersect_key($sent, array_flip(QuotationReaderInterface::WON))),
                'lost' => ($sent['rejected'] ?? 0) + ($sent['expired'] ?? 0),
            ],
            'money' => $money,
        ];
    }
}
