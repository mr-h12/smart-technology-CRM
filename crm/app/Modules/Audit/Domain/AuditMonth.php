<?php

declare(strict_types=1);

namespace App\Modules\Audit\Domain;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * One month of `audit_log`, as `D-72` partitions it: the partition's name and
 * its two bounds.
 *
 * **Everything is normalised to UTC on the way in.** `DB-08` stores UTC, and a
 * month boundary computed in any other zone is wrong by that zone's offset —
 * which does not look wrong, because it is still midnight on the first of
 * something. Point 6.1 measured what that costs: with the offset lost, August's
 * partition began at 21:00 on 31 July, so the first three hours of every month
 * were filed under the month before.
 *
 * No Illuminate here, deliberately: `deptrac.layers.yaml` gives Domain an empty
 * ruleset, and Coding Standards §3.1 keeps framework calls out of it.
 */
final readonly class AuditMonth
{
    /** The shape `D-72`'s migration seeded, so the two agree by construction. */
    public const PREFIX = 'audit_log_';

    private function __construct(
        public string $partition,
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
    ) {}

    public static function containing(DateTimeInterface $moment): self
    {
        $utc = DateTimeImmutable::createFromInterface($moment)
            ->setTimezone(new DateTimeZone('UTC'));

        $start = $utc
            ->setDate((int) $utc->format('Y'), (int) $utc->format('n'), 1)
            // The fourth argument is microseconds. Without it the bound carries
            // whatever fraction of a second the clock had, and two runs on the
            // same day produce two different bounds for the same month.
            ->setTime(0, 0, 0, 0);

        return new self(
            self::PREFIX.$start->format('Y_m'),
            $start,
            $start->modify('+1 month'),
        );
    }

    public function next(): self
    {
        return self::containing($this->end);
    }

    /** The literal a `FOR VALUES FROM` clause needs, offset included. */
    public function lowerBound(): string
    {
        return $this->start->format('Y-m-d H:i:sP');
    }

    public function upperBound(): string
    {
        return $this->end->format('Y-m-d H:i:sP');
    }
}
