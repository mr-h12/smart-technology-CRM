<?php

declare(strict_types=1);

namespace App\Modules\Audit\Application;

use App\Modules\Audit\Domain\AuditMonth;
use App\Modules\Audit\Domain\Contracts\AuditPartitionsInterface;
use App\Modules\Audit\Domain\PartitionMaintenance;
use DateTimeInterface;

/**
 * `J-15 ensure_audit_partitions` — the decision half.
 *
 * `D-72` seeded two months and stopped, which gave `audit_log` a working life
 * of about eight weeks. After that every row lands in the default partition,
 * and a row in the default partition is precisely what **blocks** creating the
 * partition it belonged in. The failure is silent when it starts and expensive
 * once it is noticed.
 *
 * Three duties, in this order and for a reason:
 *
 * 1. **Create the missing months**, skipping any already there — `§15` requires
 *    idempotency, and here that means a second run must create nothing rather
 *    than merely survive.
 * 2. **Arm every unguarded partition**, not only the ones just created. Point
 *    6.2's `TRUNCATE` guard does not propagate, so a partition made by hand, by
 *    a restore or by a future migration has none; this is what turns a
 *    permanent hole into a window a day wide.
 * 3. **Count what is stranded** in the default partition, and report it.
 *
 * The alarm comes last on purpose. A run that refused to do its work because
 * something else was already wrong would leave next month uncreated too, and
 * then there would be two problems instead of one.
 */
final readonly class EnsureAuditPartitions
{
    public function __construct(private AuditPartitionsInterface $partitions) {}

    public function upTo(int $monthsAhead, DateTimeInterface $now): PartitionMaintenance
    {
        $existing = $this->partitions->names();
        $created = [];
        $blocked = [];

        $month = AuditMonth::containing($now);

        // <= rather than <: "three months ahead" has to include the month we
        // are standing in, or the job's first duty is one it never performs.
        for ($step = 0; $step <= $monthsAhead; $step++) {
            if (! in_array($month->partition, $existing, true)) {
                if ($this->partitions->strayRowsWithin($month) > 0) {
                    $blocked[] = $month->partition;
                } else {
                    $this->partitions->create($month);
                    $created[] = $month->partition;
                }
            }

            $month = $month->next();
        }

        $armed = [];

        foreach ($this->partitions->unguarded() as $partition) {
            $this->partitions->armTruncateGuard($partition);
            $armed[] = $partition;
        }

        return new PartitionMaintenance(
            $created,
            $armed,
            $blocked,
            $this->partitions->strayRowCount(),
        );
    }
}
