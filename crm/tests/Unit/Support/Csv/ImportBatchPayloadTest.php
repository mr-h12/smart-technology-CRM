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
    public function test_that_the_five_keys_carry_the_counts_in_order(): void
    {
        self::assertSame([
            'id' => 'batch-1',
            'original_filename' => 'rows.csv',
            'row_count' => 6,
            'imported_count' => 3,
            'incomplete_count' => 1,
        ], ImportBatchPayload::of('batch-1', 'rows.csv', 6, 3, 1));
    }
}
