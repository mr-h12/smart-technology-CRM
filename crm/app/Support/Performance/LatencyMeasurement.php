<?php

declare(strict_types=1);

namespace App\Support\Performance;

/**
 * The outcome of one measurement run, and the verdict it implies.
 *
 * The verdict lives here rather than in the console command for the reason
 * `EnsureAuditPartitionsCommand` gives: a decision inside a command can only be
 * exercised by running the command, and running this one needs a web server.
 * Everything below is decided without a socket, so a test can reach it.
 */
final readonly class LatencyMeasurement
{
    /**
     * @param  list<int>  $unexpectedStatuses  every response code that was not 200,
     *                                         in the order it came back
     * @param  int  $requested  how many measured requests were asked for, so a run
     *                          that quietly produced fewer cannot report a pass
     */
    public function __construct(
        public LatencySamples $samples,
        public array $unexpectedStatuses,
        public int $requested,
    ) {}

    /**
     * Three ways to fail, and all three are failures.
     *
     * A budget check that only compares the percentile is the classic worthless
     * benchmark: an endpoint returning 500 in 2 ms would pass it comfortably. So
     * any non-200 fails the run outright, and so does a run that lost samples —
     * a short set is a measurement that did not happen, not a fast one.
     */
    public function satisfies(int $percentile, int $budgetMicroseconds): bool
    {
        if ($this->unexpectedStatuses !== []) {
            return false;
        }

        if ($this->samples->count() !== $this->requested) {
            return false;
        }

        return $this->samples->withinBudget($percentile, $budgetMicroseconds);
    }
}
