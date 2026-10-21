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

    public function replaceSentVersions(array $rows): void
    {
        $this->replace('dashboard_sent_versions', null, $rows);
    }

    public function moneyBetween(string $from, string $to): array
    {
        // `D-141`: per chain, the latest version sent in the period; won when accepted or partial.
        $won = <<<'SQL'
            won AS (
                SELECT * FROM (
                    SELECT DISTINCT ON (chain_id) * FROM dashboard_sent_versions
                    WHERE sent_day BETWEEN ? AND ?
                    ORDER BY chain_id, version DESC, sent_day DESC
                ) latest
                WHERE status IN ('accepted', 'partial')
            )
            SQL;

        /** @var list<object{currency: string, won_value: string, cost: string, gross_profit: string, margin_percent: string, delivery_cost: string, won_chains: int}> $currencies */
        $currencies = $this->connection->select("WITH {$won}
            SELECT currency, SUM(net_amount) AS won_value, SUM(cost) AS cost,
                SUM(net_amount - additional_total - cost) AS gross_profit,
                CASE WHEN SUM(net_amount) = 0 THEN 0.00 ELSE ROUND(SUM(net_amount - additional_total - cost) / SUM(net_amount) * 100, 2) END AS margin_percent,
                SUM(additional_total) AS delivery_cost, COUNT(*) AS won_chains
            FROM won GROUP BY currency ORDER BY currency", [$from, $to]);

        /** @var object{won_value: string, gross_profit: string, delivery_cost: string, unconverted: int} $combined */
        $combined = $this->connection->selectOne("WITH {$won}
            SELECT COALESCE(SUM(base_net_amount), 0.000000) AS won_value,
                COALESCE(SUM(base_net_amount - base_additional_total - base_cost), 0.000000) AS gross_profit,
                COALESCE(SUM(base_additional_total), 0.000000) AS delivery_cost,
                COUNT(*) FILTER (WHERE base_net_amount IS NULL) AS unconverted
            FROM won", [$from, $to]);

        /** @var list<object{month: string, currency: string, won_value: string, gross_profit: string}> $monthly */
        $monthly = $this->connection->select(<<<'SQL'
            SELECT month, currency, SUM(net_amount) AS won_value, SUM(net_amount - additional_total - cost) AS gross_profit
            FROM (
                SELECT DISTINCT ON (chain_id, to_char(sent_day, 'YYYY-MM')) to_char(sent_day, 'YYYY-MM') AS month, *
                FROM dashboard_sent_versions
                WHERE sent_day BETWEEN ? AND ?
                ORDER BY chain_id, to_char(sent_day, 'YYYY-MM'), version DESC, sent_day DESC
            ) latest
            WHERE status IN ('accepted', 'partial')
            GROUP BY month, currency ORDER BY month, currency
            SQL, [$from, $to]);

        return [
            'currencies' => array_map(static fn (object $r): array => [
                'currency' => $r->currency, 'won_value' => (string) $r->won_value, 'cost' => (string) $r->cost,
                'gross_profit' => (string) $r->gross_profit, 'margin_percent' => (string) $r->margin_percent,
                'delivery_cost' => (string) $r->delivery_cost, 'won_chains' => (int) $r->won_chains,
            ], $currencies),
            'combined' => [
                'won_value' => (string) $combined->won_value, 'gross_profit' => (string) $combined->gross_profit,
                'delivery_cost' => (string) $combined->delivery_cost, 'unconverted' => (int) $combined->unconverted,
            ],
            'monthly' => array_map(static fn (object $r): array => [
                'month' => $r->month, 'currency' => $r->currency, 'won_value' => (string) $r->won_value, 'gross_profit' => (string) $r->gross_profit,
            ], $monthly),
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
