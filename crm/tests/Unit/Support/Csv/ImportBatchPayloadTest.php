<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Csv;

use App\Support\Csv\ImportBatchPayload;
use PHPUnit\Framework\TestCase;

/**
 * F-10 · 1.2 — the one wire shape of an import's result, shared by
 * `POST /customers/import` and `POST /suppliers/import` (`D-85`, `D-86`).
 */
final class ImportBatchPayloadTest extends TestCase
{
    /** F-20 · 1.1 (`D-94`): the rows it rejected and skipped follow the counts, as given. */
    public function test_that_the_counts_are_followed_by_the_rejected_and_skipped_rows(): void
    {
        $rejected = [['row' => 3, 'field' => 'name', 'code' => 'required', 'message' => 'The name field is required.']];

        self::assertSame([
            'id' => 'batch-1',
            'original_filename' => 'rows.csv',
            'row_count' => 6,
            'imported_count' => 3,
            'incomplete_count' => 1,
            'rejected' => $rejected,
            'skipped' => [5, 6],
        ], ImportBatchPayload::of('batch-1', 'rows.csv', 6, 3, 1, $rejected, [5, 6]));
    }
}
