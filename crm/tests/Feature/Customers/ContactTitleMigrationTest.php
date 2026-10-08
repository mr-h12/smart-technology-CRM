<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `D-104` (F-38 · 1.2): a customer's contact person carries an optional title
 * from the managed list `contact_titles` (`DB-05`). One migration widens
 * `enum_lists_known_list`, seeds `mr` / `mrs`, and adds `customers.contact_title`.
 */
final class ContactTitleMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_08_100000_add_contact_title_to_customers.php';

    /** The owner's ruling: *Mr.* / *Mrs.* in English, «أ.» for both in Arabic. */
    public function test_that_the_two_titles_are_listed(): void
    {
        $rows = DB::table('enum_lists')
            ->where('list', 'contact_titles')
            ->whereNull('deleted_at')
            ->orderBy('position')
            ->get(['code', 'label_en', 'label_ar'])
            ->map(fn (object $row): array => (array) $row)
            ->all();

        self::assertSame([
            ['code' => 'mr', 'label_en' => 'Mr.', 'label_ar' => 'أ.'],
            ['code' => 'mrs', 'label_en' => 'Mrs.', 'label_ar' => 'أ.'],
        ], $rows);
    }

    /** Ruling 2: optional — the column is nullable, so every existing customer stays valid. */
    public function test_that_the_column_is_a_nullable_string_of_64(): void
    {
        $column = (array) DB::selectOne(
            "select is_nullable, character_maximum_length from information_schema.columns
             where table_name = 'customers' and column_name = 'contact_title'",
        );

        self::assertSame(['is_nullable' => 'YES', 'character_maximum_length' => 64], $column);
    }

    // ────────────────────────────────────────────────────────────── DEV-03

    public function test_that_the_migration_rolls_back_and_forward(): void
    {
        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATION]));

        self::assertFalse(Schema::hasColumn('customers', 'contact_title'), 'down() left the column behind (DEV-03).');
        self::assertSame(0, DB::table('enum_lists')->where('list', 'contact_titles')->count(), 'down() left the titles behind.');

        // A savepoint, so the refused insert does not abort RefreshDatabase's transaction.
        try {
            DB::transaction(fn () => DB::table('enum_lists')->insert([
                'id' => (string) Str::uuid7(),
                'list' => 'contact_titles',
                'code' => 'mr',
                'label_en' => 'Mr.',
                'label_ar' => 'أ.',
                'position' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
            self::fail('down() did not narrow enum_lists_known_list back.');
        } catch (QueryException $e) {
            self::assertSame('23514', $e->getCode());
        }

        self::assertSame(0, Artisan::call('migrate'));

        self::assertTrue(Schema::hasColumn('customers', 'contact_title'), 'The column did not come back.');
        self::assertSame(2, DB::table('enum_lists')->where('list', 'contact_titles')->count(), 'The titles did not come back.');
    }
}
