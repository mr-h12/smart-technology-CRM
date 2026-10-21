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
     * @param  list<array{quotation_id: string, chain_id: string, version: int, sent_day: string, owner_id: ?string, currency: string, status: string, net_amount: string, additional_total: string, cost: string, base_net_amount: ?string, base_additional_total: ?string, base_cost: ?string, customer_id: string, sector: ?string}>  $rows
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

    /** @param  list<array{day: string, owner_id: ?string, outcome: string, deals: int, cycle_days: int, reason_code: ?string}>  $rows */
    public function replaceOutcomes(array $rows): void;

    /** @param  list<array{owner_id: string, open_deals: int}>  $rows */
    public function replaceWorkload(array $rows): void;

    /** @param  list<array{quotation_id: string, catalog_item_id: ?string, line_total: string, base_line_total: ?string}>  $rows */
    public function replaceSentLines(array $rows): void;

    /**
     * The Sales tab's breakdowns over `[$from, $to]` (`D-144`): `D-141`'s won chains by
     * owner, sector, customer and product (money per currency, base totals at `D-149`),
     * won deals by owner and lost deals by reason from the outcomes. `$ownerIds` scopes
     * every owned row as in `overviewBetween`.
     *
     * @param  list<string>|null  $ownerIds
     * @return array{
     *     lost_by_reason: list<array{code: string, deals: int}>,
     *     won_deals_by_owner: array<string, int>,
     *     money_by_owner: list<array{owner_id: string, currency: string, won_value: string}>,
     *     by_sector: list<array{sector: ?string, currency: string, won_value: string, won_chains: int}>,
     *     by_customer: list<array{id: string, currency: string, won_value: string, base_value: numeric-string|null}>,
     *     by_product: list<array{id: string, currency: string, won_value: string, base_value: numeric-string|null}>
     * }
     */
    public function breakdownsBetween(string $from, string $to, ?array $ownerIds): array;

    /**
     * The Sales tab's counts over `[$from, $to]` (`D-144`): deals created by current
     * status, the won and lost moves with the won cycle days summed, and "now" the
     * open deals per owner. `$ownerIds` as in `overviewBetween`.
     *
     * @param  list<string>|null  $ownerIds
     * @return array{deals_by_status: array<string, int>, won: int, lost: int, cycle_days: int, workload: list<array{owner_id: string, open_deals: int}>}
     */
    public function salesBetween(string $from, string $to, ?array $ownerIds): array;
}
