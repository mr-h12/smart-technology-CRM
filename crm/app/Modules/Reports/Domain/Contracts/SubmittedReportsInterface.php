<?php

declare(strict_types=1);

namespace App\Modules\Reports\Domain\Contracts;

/**
 * `D-132`, spec §4.1: what the Pdf module reads of a report — one submitted
 * version's frozen copy, and whether a caller may open the report.
 */
interface SubmittedReportsInterface
{
    /**
     * One submitted version as it was frozen (`D-126`, `D-130`, `D-131`, `D-133`) —
     * RPT-MN has no `activity` and carries its counts instead (`D-140`), and its
     * money per currency (`D-141`);
     * null for a draft or a version that does not exist. Times are UTC, ATOM.
     * Selling totals only — no cost, margin or profit — except RPT-MN's `money`, whose
     * cost and profit are there when its author held `quotation.view_cost_and_margin` (`D-141`).
     *
     * @return array{
     *     report_id: string, version: int, code: string, type: string, author_id: string, author_name: string,
     *     period_start: string, period_end: string, submitted_at: string,
     *     follow_ups: list<array{customer_id: string, customer_name: string, note: string, followed_up_at?: string}>,
     *     notes: list<array{body: string}>,
     *     activity?: array{counts: array<string, int>, sections: array<string, list<array<string, string|int|null>>>},
     *     daily_reports?: list<array{code: string, period_start: string, status: string}>,
     *     purchase_orders_received?: int,
     *     new_customers?: array{count: int, items: list<array{customer_id: string, name: string}>},
     *     deals_by_status?: list<array{status: string, count: int}>,
     *     quotations?: array{sent: int, won: int, lost: int},
     *     money?: list<array{currency: string, won_value: string, cost?: string, profit?: string}>
     * }|null
     */
    public function version(string $reportId, int $version): ?array;

    /** True when the actor is the report's author or a live recipient — `ReportAccess`'s rule, not a copy of it. */
    public function mayOpen(string $reportId, string $actorId): bool;
}
