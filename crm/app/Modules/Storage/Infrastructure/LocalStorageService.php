<?php

declare(strict_types=1);

namespace App\Modules\Storage\Infrastructure;

use App\Modules\Storage\Domain\AllowedFileType;
use App\Modules\Storage\Domain\AttachmentParent;
use App\Modules\Storage\Domain\Contracts\StorageServiceInterface;
use App\Modules\Storage\Domain\ValueObjects\StoragePath;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The local driver behind StorageServiceInterface (§14.2).
 *
 * This is the only class in the application permitted to touch a filesystem;
 * StorageServiceTest scans app/ and routes/ and fails on anything else that
 * reaches for the Storage facade or a raw PHP file function. An abstraction
 * that callers may go around is documentation, not a boundary.
 */
final readonly class LocalStorageService implements StorageServiceInterface
{
    public function __construct(private Filesystem $disk) {}

    public function store(
        AttachmentParent $parent,
        string $parentId,
        AllowedFileType $type,
        string $sourcePath,
    ): StoragePath {
        // UUIDv7 for the same reason D-61 uses it for a primary key: the name
        // sorts by creation time, so a directory listing and a backup walk the
        // files in the order they arrived instead of at random.
        $path = StoragePath::for(
            $parent,
            $parentId,
            Str::uuid7()->toString(),
            $type->value,
            // Through the Date facade rather than Carbon directly: Carbon\* sits
            // in no deptrac layer, so importing it here reports as an uncovered
            // dependency — and an uncovered dependency is a boundary nobody
            // decided on. Illuminate\Support\Carbon is the Framework layer.
            Date::now('UTC')->toDateTimeImmutable(),
        );

        // Streamed, not read into a string: the ceiling is 30 MB per file
        // (D-71) and PHP's memory_limit is a per-process budget shared with
        // everything else the request is doing.
        $stream = fopen($sourcePath, 'rb');

        if ($stream === false) {
            throw new RuntimeException("The upload source could not be opened: {$sourcePath}");
        }

        try {
            $this->disk->writeStream($path->value, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $path;
    }

    public function read(StoragePath $path): string
    {
        $contents = $this->disk->get($path->value);

        if (! is_string($contents)) {
            throw new RuntimeException("Stored file not readable: {$path->value}");
        }

        return $contents;
    }

    public function readStream(StoragePath $path)
    {
        $stream = $this->disk->readStream($path->value);

        if (! is_resource($stream)) {
            throw new RuntimeException("Stored file not readable: {$path->value}");
        }

        return $stream;
    }

    /** @return resource */
    public function readUploadStream(string $sourcePath)
    {
        // Not through the disk: an upload temp file is not on `secure_uploads`
        // and never will be. This is the abstraction's whole job — the one
        // directory in the application where opening a file is allowed.
        $handle = @fopen($sourcePath, 'r');

        if ($handle === false) {
            throw new RuntimeException('The uploaded file could not be opened.');
        }

        return $handle;
    }

    public function sourceSizeBytes(string $sourcePath): int
    {
        // Not through the disk, on `readUploadStream()`'s own reasoning: an
        // upload temp file is not on `secure_uploads`.
        $size = @filesize($sourcePath);

        if ($size === false) {
            throw new RuntimeException('The uploaded file could not be measured.');
        }

        return $size;
    }

    public function exists(StoragePath $path): bool
    {
        return $this->disk->exists($path->value);
    }

    public function delete(StoragePath $path): void
    {
        $this->disk->delete($path->value);
    }
}
