<?php

declare(strict_types=1);

namespace App\Modules\Audit\Domain;

/**
 * What one run of `J-15` did, and what it found.
 *
 * The counts are the operator's only view of whether the audit log is healthy,
 * so they report work actually performed rather than work attempted: `§15`
 * requires the job to be idempotent, and a second run that claims to have
 * created something is indistinguishable from one that did.
 */
final readonly class PartitionMaintenance
{
    /**
     * @param  list<string>  $created  partitions this run added
     * @param  list<string>  $armed  partitions this run gave a TRUNCATE guard
     * @param  list<string>  $blocked  months that could not be created, because a
     *                                 row for them is already sitting in the
     *                                 default partition
     * @param  int  $strayRows  rows in the default partition
     */
    public function __construct(
        public array $created,
        public array $armed,
        public array $blocked,
        public int $strayRows,
    ) {}

    /**
     * A stray row is not untidiness. While it sits in the default partition,
     * PostgreSQL refuses to create the range partition that would have held it,
     * so the condition is self-perpetuating and gets worse in silence.
     */
    public function isHealthy(): bool
    {
        return $this->strayRows === 0 && $this->blocked === [];
    }
}
