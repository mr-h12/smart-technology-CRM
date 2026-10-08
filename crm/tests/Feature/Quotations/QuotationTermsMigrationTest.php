<?php

declare(strict_types=1);

namespace Tests\Feature\Quotations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use stdClass;
use Tests\Feature\Pdf\InsertsQuotationRows;
use Tests\TestCase;

/**
 * F-37 · 1.2 — `quotations.terms` (`D-103`): a list of `{key, title, body}`
 * that replaces the three term columns and `show_delivery_terms`. The old
 * columns stay, unwritten; the migration copies them in so no existing
 * quotation prints differently — the delivery term only where the flag was on.
 */
final class QuotationTermsMigrationTest extends TestCase
{
    use InsertsQuotationRows;
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_08_000000_add_terms_to_quotations.php';

    public function test_that_the_column_is_jsonb(): void
    {
        $column = DB::selectOne(
            'select is_nullable, data_type from information_schema.columns where table_name = ? and column_name = ?',
            ['quotations', 'terms'],
        );

        self::assertInstanceOf(stdClass::class, $column, 'D-103: the terms live in `quotations.terms`.');
        self::assertSame('jsonb', $column->data_type);
    }

    public function test_that_the_old_term_columns_stay(): void
    {
        foreach (['payment_terms', 'warranty', 'delivery_terms', 'show_delivery_terms'] as $column) {
            self::assertTrue(Schema::hasColumn('quotations', $column), "D-103 keeps `{$column}` — nothing is deleted.");
        }
    }

    public function test_that_the_three_old_terms_are_copied_in_their_order_with_no_title(): void
    {
        $id = $this->beforeTheMigration([
            'payment_terms' => '50% advance',
            'warranty' => 'One year',
            'delivery_terms' => 'Two weeks',
            'show_delivery_terms' => true,
        ]);

        self::assertEquals([
            ['key' => 'payment_terms', 'title' => null, 'body' => '50% advance'],
            ['key' => 'warranty', 'title' => null, 'body' => 'One year'],
            ['key' => 'delivery_terms', 'title' => null, 'body' => 'Two weeks'],
        ], $this->terms($id));
    }

    /** D-103: the delivery term only where `show_delivery_terms` was true, so the PDF prints the same. */
    public function test_that_a_hidden_delivery_term_is_not_copied(): void
    {
        $id = $this->beforeTheMigration([
            'payment_terms' => '50% advance',
            'delivery_terms' => 'Two weeks',
            'show_delivery_terms' => false,
        ]);

        self::assertEquals([
            ['key' => 'payment_terms', 'title' => null, 'body' => '50% advance'],
        ], $this->terms($id));
    }

    public function test_that_blank_old_terms_are_not_copied(): void
    {
        $id = $this->beforeTheMigration(['payment_terms' => '  ', 'warranty' => null]);

        self::assertSame([], $this->terms($id));
    }

    // ────────────────────────────────────────────────────────────────── DEV-03

    public function test_that_the_migration_rolls_back_and_forward(): void
    {
        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATION]));
        self::assertFalse(Schema::hasColumn('quotations', 'terms'), 'down() left `terms` behind.');
        self::assertTrue(Schema::hasColumn('quotations', 'payment_terms'), 'down() took an old column with it.');

        self::assertSame(0, Artisan::call('migrate', ['--path' => self::MIGRATION]));
        self::assertTrue(Schema::hasColumn('quotations', 'terms'), '`terms` did not come back.');
    }

    /**
     * Rolls the migration back, writes one quotation the old way, and runs it again.
     *
     * @param  array<string, mixed>  $old
     */
    private function beforeTheMigration(array $old): string
    {
        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATION]));
        self::assertFalse(Schema::hasColumn('quotations', 'terms'), 'the rollback did not remove `terms`.');

        $id = $this->insertQuotation();
        DB::table('quotations')->where('id', $id)->update($old);

        self::assertSame(0, Artisan::call('migrate', ['--path' => self::MIGRATION]));

        return $id;
    }

    /**
     * The stored list, decoded. `jsonb` keeps its own key order, so callers
     * compare with `assertEquals`, which ignores the order of a term's keys.
     */
    private function terms(string $id): mixed
    {
        $raw = DB::table('quotations')->where('id', $id)->value('terms');
        self::assertIsString($raw);

        return json_decode($raw, true);
    }
}
