<?php

declare(strict_types=1);

namespace App\Modules\Storage\Infrastructure;

use App\Modules\Storage\Domain\AttachmentLink;
use App\Modules\Storage\Domain\AttachmentParent;
use App\Modules\Storage\Domain\Contracts\FileRepositoryInterface;
use App\Modules\Storage\Domain\ScanStatus;
use App\Modules\Storage\Domain\StoredFile;
use App\Modules\Storage\Domain\ValueObjects\StoragePath;
use Illuminate\Database\ConnectionInterface;

/**
 * Reads `files` and the four D-71 pivots.
 *
 * The query builder rather than an Eloquent model: CLAUDE.md's Laravel section
 * says a module owns its persistence behind an interface and is not a model
 * carrying business rules, and there is no behaviour here worth a model.
 */
final readonly class DatabaseFileRepository implements FileRepositoryInterface
{
    public function __construct(private ConnectionInterface $connection) {}

    public function find(string $id): ?StoredFile
    {
        $row = $this->connection->table('files')
            ->whereNull('deleted_at')   // DB-01: archived is not deleted, but it is not downloadable either
            ->where('id', $id)
            ->first();

        if ($row === null) {
            return null;
        }

        return new StoredFile(
            id: (string) $row->id,               // @phpstan-ignore-line property.nonObject
            originalName: (string) $row->original_name,   // @phpstan-ignore-line property.nonObject
            mimeType: (string) $row->mime_type,           // @phpstan-ignore-line property.nonObject
            sizeBytes: (int) $row->size_bytes,            // @phpstan-ignore-line property.nonObject
            path: StoragePath::fromStored((string) $row->storage_path),   // @phpstan-ignore-line property.nonObject
            scanStatus: ScanStatus::from((string) $row->scan_status),   // @phpstan-ignore-line property.nonObject
        );
    }

    public function parentsOf(string $fileId): array
    {
        $links = [];

        foreach (AttachmentParent::cases() as $parent) {
            $column = $parent->value.'_id';

            $rows = $this->connection->table($parent->pivotTable())
                ->where('file_id', $fileId)
                ->pluck($column);

            foreach ($rows as $parentId) {
                $links[] = new AttachmentLink($parent, (string) $parentId);   // @phpstan-ignore-line cast.string
            }
        }

        return $links;
    }

    public function recordScan(string $fileId, ScanStatus $status): void
    {
        $this->connection->table('files')
            ->where('id', $fileId)
            ->update([
                'scan_status' => $status->value,
                'scanned_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
