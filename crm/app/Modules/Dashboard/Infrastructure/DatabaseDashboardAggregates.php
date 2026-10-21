<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Infrastructure;

use App\Modules\Dashboard\Domain\Contracts\DashboardAggregatesInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;

final readonly class DatabaseDashboardAggregates implements DashboardAggregatesInterface
{
    public function __construct(private ConnectionInterface $connection) {}

    public function lastStartedAt(): ?\DateTimeImmutable
    {
        $started = $this->connection->table('dashboard_refreshes')->orderByDesc('started_at')->value('started_at');

        return is_string($started) ? new \DateTimeImmutable($started) : null;
    }

    public function replaceDeals(?array $days, array $rows): void
    {
        $this->replace('dashboard_daily_deals', $days, $rows);
    }

    public function replaceCustomers(?array $days, array $rows): void
    {
        $this->replace('dashboard_daily_customers', $days, $rows);
    }

    public function replaceSelfApprovals(?array $days, array $rows): void
    {
        $this->replace('dashboard_daily_self_approvals', $days, $rows);
    }

    public function replaceLiveCounts(array $rows): void
    {
        $this->replace('dashboard_live_counts', null, $rows);
    }

    public function recordRefresh(string $kind, \DateTimeImmutable $startedAt, \DateTimeImmutable $finishedAt): void
    {
        $this->connection->table('dashboard_refreshes')->insert([
            'id' => (string) Str::uuid7(),
            'kind' => $kind,
            'started_at' => $startedAt->format(DATE_ATOM),
            'finished_at' => $finishedAt->format(DATE_ATOM),
        ]);
    }

    public function lastFinishedAt(): ?string
    {
        $finished = $this->connection->table('dashboard_refreshes')->max('finished_at');

        return is_string($finished) ? $finished : null;
    }

    public function overviewBetween(string $from, string $to, ?array $ownerIds): array
    {
        /** @var array<string, int|string> $byStatus */
        $byStatus = $this->scoped($this->connection->table('dashboard_daily_deals')->whereBetween('day', [$from, $to]), 'owner_id', $ownerIds)
            ->groupBy('status')->selectRaw('status, SUM(deals) AS n')->pluck('n', 'status')->all();

        $live = $this->scoped($this->connection->table('dashboard_live_counts'), 'owner_id', $ownerIds)
            ->selectRaw('COALESCE(SUM(pending_approvals), 0) AS pending, COALESCE(SUM(returned), 0) AS returned')->first();

        return [
            'customers' => (int) $this->scoped($this->connection->table('dashboard_daily_customers')->whereBetween('day', [$from, $to]), 'owner_id', $ownerIds)->sum('customers'),
            'deals_by_status' => array_map(static fn (int|string $n): int => (int) $n, $byStatus),
            'self_approvals' => (int) $this->scoped($this->connection->table('dashboard_daily_self_approvals')->whereBetween('day', [$from, $to]), 'approver_id', $ownerIds)->sum('approvals'),
            'pending_approvals' => is_object($live) && is_numeric($live->pending) ? (int) $live->pending : 0,
            'returned' => is_object($live) && is_numeric($live->returned) ? (int) $live->returned : 0,
        ];
    }

    /**
     * Null keeps every row (All, unowned included); a list keeps only those owners —
     * an unowned row never matches one (`D-138`).
     *
     * @param  list<string>|null  $ownerIds
     */
    private function scoped(Builder $query, string $column, ?array $ownerIds): Builder
    {
        return $ownerIds === null ? $query : $query->whereIn($column, $ownerIds);
    }

    /**
     * @param  list<string>|null  $days
     * @param  list<array<string, mixed>>  $rows
     */
    private function replace(string $table, ?array $days, array $rows): void
    {
        $delete = $this->connection->table($table);
        if ($days !== null) {
            if ($days === [] && $rows === []) {
                return;
            }
            $delete->whereIn('day', $days);
        }
        $delete->delete();

        // ponytail: one INSERT per 500 rows; a company's days × owners × statuses stays far below the parameter limit per chunk.
        foreach (array_chunk($rows, 500) as $chunk) {
            $this->connection->table($table)->insert($chunk);
        }
    }
}
