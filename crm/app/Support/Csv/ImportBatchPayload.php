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
    /**
     * `rejected` and `skipped` are `D-94`'s per-row lists (F-20 · 1.1), not
     * stored: they answer this upload and nothing reads them later.
     *
     * @param  list<array{row: int, field: string, code: string, message: string}>  $rejected
     * @param  list<int>  $skipped  the spreadsheet rows of the duplicates
     * @return array<string, mixed>
     */
    public static function of(string $id, string $originalFilename, int $rowCount, int $importedCount, int $incompleteCount, array $rejected, array $skipped): array
    {
        return [
            'id' => $id,
            'original_filename' => $originalFilename,
            'row_count' => $rowCount,
            'imported_count' => $importedCount,
            'incomplete_count' => $incompleteCount,
            'rejected' => $rejected,
            'skipped' => $skipped,
        ];
    }
}
