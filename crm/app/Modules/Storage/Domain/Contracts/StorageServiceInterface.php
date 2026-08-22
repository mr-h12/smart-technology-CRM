<?php

declare(strict_types=1);

namespace App\Modules\Storage\Domain\Contracts;

use App\Modules\Storage\Domain\AllowedFileType;
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
     * **There is no filename argument, and that is the point.** The extension on
     * disk comes from $type — the answer UploadValidatorInterface got from the
     * bytes (§17: "True MIME type, not the extension"). Until Point 5.4 it came
     * from pathinfo() on the name the browser sent, which is the one part of an
     * upload an attacker fully controls. The original name still reaches the
     * database, for display; it does not reach the path.
     *
     * @param  string  $parentId  the parent row's UUID
     * @param  AllowedFileType  $type  what the bytes actually are, already validated
     *
     * @throws \InvalidArgumentException when a segment would not be safe on disk
     * @throws \RuntimeException when the bytes cannot be read or written
     */
    public function store(
        AttachmentParent $parent,
        string $parentId,
        AllowedFileType $type,
        string $sourcePath,
    ): StoragePath;

    /** @throws \RuntimeException when the file is missing or unreadable */
    public function read(StoragePath $path): string;

    /**
     * The same bytes, as a stream, for callers that must not hold them in memory.
     *
     * A download can be 30 MB (D-71) and there may be several at once; read()
     * would put every one of them into a PHP string at the same time. The caller
     * closes the handle.
     *
     * @return resource
     *
     * @throws \RuntimeException when the file is missing or unreadable
     */
    public function readStream(StoragePath $path);

    public function exists(StoragePath $path): bool;

    public function delete(StoragePath $path): void;
}
