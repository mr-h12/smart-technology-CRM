<?php

declare(strict_types=1);

namespace App\Modules\Storage\Domain\Contracts;

use App\Modules\Storage\Domain\AttachmentParent;
use App\Modules\Storage\Domain\ValueObjects\StoragePath;

/**
 * §14.2: "local file system behind an abstraction layer".
 *
 * The abstraction is this interface and nothing else. It names no framework
 * type — no uploaded-file object, no disk, no path helper — because the domain
 * layer may depend on nothing (deptrac.layers.yaml, Coding Standards §3.1), and
 * because D-48's lesson generalises: the driver behind a boundary can be
 * replaced only if the boundary never mentions it. A caller that needs an
 * object storage backend later changes one binding.
 */
interface StorageServiceInterface
{
    /**
     * Copies the bytes at $sourcePath into permanent storage under the §17 path.
     *
     * The source is left alone: it is normally PHP's upload temp file, whose
     * lifetime belongs to the request, not to this service. The returned path is
     * what the caller writes to `files.storage_path`.
     *
     * @param  string  $parentId  the parent row's UUID
     * @param  string  $originalName  kept in the database for display; never used on disk
     *
     * @throws \InvalidArgumentException when a segment would not be safe on disk
     * @throws \RuntimeException when the bytes cannot be read or written
     */
    public function store(
        AttachmentParent $parent,
        string $parentId,
        string $originalName,
        string $sourcePath,
    ): StoragePath;

    /** @throws \RuntimeException when the file is missing or unreadable */
    public function read(StoragePath $path): string;

    public function exists(StoragePath $path): bool;

    public function delete(StoragePath $path): void;
}
