<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Application;

use App\Modules\Admin\Application\Settings\CompanyToday;
use App\Modules\Audit\Domain\Contracts\AuditEntryReaderInterface;
use App\Modules\Customers\Domain\Contracts\NewCustomersInterface;
use App\Modules\Dashboard\Domain\Contracts\DashboardAggregatesInterface;
use App\Modules\Deals\Domain\Contracts\DealFactsInterface;
use App\Modules\Quotations\Domain\Contracts\QuotationReaderInterface;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * J-09 `refresh_dashboard_aggregates` (§15, `D-144`, `D-145`, `D-146`).
 *
 * Nightly it rebuilds every day. Hourly it rebuilds only the days whose records
 * changed since the last run began — a September deal that moves on today
 * rebuilds September's day — and the first hourly run ever rebuilds everything.
 * The "now" counts are rebuilt on every run. Nothing before the go-live date is
 * kept (`D-145`). One transaction, so a second run of the same moment changes
 * nothing. No catch-up: the next run recomputes (§15).
 */
final readonly class RefreshDashboardAggregates
{
    private const SELF_APPROVAL = 'SELF_APPROVAL';

    public function __construct(
        private DealFactsInterface $deals,
        private NewCustomersInterface $customers,
        private QuotationReaderInterface $quotations,
        private AuditEntryReaderInterface $audit,
        private DashboardAggregatesInterface $aggregates,
        private CompanyToday $today,
        private ConnectionInterface $connection,
    ) {}

    /** @return int|null how many days were rebuilt; null when every day was */
    public function run(bool $full): ?int
    {
        $startedAt = CarbonImmutable::now();
        $zone = $this->today->zone();
        $since = $full ? null : $this->aggregates->lastStartedAt();
        $days = $since === null ? null : $this->touchedDays($since, $zone);
        $goLive = $this->today->goLiveDate();
        $live = static fn (array $row): bool => $goLive === null || $row['day'] >= $goLive;

        $this->connection->transaction(function () use ($days, $zone, $live, $full, $startedAt): void {
            $this->aggregates->replaceDeals($days, array_values(array_filter($this->deals->createdCountsByDay($zone, $days), $live)));
            $this->aggregates->replaceCustomers($days, array_values(array_filter($this->customers->createdCountsByDay($zone, $days), $live)));
            $this->aggregates->replaceSelfApprovals($days, array_values(array_map(
                static fn (array $row): array => ['day' => $row['day'], 'approver_id' => $row['actor_id'], 'approvals' => $row['entries']],
                array_filter($this->audit->countsByDay(self::SELF_APPROVAL, $zone, $days), $live),
            )));
            $this->aggregates->replaceLiveCounts($this->quotations->liveApprovalCountsByOwner());
            $this->aggregates->recordRefresh($full ? 'nightly' : 'hourly', $startedAt, CarbonImmutable::now());
        });

        return $days === null ? null : count($days);
    }

    /** @return list<string> */
    private function touchedDays(\DateTimeImmutable $since, string $zone): array
    {
        $days = array_unique([
            ...$this->deals->creationDaysTouchedSince($since, $zone),
            ...$this->customers->creationDaysTouchedSince($since, $zone),
            ...$this->audit->daysWithEntriesSince(self::SELF_APPROVAL, $since, $zone),
        ]);
        sort($days);

        return $days;
    }
}
