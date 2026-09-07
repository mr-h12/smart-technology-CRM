<?php

declare(strict_types=1);

namespace Tests\Feature\Quotations;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Module 7, Point 1.3 — the `quotation_items` table.
 *
 * The three columns worth testing hardest are the three `design/DATABASE.md`
 * §10 records as previously omitted and specification-required:
 * `supplier_quotation_item_id`, the captured FX rate, and the source currency.
 * Each is asserted for the property that makes it load-bearing, not merely for
 * existing.
 *
 * See the migration's docblock for why `margin_percent` is nullable and must
 * never gain a zero default (`D-03`), and why no CHECK asserts §5.1's
 * arithmetic (every formula there is multiplicative — Point 1.2's reasoning).
 */
final class QuotationItemSchemaMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const CHECK_VIOLATION = '23514';

    private const FOREIGN_KEY_VIOLATION = '23503';

    private const NOT_NULL_VIOLATION = '23502';

    private string $quotationId;

    private string $supplierLineId;

    protected function setUp(): void
    {
        parent::setUp();

        $customerId = Uuid::uuid4()->toString();
        $dealId = Uuid::uuid4()->toString();
        $currencyId = Uuid::uuid4()->toString();
        $supplierId = Uuid::uuid4()->toString();
        $catalogItemId = Uuid::uuid4()->toString();
        $supplierQuotationId = Uuid::uuid4()->toString();

        $this->quotationId = Uuid::uuid4()->toString();
        $this->supplierLineId = Uuid::uuid4()->toString();

        DB::table('customers')->insert([
            'id' => $customerId,
            'name' => 'Nile Trading',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('currencies')->insert([
            'id' => $currencyId,
            'code' => 'EGP',
            'rounding_unit' => '1',
            'rounding_enabled' => true,
            'is_base' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('deals')->insert([
            'id' => $dealId,
            'code' => 'DL-2026-0001',
            'customer_id' => $customerId,
            'last_activity_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('suppliers')->insert([
            'id' => $supplierId,
            'name' => 'Alpha Supplies',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('catalog_items')->insert([
            'id' => $catalogItemId,
            'kind' => 'product',
            'name' => 'Switch 24-port',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('supplier_quotations')->insert([
            'id' => $supplierQuotationId,
            'code' => 'SQ-2026-0001',
            'supplier_id' => $supplierId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('supplier_quotation_items')->insert([
            'id' => $this->supplierLineId,
            'supplier_quotation_id' => $supplierQuotationId,
            'catalog_item_id' => $catalogItemId,
            'unit_price' => '1000.000000',
            'quantity' => '5.0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('quotations')->insert([
            'id' => $this->quotationId,
            'code' => 'QT-2026-0001',
            'deal_id' => $dealId,
            'customer_id' => $customerId,
            'currency_id' => $currencyId,
            'default_margin' => '20',
            'discount_percent' => '0',
            'rounding_unit' => '1',
            'rounding_enabled' => true,
            'subtotal' => '0',
            'additional_total' => '0',
            'discount_amount' => '0',
            'tax_base' => '0',
            'net_amount' => '0',
            'total_before_round' => '0',
            'final_total' => '0',
            'rounding_diff' => '0',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────── the block

    public function test_that_the_table_exists(): void
    {
        self::assertTrue(Schema::hasTable('quotation_items'));
    }

    public function test_that_the_table_carries_the_block_section_4_8_requires(): void
    {
        foreach (['created_by', 'created_at', 'updated_by', 'updated_at'] as $column) {
            self::assertTrue(
                Schema::hasColumn('quotation_items', $column),
                "`quotation_items` is missing {$column} (DB-02).",
            );
        }

        self::assertTrue(
            Schema::hasColumn('quotation_items', 'deleted_at'),
            '`quotation_items` is missing the DB-01 soft delete.',
        );
    }

    /** `DB-07`. Every figure in §5.1 is money or a quantity. */
    public function test_that_no_column_stores_a_float(): void
    {
        self::assertTrue(Schema::hasTable('quotation_items'), 'no table, so this check proves nothing.');

        $floats = DB::select(
            'select column_name from information_schema.columns '
            .'where table_name = ? and data_type in (?, ?)',
            ['quotation_items', 'double precision', 'real'],
        );

        self::assertSame([], $floats, '`quotation_items` has a float column — DB-07 forbids it.');
    }

    // ──────────────────────────────────────────── §5.6's quartet (`DB-06`)

    public function test_that_the_unit_cost_carries_its_full_context(): void
    {
        foreach (
            ['unit_cost', 'unit_cost_currency', 'unit_cost_fx_rate_at_time', 'unit_cost_base'] as $column
        ) {
            self::assertTrue(
                Schema::hasColumn('quotation_items', $column),
                "DB-06 and §5.6 require `{$column}` — an amount without its context cannot be reproduced.",
            );
        }
    }

    /** `D-09`: the rate is fixed at creation, so it has to have been stored. */
    public function test_that_a_line_without_its_captured_rate_is_refused(): void
    {
        self::assertSame(
            self::NOT_NULL_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['unit_cost_fx_rate_at_time' => null])),
        );
    }

    public function test_that_a_zero_rate_is_refused(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['unit_cost_fx_rate_at_time' => '0'])),
        );
    }

    /**
     * The source currency is a snapshot, so it takes no foreign key —
     * deliberately, and asserted so it does not read as a `DB-04` oversight.
     * An archived code may be reassigned under `currencies_code_unique_alive`,
     * and a key would let an issued quotation's source currency be redefined.
     */
    public function test_that_the_source_currency_is_a_snapshot_not_a_reference(): void
    {
        $keys = DB::select(
            'select conname from pg_constraint where conrelid = to_regclass(?) '
            ."and contype = 'f' and pg_get_constraintdef(oid) like ?",
            ['quotation_items', '%unit_cost_currency%'],
        );

        self::assertSame([], $keys, 'the snapshot currency gained a foreign key — see the migration docblock.');

        $this->insert(['unit_cost_currency' => 'XYZ']);

        self::assertSame('XYZ', $this->storedValue('unit_cost_currency'));
    }

    // ────────────────────────────────────────── `D-03`'s three margin states

    /** NULL means *inherit the quotation's margin*. It is not zero. */
    public function test_that_a_line_may_have_no_margin_of_its_own(): void
    {
        $this->insert(['margin_percent' => null]);

        self::assertNull(DB::table('quotation_items')->value('margin_percent'));
    }

    /** Sold at cost is a real instruction, and it must not look like "inherit". */
    public function test_that_a_zero_line_margin_is_distinct_from_no_margin(): void
    {
        $this->insert(['margin_percent' => '0']);

        self::assertSame('0.000', $this->storedValue('margin_percent'));
    }

    /** The acceptance row "quotation margin 20%, line margin 30% → line uses 30%". */
    public function test_that_a_line_margin_overrides_the_quotation_margin(): void
    {
        $this->insert(['margin_percent' => '30']);

        $quotationMargin = DB::table('quotations')->value('default_margin');

        self::assertIsString($quotationMargin);
        self::assertSame('20.000', $quotationMargin);
        self::assertSame('30.000', $this->storedValue('margin_percent'));
    }

    /** §5.1 permits selling below cost; nothing in the sources forbids it. */
    public function test_that_a_negative_line_margin_is_allowed(): void
    {
        $this->insert(['margin_percent' => '-5']);

        self::assertSame('-5.000', $this->storedValue('margin_percent'));
    }

    // ─────────────────────────────────────────────────────────── the bounds

    public function test_that_a_line_for_no_quantity_is_refused(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['quantity' => '0'])),
        );
    }

    public function test_that_a_negative_quantity_is_refused(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['quantity' => '-1'])),
        );
    }

    public function test_that_a_negative_unit_cost_is_refused(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['unit_cost' => '-0.000001'])),
        );
    }

    /** A supplier may quote zero — that is a free item, not an invalid one. */
    public function test_that_a_zero_unit_cost_is_allowed(): void
    {
        $this->insert(['unit_cost' => '0', 'unit_cost_base' => '0']);

        self::assertSame('0.000000', $this->storedValue('unit_cost'));
    }

    public function test_that_a_negative_unit_price_is_refused(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['unit_price' => '-1'])),
        );
    }

    // ─────────────────────────────────────────── §5.6's block-save, in schema

    /**
     * §5.6: "Product or price missing at the supplier → block save." A
     * nullable column would be the schema holding open a state the
     * specification closes, so §10.3's price-change warning stays possible.
     */
    public function test_that_a_line_without_a_supplier_line_is_refused(): void
    {
        self::assertSame(
            self::NOT_NULL_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['supplier_quotation_item_id' => null])),
        );
    }

    public function test_that_an_unknown_supplier_line_is_refused(): void
    {
        self::assertSame(
            self::FOREIGN_KEY_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['supplier_quotation_item_id' => Uuid::uuid4()->toString()])),
        );
    }

    public function test_that_a_line_without_a_quotation_is_refused(): void
    {
        self::assertSame(
            self::NOT_NULL_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['quotation_id' => null])),
        );
    }

    public function test_that_an_unknown_quotation_is_refused(): void
    {
        self::assertSame(
            self::FOREIGN_KEY_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['quotation_id' => Uuid::uuid4()->toString()])),
        );
    }

    /** The product is reached through the supplier line; a second path could disagree with it. */
    public function test_that_the_line_does_not_name_the_product_twice(): void
    {
        self::assertFalse(
            Schema::hasColumn('quotation_items', 'catalog_item_id'),
            'a second path to the product lets a line and its supplier line name different items.',
        );
    }

    // ──────────────────────────────────────────────────────── design §13

    public function test_that_the_indexes_section_13_names_exist(): void
    {
        foreach (
            [
                'quotation_items_by_quotation' => "a quotation's lines",
                'quotation_items_by_supplier_line' => "§10.3's price-change warning, scanned the other way",
                'quotation_items_in_order' => '§10 stable ordering',
            ] as $index => $serves
        ) {
            self::assertStringContainsString(
                'WHERE (deleted_at IS NULL)',
                self::indexDefinition($index),
                "design §13 names {$serves} and `{$index}` is missing or counts archived rows.",
            );
        }
    }

    /**
     * Deliberately not unique: `DB-01` forces a partial unique index, a partial
     * unique index cannot be DEFERRABLE, and swapping two lines' numbers would
     * then need a temporary value. Stated so a reorder endpoint in Step 3 does
     * not fight a constraint that was never required.
     */
    public function test_that_two_lines_may_share_a_position_while_being_reordered(): void
    {
        $this->insert(['line_no' => 1]);
        $this->insert(['line_no' => 1]);

        self::assertSame(2, DB::table('quotation_items')->where('line_no', 1)->count());
    }

    // ────────────────────────────────────────────────────────────────── DEV-03

    public function test_that_the_migration_rolls_back_and_forward(): void
    {
        Artisan::call('migrate:reset', ['--force' => true]);
        self::assertFalse(Schema::hasTable('quotation_items'), 'down() left `quotation_items` behind (DEV-03).');

        self::assertSame(0, Artisan::call('migrate'));
        self::assertTrue(Schema::hasTable('quotation_items'), '`quotation_items` did not come back.');
    }

    // ───────────────────────────────────────────────────────────────── helpers

    /** @param array<string, mixed> $overrides */
    private function insert(array $overrides = []): void
    {
        DB::table('quotation_items')->insert(array_merge([
            'id' => Uuid::uuid4()->toString(),
            'quotation_id' => $this->quotationId,
            'supplier_quotation_item_id' => $this->supplierLineId,
            'line_no' => 1,
            'unit_cost' => '1000.000000',
            'unit_cost_currency' => 'USD',
            'unit_cost_fx_rate_at_time' => '48.50000000',
            'unit_cost_base' => '48500.000000',
            'margin_percent' => '20',
            'unit_price' => '58200.000000',
            'quantity' => '5.0000',
            'line_total' => '291000.000000',
            'line_cost' => '242500.000000',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function storedValue(string $column): string
    {
        $value = DB::table('quotation_items')->value($column);

        self::assertIsString($value, "`{$column}` did not come back as a string.");

        return $value;
    }

    private static function indexDefinition(string $index): string
    {
        $definition = DB::table('pg_indexes')->where('indexname', $index)->value('indexdef');

        self::assertIsString($definition, "`{$index}` does not exist on `quotation_items`.");

        return $definition;
    }

    private function refusedWith(callable $write): string
    {
        try {
            $write();
        } catch (QueryException $exception) {
            $state = $exception->errorInfo[0] ?? null;

            self::assertIsString($state);

            return $state;
        }

        self::fail('The database accepted a row it should have refused.');
    }
}
