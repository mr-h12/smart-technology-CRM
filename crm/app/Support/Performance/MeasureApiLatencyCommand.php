<?php

declare(strict_types=1);

namespace App\Support\Performance;

use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * `PRF-01` (§14.5) measured: "API P95 < 500 ms on the internal network".
 *
 * Thin, in the same sense as `EnsureAuditPartitionsCommand`: read the options,
 * invoke the probe, render the answer, choose an exit code. The percentile is
 * `LatencySamples`, the verdict is `LatencyMeasurement`, and both are decided
 * without a socket so the test suite can reach them — which matters here
 * because CI runs no web server for the probe itself to talk to.
 *
 * The command prints the method above the number on purpose. A latency figure
 * without its conditions is not a measurement, and §14.5's target is only
 * meaningful against a stated endpoint, sample count and connection model.
 */
final class MeasureApiLatencyCommand extends Command
{
    /**
     * PRF-01's budget, in milliseconds. §14.5 states it as a table row:
     * "| PRF-01 | API P95 < 500 ms on the internal network |".
     */
    public const BUDGET_MS = 500;

    /** The percentile PRF-01 names. */
    public const PERCENTILE = 95;

    /**
     * The nginx service over the compose network — the closest local analogue
     * of §14.5's "internal network", and the only hop that exists while the
     * server in OD-03 does not. `/api/v1/ping` is named rather than defaulted
     * to something broader because it is the only route in the application that
     * answers without authentication.
     */
    public const DEFAULT_URL = 'https://nginx/api/v1/ping';

    protected $signature = 'crm:measure-api-latency
                            {--url= : Absolute URL to measure; defaults to the internal nginx ping route}
                            {--requests= : Measured requests; defaults to 200}
                            {--warmup= : Requests issued and discarded first; defaults to 20}
                            {--percentile= : Percentile to judge; defaults to 95 (PRF-01)}
                            {--budget-ms= : Budget in milliseconds; defaults to 500 (PRF-01)}
                            {--insecure : Skip TLS verification, which the local self-signed certificate requires}';

    protected $description = 'PRF-01: measure API latency over the internal network and judge the P95 against its budget';

    public function handle(ApiLatencyProbe $probe): int
    {
        $url = $this->stringOption('url', self::DEFAULT_URL);
        $requests = $this->intOption('requests', 200);
        $warmup = $this->intOption('warmup', 20);
        $percentile = $this->intOption('percentile', self::PERCENTILE);
        $budgetMs = $this->intOption('budget-ms', self::BUDGET_MS);
        $verifyTls = $this->option('insecure') !== true;

        if ($requests < 1) {
            $this->error('--requests must be at least 1.');

            return self::FAILURE;
        }

        if ($warmup < 0) {
            $this->error('--warmup cannot be negative.');

            return self::FAILURE;
        }

        if ($percentile < 0 || $percentile > 100) {
            $this->error('--percentile must be between 0 and 100.');

            return self::FAILURE;
        }

        if ($budgetMs < 1) {
            $this->error('--budget-ms must be at least 1.');

            return self::FAILURE;
        }

        $budgetMicroseconds = $budgetMs * 1000;

        $this->line('Method');
        $this->line('  URL          '.$url);
        $this->line("  Requests     {$requests} measured, {$warmup} warm-up (discarded)");
        $this->line('  Connection   one curl handle reused — keep-alive, handshake charged to warm-up');
        $this->line('  TLS verify   '.($verifyTls ? 'on' : 'off (--insecure)'));
        $this->line("  Percentile   P{$percentile} by nearest rank over the measured samples");
        $this->line('  Budget       '.self::BUDGET_MS.' ms (PRF-01, §14.5)'.(
            $budgetMs === self::BUDGET_MS ? '' : " — overridden to {$budgetMs} ms"
        ));
        $this->newLine();

        $measurement = $probe->measure($url, $requests, $warmup, $verifyTls);
        $samples = $measurement->samples;

        $this->line('Result');
        $this->line(sprintf(
            '  count %d · min %s · P50 %s · P%d %s · max %s',
            $samples->count(),
            $this->milliseconds($samples->min()),
            $this->milliseconds($samples->percentile(50)),
            $percentile,
            $this->milliseconds($samples->percentile($percentile)),
            $this->milliseconds($samples->max()),
        ));

        if ($measurement->unexpectedStatuses !== []) {
            $this->line(sprintf(
                '  %d response(s) were not 200: %s',
                count($measurement->unexpectedStatuses),
                implode(', ', array_unique($measurement->unexpectedStatuses)),
            ));
        }

        $this->newLine();

        if ($measurement->satisfies($percentile, $budgetMicroseconds)) {
            $this->info(sprintf(
                'PASS  P%d %s < %d ms',
                $percentile,
                $this->milliseconds($samples->percentile($percentile)),
                $budgetMs,
            ));

            return self::SUCCESS;
        }

        $this->error(sprintf(
            'FAIL  P%d %s is not under %d ms, or the run did not complete cleanly.',
            $percentile,
            $this->milliseconds($samples->percentile($percentile)),
            $budgetMs,
        ));

        return self::FAILURE;
    }

    /**
     * Rendered from whole microseconds without touching a float, so the printed
     * number is the measured one rather than a rounding of it.
     */
    private function milliseconds(int $microseconds): string
    {
        return sprintf('%d.%03d ms', intdiv($microseconds, 1000), $microseconds % 1000);
    }

    private function stringOption(string $name, string $default): string
    {
        $value = $this->option($name);

        if ($value === null || $value === '') {
            return $default;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException("--{$name} takes a single value.");
        }

        return $value;
    }

    /**
     * Parsed strictly rather than cast. `(int) 'fast'` is 0, and a 0 ms budget
     * or a 0-request run would be reported rather than refused.
     */
    private function intOption(string $name, int $default): int
    {
        $value = $this->option($name);

        if ($value === null || $value === '') {
            return $default;
        }

        if (! is_string($value) || preg_match('/^-?\d+$/', $value) !== 1) {
            throw new InvalidArgumentException("--{$name} must be a whole number.");
        }

        return (int) $value;
    }
}
