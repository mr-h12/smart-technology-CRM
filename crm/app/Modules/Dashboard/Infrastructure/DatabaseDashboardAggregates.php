<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Infrastructure;

use App\Modules\Dashboard\Domain\Contracts\DashboardAggregatesInterface;
use Illuminate\Database\ConnectionInterface;
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
