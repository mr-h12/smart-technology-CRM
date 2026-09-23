<?php

declare(strict_types=1);

namespace App\Modules\Storage\Domain\Contracts;

use App\Modules\Storage\Domain\AttachmentLink;
use App\Modules\Storage\Domain\AttachmentParent;
use App\Modules\Storage\Domain\ScanStatus;
use App\Modules\Storage\Domain\StoredFile;

/**
 * Reads the `files` row and its D-71 pivots.
 *
 * Soft-deleted rows are invisible here (DB-01): a restored archive is a
 * different decision from a download, and this interface only serves the latter.
 */
interface FileRepositoryInterface
{
    public function find(string $id): ?StoredFile;

    /**
     * Every parent the file is attached to, across the four pivots.
     *
     * An empty list is meaningful, not an error: it is a file uploaded and never
     * attached — the orphan J-11 sweeps up.
     *
     * @return list<AttachmentLink>
     */
    public function parentsOf(string $fileId): array;

    /**
     * Whether the parent has at least one (not deleted) file — Module 10 ·
     * 2.2's `has_attachment` on a purchase order, read here because the
     * pivots are Storage's (`D-71`).
     */
    public function hasFiles(AttachmentParent $parent, string $parentId): bool;

    /**
     * Writes the scanner's verdict and the moment it was given (SEC-15).
     *
     * Only ever called with an answer. "Could not check" is an exception, not a
     * status, so nothing here can record a file as examined when it was not.
     */
    public function recordScan(string $fileId, ScanStatus $status): void;
}
