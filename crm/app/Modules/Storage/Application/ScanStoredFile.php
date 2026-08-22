<?php

declare(strict_types=1);

namespace App\Modules\Storage\Application;

use App\Modules\Storage\Domain\Contracts\FileRepositoryInterface;
use App\Modules\Storage\Domain\Contracts\StorageServiceInterface;
use App\Modules\Storage\Domain\Contracts\VirusScannerInterface;
use App\Modules\Storage\Domain\ScanStatus;
use RuntimeException;

/**
 * Scans a stored file and records the answer (SEC-15).
 *
 * Nothing is written unless the scanner actually answered. A ScannerUnavailable
 * propagates untouched and the row stays `pending`, which keeps the file
 * undownloadable — the safe outcome — and leaves a retry meaningful. Catching it
 * here and writing anything at all would be the whole defect this class exists
 * to avoid.
 */
final readonly class ScanStoredFile
{
    public function __construct(
        private FileRepositoryInterface $files,
        private StorageServiceInterface $storage,
        private VirusScannerInterface $scanner,
    ) {}

    public function scan(string $fileId): ScanStatus
    {
        $file = $this->files->find($fileId);

        if ($file === null) {
            throw new RuntimeException("No such file to scan: {$fileId}");
        }

        $stream = $this->storage->readStream($file->path);

        try {
            $status = $this->scanner->scan($stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $this->files->recordScan($fileId, $status);

        return $status;
    }
}
