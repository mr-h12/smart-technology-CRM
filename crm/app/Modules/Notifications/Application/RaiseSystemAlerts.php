<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

use App\Modules\Admin\Domain\Backups\BackupFailed;
use App\Modules\Admin\Domain\Backups\BackupSucceeded;
use App\Modules\Identity\Domain\Authentication\AccountLocked;
use App\Modules\Identity\Domain\Contracts\UserFactsInterface;
use App\Modules\Notifications\Domain\Contracts\AlertLedgerInterface;

/**
 * Module 16, Points 3 and 5 (`D-153`) — the system alerts raised the moment their
 * cause happens, to every active Super Admin (hidden included, §3.12 rule 6).
 * A repeat on the same subject is one alert with one more occurrence, unread
 * again. `ClearResolvedAlerts` (`J-17`) clears them.
 */
final readonly class RaiseSystemAlerts
{
    public const ACCOUNT_LOCKED = 'account_locked';

    public const JOB_FAILED = 'job_failed';

    public const BACKUP_FAILED = 'backup_failed';

    public function __construct(
        private UserFactsInterface $users,
        private AlertLedgerInterface $alerts,
    ) {}

    /** `SEC-03`: Identity's own event, the one its lockout mail listens to. */
    public function accountLocked(AccountLocked $event): void
    {
        $this->alerts->occurred(self::ACCOUNT_LOCKED, $event->userId, $this->users->superAdminIds(), [
            'name' => $event->userName,
            'locked_until' => $event->lockedUntil->format(DATE_ATOM),
        ]);
    }

    /** One alert per job class, so a job that keeps failing is one alert and its counter. */
    public function jobFailed(string $jobClass, string $queue): void
    {
        $short = substr($jobClass, (int) strrpos('\\'.$jobClass, '\\'));

        $this->alerts->occurred(self::JOB_FAILED, $jobClass, $this->users->superAdminIds(), [
            'job' => $short,
            'queue' => $queue,
        ]);
    }

    /** Module 16 · 5 (`BK-05`): each night that fails is news — one more occurrence, unread again. */
    public function backupFailed(BackupFailed $event): void
    {
        $this->alerts->occurred(self::BACKUP_FAILED, null, $this->users->superAdminIds(), [
            'started_at' => $event->startedAt->format(DATE_ATOM),
        ]);
    }

    /** The next good backup clears it, the row kept. */
    public function backupSucceeded(BackupSucceeded $event): void
    {
        $this->alerts->clearAllBut(self::BACKUP_FAILED, []);
    }
}
