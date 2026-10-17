<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Reports\Domain\Contracts\ReportDirectoryInterface;
use App\Support\Settings\SettingReader;
use Illuminate\Database\ConnectionInterface;

/**
 * `J-08` (§15; §11.4 "automatic archiving after six months"): every non-draft
 * report older than `limits.report_archive_months` (6 while unset — `D-127`)
 * becomes `archived`. It counts from the submit; a Missed report counts from its
 * day. Nothing is deleted, and an archived report still opens. Idempotent
 * (§15.1): only reports not yet archived move. The audit carries no actor — a
 * queued job has no request — as `J-04` records it.
 */
final readonly class ArchiveOldReports
{
    private const DEFAULT_MONTHS = 6;

    public function __construct(
        private ReportDirectoryInterface $reports,
        private SettingReader $limits,
        private AuditRecorderInterface $audit,
        private ConnectionInterface $connection,
    ) {}

    /** @return int how many reports were archived */
    public function run(): int
    {
        $months = $this->limits->nullableInteger('limits.report_archive_months') ?? self::DEFAULT_MONTHS;

        return $this->connection->transaction(function () use ($months): int {
            $moved = $this->reports->archiveOlderThan(now()->subMonthsNoOverflow($months));

            foreach ($moved as $id => $was) {
                $this->audit->record(AuditEvent::of('REPORT_ARCHIVED'), 'report', $id, ['status' => $was], ['status' => 'archived']);
            }

            return count($moved);
        });
    }
}
