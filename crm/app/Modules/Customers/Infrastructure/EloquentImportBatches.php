<?php

declare(strict_types=1);

namespace App\Modules\Customers\Infrastructure;

use App\Modules\Customers\Domain\Contracts\ImportBatchesInterface;
use App\Modules\Customers\Domain\Importing\ImportSummary;
use App\Modules\Customers\Infrastructure\Eloquent\ImportBatch;

/**
 * `import_batches` through Eloquent.
 *
 * The four CHECK constraints on the table are the real guard — `imported_count
 * <= row_count` and `incomplete_count <= imported_count` are `DB-04`'s
 * arithmetic, enforced where it cannot be argued with rather than here.
 */
final readonly class EloquentImportBatches implements ImportBatchesInterface
{
    public function record(
        string $originalFilename,
        int $rowCount,
        int $importedCount,
        int $incompleteCount,
        string $actorId,
    ): ImportSummary {
        $row = new ImportBatch;
        $row->fill([
            'original_filename' => $originalFilename,
            'row_count' => $rowCount,
            'imported_count' => $importedCount,
            'incomplete_count' => $incompleteCount,
        ]);

        // `DB-02`, filled from the request rather than guessed by an observer —
        // the same arrangement `EloquentCustomerDirectory` uses.
        $row->created_by = $actorId;
        $row->updated_by = $actorId;
        $row->save();

        return new ImportSummary(
            $row->id,
            $row->original_filename,
            $row->row_count,
            $row->imported_count,
            $row->incomplete_count,
        );
    }
}
