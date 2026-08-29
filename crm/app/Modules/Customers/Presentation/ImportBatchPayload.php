<?php

declare(strict_types=1);

namespace App\Modules\Customers\Presentation;

use App\Modules\Customers\Domain\Importing\ImportSummary;

/**
 * One `import_batches` row on the wire.
 *
 * Four numbers, and the fifth is arithmetic the caller can do: failures are
 * `row_count - imported_count`, which is why the table stores neither and this
 * does not serialise one. A field that can disagree with the two it is derived
 * from is a field that eventually will.
 */
final readonly class ImportBatchPayload
{
    /** @return array<string, string|int> */
    public static function of(ImportSummary $batch): array
    {
        return [
            'id' => $batch->id,
            'original_filename' => $batch->originalFilename,
            'row_count' => $batch->rowCount,
            'imported_count' => $batch->importedCount,
            'incomplete_count' => $batch->incompleteCount,
        ];
    }
}
