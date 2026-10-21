<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Domain\Contracts;

/**
 * J-09's own tables (`D-146`): what it writes, and the newest run it recorded.
 * Each `replace…` deletes the given days (every day when `$days` is null) and
 * inserts `$rows` in their place.
 */
interface DashboardAggregatesInterface
{
    /** When the newest recorded run began — the hourly run's starting point. Null before the first. */
    public function lastStartedAt(): ?\DateTimeImmutable;

    /**
     * @param  list<string>|null  $days
     * @param  list<array{day: string, owner_id: ?string, status: string, deals: int}>  $rows
     */
    public function replaceDeals(?array $days, array $rows): void;

    /**
     * @param  list<string>|null  $days
     * @param  list<array{day: string, owner_id: ?string, customers: int}>  $rows
     */
    public function replaceCustomers(?array $days, array $rows): void;

    /**
     * @param  list<string>|null  $days
     * @param  list<array{day: string, approver_id: string, approvals: int}>  $rows
     */
    public function replaceSelfApprovals(?array $days, array $rows): void;

    /** @param  list<array{owner_id: string, pending_approvals: int, returned: int}>  $rows */
    public function replaceLiveCounts(array $rows): void;

    public function recordRefresh(string $kind, \DateTimeImmutable $startedAt, \DateTimeImmutable $finishedAt): void;

    /** The newest run's `finished_at` as stored — "Last updated" (`D-145`). Null before the first. */
    public function lastFinishedAt(): ?string;

    /**
     * The Overview's sums (`D-144`) over the days `[$from, $to]` (`Y-m-d`, both
     * included); the "now" counts whatever the days. `$ownerIds` keeps only the
     * rows owned (or self-approved) by those users; null keeps every row, unowned
     * ones included.
     *
     * @param  list<string>|null  $ownerIds
     * @return array{customers: int, deals_by_status: array<string, int>, self_approvals: int, pending_approvals: int, returned: int}
     */
    public function overviewBetween(string $from, string $to, ?array $ownerIds): array;

    /**
     * Replaces the whole money projection (`D-141`, `D-149`).
     *
     * @param  list<array{quotation_id: string, chain_id: string, version: int, sent_day: string, owner_id: ?string, currency: string, status: string, net_amount: string, additional_total: string, cost: string, base_net_amount: ?string, base_additional_total: ?string, base_cost: ?string}>  $rows
     */
    public function replaceSentVersions(array $rows): void;

    /**
     * `D-141`'s won money over `[$from, $to]`: per chain the latest version sent in
     * the period, kept when `accepted` or `partial`. Exact decimal strings.
     *
     * @return array{
     *     currencies: list<array{currency: string, won_value: string, cost: string, gross_profit: string, margin_percent: string, delivery_cost: string, won_chains: int}>,
     *     combined: array{won_value: string, gross_profit: string, delivery_cost: string, unconverted: int},
     *     monthly: list<array{month: string, currency: string, won_value: string, gross_profit: string}>
     * }
     */
    public function moneyBetween(string $from, string $to): array;
}
