<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure;

use App\Modules\Admin\Domain\Contracts\BackupRunsInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/** Module 16 · 5 (`D-153`, `BK-04`) — `backup_runs`. */
final class DatabaseBackupRuns implements BackupRunsInterface
{
    public function __construct(private ConnectionInterface $connection) {}

    public function succeededOn(string $day): bool
    {
        return $this->connection->table('backup_runs')
            ->where('status', 'succeeded')
            ->whereRaw("(started_at AT TIME ZONE 'UTC')::date = ?::date", [$day])
            ->exists();
    }

    public function start(\DateTimeImmutable $at): string
    {
        $id = (string) Str::uuid7();
        $this->connection->table('backup_runs')->insert([
            'id' => $id, 'status' => 'running', 'started_at' => $at, 'created_at' => $at, 'updated_at' => $at,
        ]);

        return $id;
    }

    public function succeed(string $runId, array $artefacts, \DateTimeImmutable $at): void
    {
        $this->connection->table('backup_runs')->where('id', $runId)->update([
            'status' => 'succeeded',
            'finished_at' => $at,
            'database_path' => $artefacts['database']['path'],
            'database_sha256' => $artefacts['database']['sha256'],
            'database_bytes' => $artefacts['database']['bytes'],
            'files_path' => $artefacts['files']['path'],
            'files_sha256' => $artefacts['files']['sha256'],
            'files_bytes' => $artefacts['files']['bytes'],
            'updated_at' => $at,
        ]);
    }

    public function fail(string $runId, string $error, \DateTimeImmutable $at): void
    {
        $this->connection->table('backup_runs')->where('id', $runId)->update([
            'status' => 'failed', 'finished_at' => $at, 'error' => mb_substr($error, 0, 4000), 'updated_at' => $at,
        ]);
    }
}
