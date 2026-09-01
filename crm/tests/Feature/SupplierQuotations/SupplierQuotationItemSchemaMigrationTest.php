<?php

declare(strict_types=1);

namespace Tests\Feature\SupplierQuotations;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Module 6, Point 1.2 — the `supplier_quotation_items` table.
 *
 * §7.2 gives this table one row rather than a field table of its own:
 *
 *     | Line items | Product · **price** · quantity (+ to add more) |
 *
 * Those three facts are read out of the master documentation below rather than
 * retyped, on `SupplierQuotationSchemaMigrationTest`'s precedent — a fact
 * dropped from both the migration and a hand-written list would pass a check
 * that only agrees with itself. Each is mapped to the column that carries it,
 * because none of the three is spelled the way a column is.
 *
 * All three are `NOT NULL`, which is the same argument §4.1 made for
 * `supplier_quotations.supplier_id` rather than a different rule: a line with
 * no product, no price or no quantity is not a smaller line — it is not the row
 * §7.2 describes. `§5.6` states the price half outright ("Product or price
 * missing at the supplier → **block save**") and `§10.4`'s inline-validation
 * table lists "missing price" in red.
 *
 * What this test does **not** decide is whether the parent's `total_price` is a
 * typed figure or the sum of these rows. That is Step 2's question, still open
 * with the owner, and no constraint here answers it.
 */
final class SupplierQuotationItemSchemaMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MASTER_DOCUMENTATION = '/opt/crm/docs/CRM_Documentation_EN.md';

    private const CHECK_VIOLATION = '23514';

    private const FOREIGN_KEY_VIOLATION = '23503';

    private const NOT_NULL_VIOLATION = '23502';

    /**
     * §7.2's three line-item facts, and the column that carries each. None is
     * spelled the way a column is, so the mapping is stated rather than guessed.
     */
    private const FACT_COLUMNS = [
        'Product' => 'catalog_item_id',
        'price' => 'unit_price',
        'quantity' => 'quantity',
    ];

    private string $quotationId;

    private string $catalogItemId;

    protected function setUp(): void
    {
        parent::setUp();

        $supplierId = Uuid::uuid4()->toString();
        $this->quotationId = Uuid::uuid4()->toString();
        $this->catalogItemId = Uuid::uuid4()->toString();

        DB::table('suppliers')->insert([
            'id' => $supplierId,
            'name' => 'Alpha Supplies',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('supplier_quotations')->insert([
            'id' => $this->quotationId,
            // Twelve hex characters, not the four the thirteen helpers in the
            // debt register use: 'SQ-' + four is 65,536 values and CI has
            // already lost that bet once. `code` is string(20) and 'SQ-2026-'
            // is eight, so twelve is what fits.
            'code' => 'SQ-2026-'.substr(str_replace('-', '', $this->quotationId), -12),
            'supplier_id' => $supplierId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('catalog_items')->insert([
            'id' => $this->catalogItemId,
            'kind' => 'product',
            'name' => 'Split unit 1.5HP',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────── the block

    public function test_that_the_table_exists(): void
    {
        self::assertTrue(Schema::hasTable('supplier_quotation_items'));
    }

    public function test_that_the_table_carries_the_block_section_4_8_requires(): void
    {
        foreach (['created_by', 'created_at', 'updated_by', 'updated_at'] as $column) {
            self::assertTrue(
                Schema::hasColumn('supplier_quotation_items', $column),
                "`supplier_quotation_items` is missing {$column} (DB-02).",
            );
        }

        self::assertTrue(
            Schema::hasColumn('supplier_quotation_items', 'deleted_at'),
            '`supplier_quotation_items` is missing the DB-01 soft delete.',
        );
    }

    /** `DB-07`. This is the table `D-21` says every supplier price lives in. */
    public function test_that_no_column_stores_a_float(): void
    {
        self::assertTrue(Schema::hasTable('supplier_quotation_items'), 'no table, so this check proves nothing.');

        $floats = DB::select(
            'select column_name from information_schema.columns '
            .'where table_name = ? and data_type in (?, ?)',
            ['supplier_quotation_items', 'double precision', 'real'],
        );

        self::assertSame([], $floats, '`supplier_quotation_items` has a float column — DB-07 forbids it.');
    }

    // ──────────────────────────────────────────────── §7.2's three line facts

    public function test_that_every_fact_section_7_2_puts_on_a_line_has_a_column(): void
    {
        $facts = self::documentedLineFacts();

        self::assertSame(
            array_keys(self::FACT_COLUMNS),
            $facts,
            "§7.2's `Line items` row no longer reads `Product · price · quantity` — the mapping below "
            .'is stale and the columns it checks may no longer be the documented ones.',
        );

        foreach ($facts as $fact) {
            self::assertTrue(
                Schema::hasColumn('supplier_quotation_items', self::FACT_COLUMNS[$fact]),
                "§7.2 puts `{$fact}` on every line and `".self::FACT_COLUMNS[$fact].'` is not a column.',
            );
        }
    }

    // ─────────────────────────────────────────────────────────── the two links

    public function test_that_a_line_without_a_quotation_is_refused(): void
    {
        self::assertSame(
            self::NOT_NULL_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['supplier_quotation_id' => null])),
        );
    }

    public function test_that_an_unknown_quotation_is_refused(): void
    {
        self::assertSame(
            self::FOREIGN_KEY_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['supplier_quotation_id' => Uuid::uuid4()->toString()])),
        );
    }

    public function test_that_a_line_without_a_product_is_refused(): void
    {
        self::assertSame(
            self::NOT_NULL_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['catalog_item_id' => null])),
        );
    }

    /** `D-22` adds the missing product to the catalog; it never leaves the line pointing at nothing. */
    public function test_that_an_unknown_product_is_refused(): void
    {
        self::assertSame(
            self::FOREIGN_KEY_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['catalog_item_id' => Uuid::uuid4()->toString()])),
        );
    }

    // ───────────────────────────────────────────────── the price and the count

    /** §5.6: "Product or price missing at the supplier → **block save**". */
    public function test_that_a_line_without_a_price_is_refused(): void
    {
        self::assertSame(
            self::NOT_NULL_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['unit_price' => null])),
        );
    }

    public function test_that_a_negative_price_is_refused(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['unit_price' => '-0.000001'])),
        );
    }

    /** Zero is a price — a supplier throwing an accessory in free is a real offer. */
    public function test_that_a_zero_price_is_accepted(): void
    {
        $id = $this->insert(['unit_price' => '0']);

        self::assertNotNull(DB::table('supplier_quotation_items')->where('id', $id)->value('unit_price'));
    }

    public function test_that_a_line_without_a_quantity_is_refused(): void
    {
        self::assertSame(
            self::NOT_NULL_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['quantity' => null])),
        );
    }

    /** §5.1's `line_total = unit_price × quantity`: a line of nothing totals nothing. */
    public function test_that_a_quantity_of_zero_is_refused(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['quantity' => '0'])),
        );
    }

    /**
     * `D-68`, and the reason `money()` exists instead of `decimal()`: Laravel's
     * default is (8,2), which would store this as 1234.57 and report success.
     */
    public function test_that_a_price_keeps_the_six_decimals_d_68_fixes(): void
    {
        $id = $this->insert(['unit_price' => '1234.567891']);

        self::assertSame(
            '1234.567891',
            DB::table('supplier_quotation_items')->where('id', $id)->value('unit_price'),
        );
    }

    /** §7.3 units include metre and kilo, so a quantity is not an integer. */
    public function test_that_a_fractional_quantity_is_kept(): void
    {
        $id = $this->insert(['quantity' => '2.5000']);

        self::assertSame(
            '2.5000',
            DB::table('supplier_quotation_items')->where('id', $id)->value('quantity'),
        );
    }

    // ─────────────────────────────────────────────────────────────── `DB-09`

    public function test_that_both_joins_are_indexed(): void
    {
        foreach (['supplier_quotation_id', 'catalog_item_id'] as $column) {
            self::assertNotSame(
                [],
                DB::select(
                    'select indexname from pg_indexes where tablename = ? and indexdef like ?',
                    ['supplier_quotation_items', '%('.$column.')%'],
                ),
                "`{$column}` is joined on every read of this table and has no index.",
            );
        }
    }

    // ────────────────────────────────────────────────────────────────── DEV-03

    /**
     * `migrate:reset`, not `migrate:rollback --path` — see this module's Point
     * 1.1 test for why the second does not mean what it reads like.
     */
    public function test_that_the_migration_rolls_back_and_forward(): void
    {
        Artisan::call('migrate:reset', ['--force' => true]);

        self::assertFalse(
            Schema::hasTable('supplier_quotation_items'),
            'down() left `supplier_quotation_items` behind (DEV-03).',
        );

        self::assertSame(0, Artisan::call('migrate'));

        self::assertTrue(Schema::hasTable('supplier_quotation_items'), '`supplier_quotation_items` did not come back.');
    }

    // ───────────────────────────────────────────────────────────────── helpers

    /** @param array<string, mixed> $overrides */
    private function insert(array $overrides = []): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('supplier_quotation_items')->insert(array_merge([
            'id' => $id,
            'supplier_quotation_id' => $this->quotationId,
            'catalog_item_id' => $this->catalogItemId,
            'unit_price' => '1500.000000',
            'quantity' => '3.0000',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $id;
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

    /**
     * The three facts §7.2's `Line items` row names, in its order.
     *
     * @return list<string>
     */
    private static function documentedLineFacts(): array
    {
        self::assertFileExists(
            self::MASTER_DOCUMENTATION,
            'The master documentation is not mounted. This fails rather than skips: without it '
            .'these checks would only be agreeing with the migration that wrote them.',
        );

        $body = (string) file_get_contents(self::MASTER_DOCUMENTATION);

        $start = strpos($body, '### 7.2 Supplier Quotations');
        self::assertIsInt($start, '§7.2 is no longer a heading in the master documentation.');

        $end = strpos($body, '### 7.3', $start);
        self::assertIsInt($end, '§7.3 no longer follows §7.2 — the slice would run to the end of the file.');

        $facts = [];

        foreach (explode("\n", substr($body, $start, $end - $start)) as $line) {
            $cells = explode('|', $line);

            if (trim($cells[1] ?? '') !== 'Line items') {
                continue;
            }

            foreach (explode('·', trim($cells[2] ?? '')) as $fact) {
                // "**price**" and "quantity (+ to add more)" — the emphasis and
                // the UI aside are presentation, not the name of the fact.
                $facts[] = trim(preg_replace('/\*\*|\(.*\)/', '', $fact) ?? '');
            }
        }

        self::assertNotSame([], $facts, "§7.2 no longer has a `Line items` row — this test's subject is gone.");

        return $facts;
    }
}
