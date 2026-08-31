<?php

declare(strict_types=1);

namespace App\Modules\Storage\Domain\Contracts;

use App\Modules\Storage\Domain\AttachmentParent;
use App\Modules\Storage\Domain\ValueObjects\StoragePath;

/**
 * The write half `FileRepositoryInterface` deliberately does not carry —
 * that interface's own docblock frames it as "Reads the `files` row and its
 * D-71 pivots", and a create belongs beside `CustomerStatusWriterInterface`'s
 * precedent (Deals Point 3.1): a contract split by verb, not bolted onto the
 * reader the first time a caller needs to write.
 *
 * `AttachDealDocument` (Module 5) is the first caller and, with it, the first
 * write `StorageServiceInterface::store()` has ever had a database row put
 * behind it.
 */
interface FileWriterInterface
{
    /**
     * Records the `files` row for bytes `store()` has already copied into
     * place. `$path->fileId()` is the row's id — not a freshly generated one
     * — on the migration's own "no second identifier column exists to drift".
     *
     * @return string the new `files.id`, i.e. `$path->fileId()`
     */
    public function create(StoragePath $path, string $originalName, string $mimeType, int $sizeBytes, string $actorId): string;

    /** One D-71 pivot row: `$fileId` now hangs from `$parent`/`$parentId`. */
    public function attach(AttachmentParent $parent, string $parentId, string $fileId): void;
}
