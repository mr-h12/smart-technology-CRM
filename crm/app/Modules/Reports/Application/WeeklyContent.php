<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application;

use App\Modules\Admin\Application\Settings\CompanyToday;
use App\Modules\Customers\Domain\Contracts\NewCustomersInterface;
use App\Modules\Quotations\Domain\Contracts\PurchaseOrderCountsInterface;
use App\Modules\Reports\Domain\Contracts\ReportDirectoryInterface;
use App\Modules\Reports\Domain\Report;

/**
 * `D-126` / `D-134` RPT-WK: what a weekly report holds beyond the week's
 * activity (`DailyActivity` over the same days): the author's own daily
 * reports of the week, the count of POs received on the author's deals by PO
 * date, and the customers the author added. Live for a draft, frozen at submit.
 */
final readonly class WeeklyContent
{
    public function __construct(
        private ReportDirectoryInterface $reports,
        private PurchaseOrderCountsInterface $orders,
        private NewCustomersInterface $customers,
        private CompanyToday $today,
    ) {}

    /** @return array{daily_reports: list<array{code: string, period_start: string, status: string}>, purchase_orders_received: int, new_customers: array{count: int, items: list<array{customer_id: string, name: string}>}} */
    public function of(Report $report): array
    {
        $items = $this->customers->createdBetween($report->authorId, ...$report->utcPeriod($this->today->zone()));

        return [
            'daily_reports' => $this->reports->dailyReportsOf($report->authorId, $report->periodStart, $report->periodEnd),
            'purchase_orders_received' => $this->orders->countOnDealsOwnedBy($report->authorId, $report->periodStart, $report->periodEnd),
            'new_customers' => ['count' => count($items), 'items' => $items],
        ];
    }
}
