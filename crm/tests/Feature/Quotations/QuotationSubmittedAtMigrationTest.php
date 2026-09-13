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
 * Module 7, Point 4.2 — `quotations.submitted_at` (the owner's Q2 ruling,
 * 2026-09-12): the moment a quotation entered `pending`, which `D-11`'s
 * "days waiting" column needs and §6.2's Tracking group does not list.
 * Nullable, because a draft has not been submitted and a returned one
 * (§6.4, `pending → draft`) is no longer waiting.
 */
final class QuotationSubmittedAtMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_that_the_column_exists_and_starts_empty(): void
    {
        self::assertTrue(Schema::hasColumn('quotations', 'submitted_at'));

        $column = DB::selectOne(
            'select is_nullable, data_type from information_schema.columns where table_name = ? and column_name = ?',
            ['quotations', 'submitted_at'],
        );

        self::assertInstanceOf(stdClass::class, $column);
        self::assertSame('YES', $column->is_nullable, 'a draft has no submission moment.');
        self::assertSame('timestamp with time zone', $column->data_type, '`DB-08`: an instant, stored in UTC like `sent_at`.');
    }

    // ────────────────────────────────────────────────────────────────── DEV-03

    public function test_that_the_migration_rolls_back_and_forward(): void
    {
        Artisan::call('migrate:reset', ['--force' => true]);
        self::assertFalse(Schema::hasTable('quotations'));

        self::assertSame(0, Artisan::call('migrate'));
        self::assertTrue(Schema::hasColumn('quotations', 'submitted_at'), '`submitted_at` did not come back.');
    }
}
