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

    /**
     * An **upload temp file** as a read stream, for a caller that parses bytes
     * it never stores. Module 3 Point 3.6 is the first.
     *
     * The pair to `store()`'s `$sourcePath`: this interface already knows what
     * PHP's upload temp file is, and this is the other thing a caller can
     * legitimately want to do with one. It takes a raw path rather than a
     * {@see StoragePath} for exactly that reason — the file is not in storage,
     * and giving it a storage path would claim it was.
     *
     * **Why it exists at all.** §14.2 puts "the local file system behind an
     * abstraction layer", and `StorageServiceTest` enforces that as a property
     * of every file in the application: no `fopen(` outside this module's
     * Infrastructure. A module that must read an uploaded CSV therefore cannot
     * open it, and the alternative — a hand-rolled parser over a string,
     * because `fgetcsv` needs a stream — would reimplement the standard
     * library to get around a boundary rather than through it.
     *
     * The caller closes the handle. Nothing is copied, kept, scanned or
     * indexed: the file's lifetime still belongs to the request.
     *
     * @return resource
     *
     * @throws \RuntimeException when the path is missing or unreadable
     */
    public function readUploadStream(string $sourcePath);

    /**
     * The size, in bytes, of an **upload temp file** — `readUploadStream()`'s
     * pair for a caller that must record a size rather than parse bytes.
     *
     * `filesize()` is one of `StorageServiceTest`'s forbidden calls outside
     * this module's Infrastructure, same reasoning as `readUploadStream()`'s
     * own docblock: a caller that must know how large an upload is cannot
     * measure it itself, and the alternative — counting bytes off a stream
     * `readUploadStream()` already hands out — would read a file already
     * proven a sane size by `UploadValidatorInterface::validate()` a second
     * time to learn a number that check already computed.
     *
     * @throws \RuntimeException when the path is missing or unreadable
     */
    public function sourceSizeBytes(string $sourcePath): int;

    public function exists(StoragePath $path): bool;

    public function delete(StoragePath $path): void;
}
