<?php

declare(strict_types=1);

namespace App\Modules\Storage\Infrastructure;

use App\Modules\Storage\Domain\AttachmentParent;
use App\Modules\Storage\Domain\Contracts\FileWriterInterface;
use App\Modules\Storage\Domain\ScanStatus;
use App\Modules\Storage\Domain\ValueObjects\StoragePath;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * {@see FileWriterInterface} over `files` and the D-71 pivots.
 *
 * The query builder, on `DatabaseFileRepository`'s own reasoning: there is no
 * behaviour here worth an Eloquent model, and this class is a database writer
 * `AuditEnforcementTest`'s scanner is meant to see — nothing here hides that
 * the way an aliased Eloquent model would.
 */
final readonly class DatabaseFileWriter implements FileWriterInterface
{
    public function __construct(private ConnectionInterface $connection) {}

    public function create(StoragePath $path, string $originalName, string $mimeType, int $sizeBytes, string $actorId): string
    {
        $fileId = $path->fileId();
        $now = now();

        $this->connection->table('files')->insert([
            'id' => $fileId,
            'original_name' => $originalName,
            'mime_type' => $mimeType,
            'size_bytes' => $sizeBytes,
            'storage_path' => $path->value,
            // Not `pending`'s absence but its literal value: the column default
            // would do the same, but a write that names every column it means
            // is not one a reader has to cross-check against a schema file.
            'scan_status' => ScanStatus::Pending->value,
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $fileId;
    }

    public function attach(AttachmentParent $parent, string $parentId, string $fileId): void
    {
        $this->connection->table($parent->pivotTable())->insert([
            $parent->value.'_id' => $parentId,
            'file_id' => $fileId,
        ]);
    }

    public function remove(AttachmentParent $parent, string $parentId, string $fileId, string $actorId): ?string
    {
        // A malformed id is a file that does not exist here, not a database error.
        if (! Str::isUuid($fileId)) {
            return null;
        }

        $pivot = $parent->pivotTable();

        $name = $this->connection->table('files')
            ->join($pivot, $pivot.'.file_id', '=', 'files.id')
            ->where($pivot.'.'.$parent->value.'_id', $parentId)
            ->where('files.id', $fileId)
            ->whereNull('files.deleted_at')
            ->value('files.original_name');

        if (! is_string($name)) {
            return null;
        }

        $now = now();

        // DB-01: the row is marked, never deleted; the pivot stays as the record of where it hung.
        $removed = $this->connection->table('files')
            ->where('id', $fileId)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => $now, 'updated_by' => $actorId, 'updated_at' => $now]);

        return $removed === 1 ? $name : null;
    }
}
