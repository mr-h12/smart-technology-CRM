<?php

declare(strict_types=1);

namespace App\Modules\Storage\Domain;

use App\Modules\Storage\Domain\ValueObjects\StoragePath;
use DateTimeImmutable;

/**
 * The `files` row, as the domain sees it.
 *
 * `scanStatus` is here rather than left in the database because SEC-15 makes it
 * a rule about serving, not a column: a file whose scan has not come back clean
 * is not downloadable, whoever asks.
 */
final readonly class StoredFile
{
    public function __construct(
        public string $id,
        public string $originalName,
        public string $mimeType,
        public int $sizeBytes,
        public StoragePath $path,
        public ScanStatus $scanStatus,
        // Module 10 · 2.3: a parent's file list shows when each was attached.
        public DateTimeImmutable $createdAt,
    ) {}

    public function isScannedClean(): bool
    {
        return $this->scanStatus->isServable();
    }
}
