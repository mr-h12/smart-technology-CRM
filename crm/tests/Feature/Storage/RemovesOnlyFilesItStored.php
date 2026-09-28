<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Modules\Storage\Domain\AttachmentParent;

/**
 * F-31 · 1.1 — an upload test removes only the files it stored. The disk is
 * the development volume (phpunit.xml forces no STORAGE_PATH, so
 * `StorageServiceTest` can check the real one), so a glob on the parent alone
 * would take files a person uploaded (DB-01). An upload over HTTP answers with
 * no disk path, so what the test stored is what appeared after `setUp`.
 */
trait RemovesOnlyFilesItStored
{
    /** @var list<string> */
    private array $filesBefore = [];

    private function rememberStoredFiles(AttachmentParent $parent): void
    {
        $this->filesBefore = $this->storedFiles($parent);
    }

    private function removeFilesStoredSince(AttachmentParent $parent): void
    {
        foreach (array_diff($this->storedFiles($parent), $this->filesBefore) as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /** @return list<string> */
    private function storedFiles(AttachmentParent $parent): array
    {
        $root = config('filesystems.disks.secure_uploads.root');

        return is_string($root) ? (glob($root.'/*/*/'.$parent->value.'/*/*') ?: []) : [];
    }
}
