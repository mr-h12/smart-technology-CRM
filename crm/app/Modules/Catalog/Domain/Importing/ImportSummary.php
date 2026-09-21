<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Importing;

/**
 * `D-86` (F-10 · 1.5) — what one catalog import produced. Suppliers' and
 * Customers' own `ImportSummary` have the same shape; each module's `Domain`
 * names its own (F-10 · 1.2's ruling, the debt row's stated ceiling).
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
