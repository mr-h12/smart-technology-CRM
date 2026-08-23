<?php

declare(strict_types=1);

namespace App\Support\Queue\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;

/**
 * A job whose only purpose is to prove the queue works.
 *
 * It carries no business meaning and writes to nothing a module owns. What it
 * does is leave evidence that cannot be produced any other way: which queue it
 * actually ran on, which attempt it was, and when — recorded from inside the
 * worker process, after the payload has made a real round trip through Redis.
 *
 * The receipt goes to Redis rather than to a table because Module 0 owns no
 * table for it and a migration for a probe would be a schema nobody wants. It
 * is a list, not a value, so retries append instead of overwriting — that is
 * what makes §15.1's "fixed retry count, then Failed" observable rather than
 * inferred.
 *
 * Timestamps are UTC (DB-08). The job is idempotent in the sense §15.1 requires
 * of every job: running it twice appends a second receipt and changes nothing
 * else.
 */
final class InfrastructureProbeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Receipts live under this prefix, one Redis list per probe token.
     */
    public const RECEIPT_PREFIX = 'infrastructure-probe:';

    public function __construct(
        public string $token,
        public bool $failDeliberately = false,
    ) {}

    public function handle(): void
    {
        Redis::connection()->rpush(self::RECEIPT_PREFIX.$this->token, json_encode([
            // Asked of the job at runtime, not of the dispatch call. A worker
            // reading the wrong list is exactly the defect worth catching, and
            // echoing back what the test already knows would hide it.
            'queue' => $this->job?->getQueue(),
            'attempt' => $this->attempts(),
            'executed_at' => Carbon::now('UTC')->toIso8601String(),
        ], JSON_THROW_ON_ERROR));

        if ($this->failDeliberately) {
            throw new InfrastructureProbeFailed($this->token);
        }
    }
}
