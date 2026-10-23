<?php

declare(strict_types=1);

namespace App\Modules\Admin\Application\Backup;

use App\Modules\Admin\Domain\Backup\BackupFailed;
use App\Modules\Admin\Domain\Backup\BackupSucceeded;
use App\Modules\Admin\Domain\Contracts\BackupRunsInterface;
use App\Modules\Storage\Domain\BackupNotWritten;
use App\Modules\Storage\Domain\Contracts\BackupWriterInterface;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Module 16, Point 5 — `J-10` database_backup, minimal (`D-153`: `BK-01` without
 * the search index, `BK-04`).
 *
 * One backup a UTC day: a day with a succeeded run is skipped, so the startup
 * catch-up and the 02:00 run share it, and a failed day is tried again. The run
 * is recorded before it starts, so a crash leaves a `running` row that says so.
 * The outcome is announced (`BackupSucceeded` / `BackupFailed`); what to do about
 * it is the listener's business.
 */
final readonly class RunBackup
{
    public function __construct(
        private BackupRunsInterface $runs,
        private BackupWriterInterface $writer,
        private Dispatcher $events,
    ) {}

    /** @return 'succeeded'|'failed'|'skipped' */
    public function run(): string
    {
        $now = new DateTimeImmutable(now()->toIso8601String());
        $utc = $now->setTimezone(new DateTimeZone('UTC'));

        if ($this->runs->succeededOn($utc->format('Y-m-d'))) {
            return 'skipped';
        }

        $runId = $this->runs->start($now);

        try {
            $artefacts = $this->writer->write($utc->format('Ymd-His'));
        } catch (BackupNotWritten $e) {
            $this->runs->fail($runId, $e->getMessage(), new DateTimeImmutable(now()->toIso8601String()));
            $this->events->dispatch(new BackupFailed($runId, $e->getMessage(), $now));

            return 'failed';
        }

        $this->runs->succeed($runId, $artefacts, new DateTimeImmutable(now()->toIso8601String()));
        $this->events->dispatch(new BackupSucceeded($runId));

        return 'succeeded';
    }
}
