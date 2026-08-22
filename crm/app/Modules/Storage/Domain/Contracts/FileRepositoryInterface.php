<?php

declare(strict_types=1);

namespace App\Modules\Storage\Domain\Contracts;

use App\Modules\Storage\Domain\AttachmentLink;
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
}
