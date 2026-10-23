<?php

declare(strict_types=1);

namespace App\Modules\Storage\Domain\Contracts;

use App\Modules\Storage\Domain\BackupNotWritten;

/** Module 16 · 5 (`D-153`, `BK-01`) — writes one backup: the database and the stored files. */
interface BackupWriterInterface
{
    /**
     * Writes `<folder>/database.dump` and `<folder>/files.tar.gz` under the backups
     * root, all or nothing.
     *
     * @return array{database: array{path: string, sha256: string, bytes: int}, files: array{path: string, sha256: string, bytes: int}} paths relative to the root
     *
     * @throws BackupNotWritten with what went wrong; the folder is removed
     */
    public function write(string $folder): array;
}
