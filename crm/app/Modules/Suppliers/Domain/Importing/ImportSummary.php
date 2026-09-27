<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\Domain\Importing;

/**
 * `D-85` (F-09 · 1.4) — what one supplier import produced. The customers' own
 * `ImportSummary` has the same shape; modules do not share classes, and the
 * failures are `rowCount - importedCount`, as there.
 */
final readonly class ImportSummary
{
    /**
     * @param  list<array{row: int, field: string, code: string, message: string}>  $rejected  `D-94`: each row not saved, and why
     * @param  list<int>  $skipped  `D-94`: the duplicates' spreadsheet rows
     */
    public function __construct(
        public string $id,
        public string $originalFilename,
        public int $rowCount,
        public int $importedCount,
        public int $incompleteCount,
        public array $rejected = [],
        public array $skipped = [],
    ) {}
}
