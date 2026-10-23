<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain\Contracts;

/** Module 16 · 5 (`D-153`, `BK-04`) — `backup_runs`, `J-10`'s record. */
interface BackupRunsInterface
{
    /** Whether a run that started on `$day` (UTC, `Y-m-d`) succeeded. */
    public function succeededOn(string $day): bool;

    /** @return string the new `running` row's id */
    public function start(\DateTimeImmutable $at): string;

    /** @param array{database: array{path: string, sha256: string, bytes: int}, files: array{path: string, sha256: string, bytes: int}} $artefacts */
    public function succeed(string $runId, array $artefacts, \DateTimeImmutable $at): void;

    public function fail(string $runId, string $error, \DateTimeImmutable $at): void;
}
