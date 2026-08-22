<?php

declare(strict_types=1);

namespace App\Modules\Audit\Presentation;

use App\Modules\Audit\Application\EnsureAuditPartitions;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

/**
 * `J-15 ensure_audit_partitions` (`§15`) — the entry point, and nothing else.
 *
 * Thin in the same way a controller is thin: read configuration, invoke the use
 * case, render the answer, choose an exit code. Every decision is in
 * `EnsureAuditPartitions`, where it can be exercised without a console.
 */
final class EnsureAuditPartitionsCommand extends Command
{
    protected $signature = 'audit:ensure-partitions
                            {--months= : How many months ahead to guarantee; defaults to config}';

    protected $description = 'J-15: create upcoming audit_log partitions, arm their TRUNCATE guards, and report stray rows';

    public function handle(EnsureAuditPartitions $ensure, ConfigRepository $config): int
    {
        $months = $this->option('months');

        // ->integer() rather than a cast on ->get(): a misconfigured string
        // becomes 0 under a cast, and 0 months ahead is a job that keeps only
        // the current month and lets the table die at the next boundary
        // without ever reporting a failure.
        $monthsAhead = is_string($months)
            ? (int) $months
            : $config->integer('audit.partitions.months_ahead');

        $result = $ensure->upTo($monthsAhead, Date::now('UTC'));

        $this->info(sprintf(
            'J-15: created %d, armed %d, %s holds %d row(s).',
            count($result->created),
            count($result->armed),
            'audit_log_default',
            $result->strayRows,
        ));

        foreach ($result->created as $partition) {
            $this->line('  created '.$partition);
        }

        foreach ($result->armed as $partition) {
            $this->line('  armed '.$partition);
        }

        if ($result->isHealthy()) {
            return self::SUCCESS;
        }

        // A non-zero exit is the alarm. OBS-06 wants alerting on a failed
        // maintenance job, and the scheduler is what notices a non-zero exit;
        // printing a warning and returning 0 would make this job report success
        // for the one state it exists to detect.
        foreach ($result->blocked as $partition) {
            $this->error(
                "J-15: cannot create {$partition} — audit_log_default already holds a row "
                .'for that month, and PostgreSQL refuses the partition while it does.',
            );
        }

        if ($result->strayRows > 0) {
            $this->error(
                "J-15: audit_log_default holds {$result->strayRows} row(s). "
                .'Every one of them is a row that missed its month, and each blocks the '
                .'partition it belonged in from ever being created.',
            );
        }

        return self::FAILURE;
    }
}
