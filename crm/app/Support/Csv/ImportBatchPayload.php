<?php

declare(strict_types=1);

namespace App\Support\Csv;

/**
 * One import batch on the wire — customers' `import_batches` and suppliers'
 * `supplier_import_batches` alike (F-10 · 1.2). It takes the five values, not a
 * summary: each module keeps its own `ImportSummary`, because it is named by the
 * module's `Domain` contracts and deptrac gives `Domain` no licence to depend on
 * `SharedContracts`, where this class lives.
 *
 * Four numbers, and the fifth is arithmetic the caller can do: failures are
 * `row_count - imported_count`, which is why the table stores neither and this
 * does not serialise one. A field that can disagree with the two it is derived
 * from is a field that eventually will.
 */
final readonly class ImportBatchPayload
{
    /** @return array<string, string|int> */
    public static function of(string $id, string $originalFilename, int $rowCount, int $importedCount, int $incompleteCount): array
    {
        return [
            'id' => $id,
            'original_filename' => $originalFilename,
            'row_count' => $rowCount,
            'imported_count' => $importedCount,
            'incomplete_count' => $incompleteCount,
        ];
    }
}
