<?php

declare(strict_types=1);

namespace App\Modules\Customers\Domain\Contracts;

use App\Modules\Customers\Domain\Importing\ImportSummary;

/**
 * `import_batches`, written once at the end of an import.
 *
 * Separate from {@see CustomerDirectoryInterface} because it is a different
 * table and a different thing: that one answers questions about customers, and
 * this one records that an import happened. Folding the two would give the
 * customer reader a method nothing about a customer needs.
 *
 * No scope parameter. §3.3 grants `import (Excel)` to the Manager alone, whose
 * grant is `All`, and a batch is not a row anyone is filtered out of.
 */
interface ImportBatchesInterface
{
    public function record(
        string $originalFilename,
        int $rowCount,
        int $importedCount,
        int $incompleteCount,
        string $actorId,
    ): ImportSummary;
}
