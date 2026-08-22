<?php

declare(strict_types=1);

namespace App\Modules\Audit\Domain\Contracts;

use App\Modules\Audit\Domain\AuditMonth;

/**
 * Everything `J-15` needs the database to tell it or do for it.
 *
 * Narrow on purpose: the use case decides *which* months and *whether* to arm,
 * and this only answers and executes. That is what lets the decision be tested
 * without a database and the DDL be tested with one.
 */
interface AuditPartitionsInterface
{
    /**
     * Every partition attached to `audit_log`.
     *
     * @return list<string>
     */
    public function names(): array;

    public function create(AuditMonth $month): void;

    /**
     * Partitions carrying no `TRUNCATE` guard — any partition, however it got
     * there. Point 6.2's guard does not propagate, so this is not limited to
     * partitions this job created.
     *
     * @return list<string>
     */
    public function unguarded(): array;

    public function armTruncateGuard(string $partition): void;

    /**
     * Rows already in the default partition that fall inside this month.
     *
     * Asked *before* creating, because creating over them does not fail
     * gracefully: PostgreSQL rejects the whole statement with `updated
     * partition constraint for default partition ... would be violated by some
     * row`, and an unhandled failure there stops the run before it arms
     * anything.
     */
    public function strayRowsWithin(AuditMonth $month): int;

    /** Every row in the default partition, whatever month it belongs to. */
    public function strayRowCount(): int;
}
