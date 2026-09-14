<?php

declare(strict_types=1);

namespace Tests\Feature\Quotations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use stdClass;
use Tests\TestCase;

/**
 * `returned_at` and `return_note` on `quotations` — Module 8 Point 1.2, the
 * owner's Q2 ruling (#131): a return moves the same row `pending → draft`
 * and records when and why, so §8's "incomplete" bucket (2.2) and the
 * detail (2.1) can read it without the audit table.
 */
final class QuotationReturnColumnsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_that_the_columns_exist_and_start_empty(): void
    {
        foreach (['returned_at' => 'timestamp with time zone', 'return_note' => 'text'] as $name => $type) {
            self::assertTrue(Schema::hasColumn('quotations', $name), $name);

            $column = DB::selectOne(
                'select is_nullable, data_type from information_schema.columns where table_name = ? and column_name = ?',
                ['quotations', $name],
            );

            self::assertInstanceOf(stdClass::class, $column);
            self::assertSame('YES', $column->is_nullable, 'a quotation never returned carries neither.');
            self::assertSame($type, $column->data_type, $name);
        }
    }

    // ────────────────────────────────────────────────────────────────── DEV-03

    public function test_that_the_migration_rolls_back_and_forward(): void
    {
        Artisan::call('migrate:reset', ['--force' => true]);
        self::assertFalse(Schema::hasTable('quotations'));

        self::assertSame(0, Artisan::call('migrate'));
        self::assertTrue(Schema::hasColumn('quotations', 'returned_at'), '`returned_at` did not come back.');
        self::assertTrue(Schema::hasColumn('quotations', 'return_note'), '`return_note` did not come back.');
    }
}
