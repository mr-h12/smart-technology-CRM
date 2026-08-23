<?php

declare(strict_types=1);

namespace App\Support\Performance;

use InvalidArgumentException;

/**
 * An ordered set of measured request durations, held as whole microseconds.
 *
 * Microseconds rather than milliseconds because PRF-01 (§14.5) sets the budget
 * at 500 ms, and a sample set rounded to whole milliseconds cannot tell 499
 * from 500 — the resolution of the measurement would sit exactly on the line it
 * is asked to judge.
 *
 * Integers rather than floats because a nearest-rank percentile is a
 * *selection* from the samples, not an average of them. The value compared
 * against the budget is a duration that was actually observed, so no arithmetic
 * — and therefore no accumulated rounding — happens to it between the wire and
 * the verdict. DB-07 forbids float near money; this is not money, but the same
 * reasoning applies to a number that decides a pass or a fail.
 */
final class LatencySamples
{
    /**
     * Ascending. The constructor is the only place that sorts, so every reader
     * below may rely on the order without re-checking it.
     *
     * @var non-empty-list<int>
     */
    private readonly array $sorted;

    /**
     * @param  non-empty-list<int>  $sortedMicroseconds
     */
    private function __construct(array $sortedMicroseconds)
    {
        $this->sorted = $sortedMicroseconds;
    }

    /**
     * @param  list<int>  $microseconds
     *
     * @throws InvalidArgumentException when the set is empty or holds a
     *                                  negative duration
     */
    public static function fromMicroseconds(array $microseconds): self
    {
        if ($microseconds === []) {
            // A percentile over nothing is not zero, it is undefined. Returning
            // 0 here would make an empty run report a perfect result, which is
            // the single most dangerous way for this class to be wrong.
            throw new InvalidArgumentException('Cannot take a percentile of an empty sample set.');
        }

        foreach ($microseconds as $sample) {
            if ($sample < 0) {
                throw new InvalidArgumentException("A duration cannot be negative: {$sample} µs.");
            }
        }

        sort($microseconds, SORT_NUMERIC);

        return new self($microseconds);
    }

    /**
     * The nearest-rank percentile: the smallest observed value at or below
     * which at least `percentile` per cent of the samples fall.
     *
     *     rank = ceil(percentile × count ÷ 100)      1-based, clamped to ≥ 1
     *
     * The ceiling is computed with integer division — `intdiv(a + b - 1, b)` —
     * rather than `ceil()`, so no float ever participates in choosing the rank.
     * With 100 samples P95 is the 95th smallest; with 20 samples it is the 19th.
     *
     * The parameter is a plain `int` and not `int<0, 100>` on purpose: the
     * value arrives from a command-line option, so the range is a runtime fact
     * this method has to establish, not a promise a caller can be trusted to
     * keep. Annotating it away would make the guard below unreachable.
     *
     * @throws InvalidArgumentException when the percentile is outside 0…100
     */
    public function percentile(int $percentile): int
    {
        if ($percentile < 0 || $percentile > 100) {
            throw new InvalidArgumentException("A percentile must be between 0 and 100, got {$percentile}.");
        }

        $count = count($this->sorted);
        $rank = intdiv($percentile * $count + 99, 100);

        return $this->sorted[max(1, $rank) - 1];
    }

    /**
     * PRF-01 reads "P95 < 500 ms" — strictly less than. A run landing exactly on
     * the budget is a breach, and the boundary is pinned by its own test because
     * an off-by-one here silently widens every budget in the system.
     */
    public function withinBudget(int $percentile, int $budgetMicroseconds): bool
    {
        return $this->percentile($percentile) < $budgetMicroseconds;
    }

    public function count(): int
    {
        return count($this->sorted);
    }

    public function min(): int
    {
        return $this->sorted[0];
    }

    public function max(): int
    {
        return $this->sorted[count($this->sorted) - 1];
    }
}
