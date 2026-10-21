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
}
