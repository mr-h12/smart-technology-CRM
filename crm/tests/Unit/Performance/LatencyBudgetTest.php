<?php

declare(strict_types=1);

namespace Tests\Unit\Performance;

use App\Support\Performance\LatencyMeasurement;
use App\Support\Performance\LatencySamples;
use App\Support\Performance\MeasureApiLatencyCommand;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The arithmetic and the verdict behind `PRF-01`, exercised without a socket.
 *
 * This split is not tidiness. CI starts PostgreSQL and Redis and **no web
 * server** — checked in `.github/workflows/php-image.yml`, which runs
 * `ci-postgres` and `ci-redis` on `ci-net` and nothing else. A test that needed
 * nginx would fail there, so what CI can guarantee is that the percentile is
 * computed correctly and that a breach is recognised as a breach. The number
 * itself is measured against the running stack by
 * `php artisan crm:measure-api-latency` and recorded in `CHECKLIST.md`.
 */
final class LatencyBudgetTest extends TestCase
{
    /**
     * The path the compose file and the CI workflow both mount the master
     * documentation at. `./crm` is the only application bind mount, so without
     * this the §14.5 table is simply not visible from PHP.
     */
    private const MASTER_DOCUMENTATION = '/opt/crm/docs/CRM_Documentation_EN.md';

    /**
     * Defect #4 on this project's list is "a check that is self-consistent
     * rather than correct" — the precision test compared columns against the
     * constants that built them, and `QueueConfigurationTest` compared the enum
     * to the test's own literal. So the budget is not written here. It is read
     * out of the sentence in §14.5 that decides it, and the command's constants
     * are compared against that.
     */
    public function test_that_the_budget_matches_the_row_in_section_14_5(): void
    {
        $this->assertFileExists(
            self::MASTER_DOCUMENTATION,
            'The master documentation is not mounted. This check fails rather than skips: '
            .'without it the budget below is only agreeing with itself.',
        );

        $documentation = file_get_contents(self::MASTER_DOCUMENTATION);
        $this->assertIsString($documentation);

        // /u throughout. This file is bilingual, and in byte mode a pattern
        // scanning it can shift on the trailing byte of an Arabic character —
        // the defect step 3 hit with «م», whose 0x85 is NEL.
        $matched = preg_match(
            '/^\|\s*PRF-01\s*\|\s*API\s+P(\d+)\s*<\s*(\d+)\s*ms/mu',
            $documentation,
            $row,
        );

        $this->assertSame(
            1,
            $matched,
            'The PRF-01 row in §14.5 no longer reads "API P<n> < <n> ms". '
            .'If the target changed, this command changes with it.',
        );

        $this->assertSame(
            (int) $row[1],
            MeasureApiLatencyCommand::PERCENTILE,
            'The percentile the command judges is not the percentile §14.5 names.',
        );

        $this->assertSame(
            (int) $row[2],
            MeasureApiLatencyCommand::BUDGET_MS,
            'The budget the command enforces is not the budget §14.5 sets.',
        );
    }

    public function test_that_the_p95_of_one_hundred_samples_is_the_ninety_fifth_smallest(): void
    {
        $samples = LatencySamples::fromMicroseconds(range(1, 100));

        $this->assertSame(95, $samples->percentile(95));
    }

    public function test_that_the_rank_is_rounded_up_rather_than_truncated(): void
    {
        // 95 % of 20 is 19 exactly, so the rank is the 19th smallest.
        $this->assertSame(19, LatencySamples::fromMicroseconds(range(1, 20))->percentile(95));

        // 95 % of 3 is 2.85, and truncating it to 2 would report the middle
        // sample as the 95th percentile of a three-sample run.
        $this->assertSame(30, LatencySamples::fromMicroseconds([10, 20, 30])->percentile(95));
    }

    public function test_that_a_percentile_is_an_observed_value_and_never_an_interpolation(): void
    {
        // Nearest rank over [10, 20] gives 20, not the 19.5 an interpolating
        // definition would produce. The value compared against the budget has
        // to be a duration that actually happened.
        $this->assertSame(20, LatencySamples::fromMicroseconds([10, 20])->percentile(95));
    }

    public function test_that_the_extremes_and_the_median_are_addressable(): void
    {
        $samples = LatencySamples::fromMicroseconds(range(1, 100));

        $this->assertSame(1, $samples->percentile(0));
        $this->assertSame(50, $samples->percentile(50));
        $this->assertSame(100, $samples->percentile(100));
        $this->assertSame(1, $samples->min());
        $this->assertSame(100, $samples->max());
        $this->assertSame(100, $samples->count());
    }

    public function test_that_the_input_order_does_not_change_the_answer(): void
    {
        $ascending = LatencySamples::fromMicroseconds([1, 2, 3, 4, 900]);
        $shuffled = LatencySamples::fromMicroseconds([900, 3, 1, 4, 2]);

        $this->assertSame($ascending->percentile(95), $shuffled->percentile(95));
        $this->assertSame(900, $shuffled->percentile(95));
    }

    public function test_that_an_empty_run_is_refused_rather_than_reported_as_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LatencySamples::fromMicroseconds([]);
    }

    public function test_that_a_negative_duration_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LatencySamples::fromMicroseconds([10, -1, 20]);
    }

    public function test_that_a_percentile_outside_zero_to_one_hundred_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LatencySamples::fromMicroseconds([10])->percentile(101);
    }

    /**
     * PRF-01 reads "< 500 ms". A run landing exactly on the budget is a breach,
     * and an off-by-one here would widen every budget quoted from §14.5.
     */
    public function test_that_a_percentile_exactly_on_the_budget_is_a_breach(): void
    {
        $budget = MeasureApiLatencyCommand::BUDGET_MS * 1000;

        $onTheLine = LatencySamples::fromMicroseconds(array_fill(0, 20, $budget));
        $justUnder = LatencySamples::fromMicroseconds(array_fill(0, 20, $budget - 1));

        $this->assertFalse($onTheLine->withinBudget(95, $budget));
        $this->assertTrue($justUnder->withinBudget(95, $budget));
    }

    public function test_that_a_clean_fast_run_passes(): void
    {
        $measurement = new LatencyMeasurement(
            LatencySamples::fromMicroseconds(array_fill(0, 200, 12_000)),
            [],
            200,
        );

        $this->assertTrue($measurement->satisfies(95, MeasureApiLatencyCommand::BUDGET_MS * 1000));
    }

    /**
     * The failure mode that makes a benchmark worthless: an endpoint erroring
     * in two milliseconds is faster than one working in twenty, and a check
     * that only reads the percentile would call it an improvement.
     */
    public function test_that_a_fast_error_response_fails_the_run(): void
    {
        $measurement = new LatencyMeasurement(
            LatencySamples::fromMicroseconds(array_fill(0, 199, 2_000)),
            [500],
            200,
        );

        $this->assertFalse($measurement->satisfies(95, MeasureApiLatencyCommand::BUDGET_MS * 1000));
    }

    /**
     * A 301 is the specific one this stack can produce: plain http:// on nginx
     * redirects to https://, and the probe does not follow it.
     */
    public function test_that_a_redirect_fails_the_run(): void
    {
        $measurement = new LatencyMeasurement(
            LatencySamples::fromMicroseconds([1_000]),
            [301],
            1,
        );

        $this->assertFalse($measurement->satisfies(95, MeasureApiLatencyCommand::BUDGET_MS * 1000));
    }

    public function test_that_a_run_which_lost_samples_fails(): void
    {
        // Every sample is fast and every response was 200, but 200 requests
        // were asked for and 150 came back. A short set is a measurement that
        // did not happen, not a fast one.
        $measurement = new LatencyMeasurement(
            LatencySamples::fromMicroseconds(array_fill(0, 150, 5_000)),
            [],
            200,
        );

        $this->assertFalse($measurement->satisfies(95, MeasureApiLatencyCommand::BUDGET_MS * 1000));
    }
}
