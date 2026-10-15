<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `D-121` (F-50 · 2): a customer carries five optional links — the location,
 * the website and three free social links — each at most 2048 characters.
 */
final class CustomerLinksMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_15_000000_add_links_to_customers.php';

    private const COLUMNS = ['location_url', 'website_url', 'social_url_1', 'social_url_2', 'social_url_3'];

    /** Optional, so every existing customer stays valid; 2048 is `D-121`'s ceiling. */
    public function test_that_the_five_columns_are_nullable_strings_of_2048(): void
    {
        foreach (self::COLUMNS as $name) {
            $column = (array) DB::selectOne(
                "select is_nullable, character_maximum_length from information_schema.columns
                 where table_name = 'customers' and column_name = ?",
                [$name],
            );

            self::assertSame(['is_nullable' => 'YES', 'character_maximum_length' => 2048], $column, $name);
        }
    }

    // ────────────────────────────────────────────────────────────── DEV-03

    public function test_that_the_migration_rolls_back_and_forward(): void
    {
        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATION]));

        foreach (self::COLUMNS as $name) {
            self::assertFalse(Schema::hasColumn('customers', $name), "down() left {$name} behind (DEV-03).");
        }

        self::assertSame(0, Artisan::call('migrate'));

        foreach (self::COLUMNS as $name) {
            self::assertTrue(Schema::hasColumn('customers', $name), "{$name} did not come back.");
        }
    }
}
