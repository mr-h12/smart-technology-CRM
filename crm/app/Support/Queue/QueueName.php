<?php

declare(strict_types=1);

namespace App\Support\Queue;

/**
 * The four queues §15.1 names, as a type rather than four string literals.
 *
 * The values are not the source of truth — config/queue.php is, because AP-08
 * puts operational values in configuration rather than in classes. This enum is
 * the typed way to say one of them at a call site, so that `->onQueue(...)`
 * cannot be handed a queue that nothing drains. QueueConfigurationTest asserts
 * the two agree, in order, and that a worker in docker-compose.yml consumes
 * each one.
 *
 * The order of the cases is §15.1's priority order and is load-bearing: it is
 * what `all()` returns and what worker capacity is allocated against.
 */
enum QueueName: string
{
    /** Recovery · system health. */
    case Critical = 'critical';

    /** PDF generation (PRF-04 puts it here and nowhere else). */
    case Pdf = 'pdf';

    /** Report generation. */
    case Reports = 'reports';

    /** Cleanup · indexing · aggregates. */
    case Maintenance = 'maintenance';

    /**
     * Every queue, in §15.1's priority order.
     *
     * @return list<self>
     */
    public static function all(): array
    {
        return self::cases();
    }

    /**
     * The priority column of §15.1: 1 is drained first, 4 last.
     *
     * Derived from the case order rather than written twice, so a queue cannot
     * be inserted without its priority moving with it.
     */
    public function priority(): int
    {
        return array_search($this, self::cases(), true) + 1;
    }
}
