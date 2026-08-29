<?php

declare(strict_types=1);

namespace App\Modules\Customers\Domain\Importing;

/**
 * One `import_batches` row, as the caller who uploaded the file reads it.
 *
 * **Four numbers and no fifth.** Outright failures are `rowCount -
 * importedCount`, exactly as the table's own note says: a stored count can
 * disagree with the two it is computed from, and this class does not offer a
 * place for that disagreement to live either.
 *
 * `D-31` is why `importedCount` and `incompleteCount` are separate: a flagged
 * row **saved**, so it is imported and incomplete at the same time, and
 * `incomplete_count <= imported_count` is a CHECK on the table.
 */
final readonly class ImportSummary
{
    public function __construct(
        public string $id,
        public string $originalFilename,
        public int $rowCount,
        public int $importedCount,
        public int $incompleteCount,
    ) {}
}
