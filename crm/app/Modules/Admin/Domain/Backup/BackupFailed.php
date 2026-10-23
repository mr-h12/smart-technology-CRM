<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain\Backup;

use DateTimeImmutable;

/** Module 16 · 5 (`D-153`, `BK-05`): a `J-10` run that left no backup, and why. */
final readonly class BackupFailed
{
    public function __construct(
        public string $runId,
        public string $error,
        public DateTimeImmutable $startedAt,
    ) {}
}
