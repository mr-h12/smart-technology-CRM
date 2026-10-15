<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `D-123` (F-50 · 4) — `customers.status_reason`: the reason a status was set
 * by hand, `null` while the status is derived. Its presence is what marks the
 * status as set by hand.
 */
final class CustomerStatusReasonMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_15_200000_add_status_reason_to_customers.php';

    public function test_that_the_column_is_a_nullable_string_of_1000(): void
    {
        $column = (array) DB::selectOne(
            "select is_nullable, character_maximum_length from information_schema.columns
             where table_name = 'customers' and column_name = 'status_reason'",
        );

        self::assertSame(['is_nullable' => 'YES', 'character_maximum_length' => 1000], $column);
    }

    // ────────────────────────────────────────────────────────────── DEV-03

    public function test_that_the_migration_rolls_back_and_forward(): void
    {
        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATION]));
        self::assertFalse(Schema::hasColumn('customers', 'status_reason'), 'down() left status_reason behind (DEV-03).');

        self::assertSame(0, Artisan::call('migrate'));
        self::assertTrue(Schema::hasColumn('customers', 'status_reason'), 'status_reason did not come back.');
    }
}
