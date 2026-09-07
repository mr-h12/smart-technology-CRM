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
 * Module 7, Point 1.4 — the `quotation_additional_items` table.
 *
 * Four columns, and `OD-01`/`D-62` rest on them. The rule those decisions
 * carry — these lines never enter `tax_base` — is enforced one table up by
 * Point 1.2's `quotations_tax_base_follows_discount`, so the last test here
 * walks both tables together: real additional-item rows, and a parent whose
 * tax base excludes them, then the same parent with them folded in, refused.
 *
 * See the migration's docblock for why `amount >= 0` (a negative additional
 * item is a discount that bypasses `D-07` and lands on the wrong side of the
 * tax base) and why there is no currency column (`D-09`).
 */
final class QuotationAdditionalItemSchemaMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const CHECK_VIOLATION = '23514';

    private const FOREIGN_KEY_VIOLATION = '23503';

    private const NOT_NULL_VIOLATION = '23502';

    private string $quotationId;

    private string $customerId;

    private string $dealId;

    private string $currencyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customerId = $customerId = Uuid::uuid4()->toString();
        $this->dealId = $dealId = Uuid::uuid4()->toString();
        $this->currencyId = $currencyId = Uuid::uuid4()->toString();

        $this->quotationId = Uuid::uuid4()->toString();

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

        $this->insertQuotation($this->quotationId);
    }

    // ─────────────────────────────────────────────────────────────── the block

    public function test_that_the_table_exists(): void
    {
        self::assertTrue(Schema::hasTable('quotation_additional_items'));
    }

    public function test_that_the_table_carries_the_block_section_4_8_requires(): void
    {
        foreach (['created_by', 'created_at', 'updated_by', 'updated_at'] as $column) {
            self::assertTrue(
                Schema::hasColumn('quotation_additional_items', $column),
                "`quotation_additional_items` is missing {$column} (DB-02).",
            );
        }

        self::assertTrue(
            Schema::hasColumn('quotation_additional_items', 'deleted_at'),
            '`quotation_additional_items` is missing the DB-01 soft delete.',
        );
    }

    /** `DB-07`, and `D-68`'s `NUMERIC(18,6)` for the one money column. */
    public function test_that_the_amount_is_decimal_at_the_approved_precision(): void
    {
        $floats = DB::select(
            'select column_name from information_schema.columns '
            .'where table_name = ? and data_type in (?, ?)',
            ['quotation_additional_items', 'double precision', 'real'],
        );

        self::assertSame([], $floats, 'an additional item stores a float — DB-07 forbids it.');

        $this->insert(['amount' => '1234.5678901']);

        self::assertSame('1234.567890', $this->storedValue('amount'));
    }

    /** `D-09`: the quotation has one currency, and a second opinion here could disagree with it. */
    public function test_that_an_additional_item_names_no_currency_of_its_own(): void
    {
        foreach (['currency', 'currency_id', 'amount_currency'] as $column) {
            self::assertFalse(
                Schema::hasColumn('quotation_additional_items', $column),
                "`{$column}` could disagree with the quotation's currency, and nothing would reconcile them.",
            );
        }
    }

    // ──────────────────────────────────────────────────────── §10's four columns

    public function test_that_an_item_without_a_quotation_is_refused(): void
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

    /** The label reaches the customer's PDF, so it is required. */
    public function test_that_an_item_without_a_description_is_refused(): void
    {
        self::assertSame(
            self::NOT_NULL_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['description' => null])),
        );
    }

    public function test_that_a_blank_description_is_not_a_label(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['description' => '   '])),
        );
    }

    public function test_that_an_item_without_an_amount_is_refused(): void
    {
        self::assertSame(
            self::NOT_NULL_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['amount' => null])),
        );
    }

    // ───────────────────────────────────────── `D-07`'s back door, closed

    /**
     * A negative additional item would reduce `net_amount` without touching
     * `discount_percent`, `discount_amount` or the tax base — reintroducing
     * after tax the discount §5.2 subtracts before it.
     */
    public function test_that_a_negative_amount_is_refused(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['amount' => '-0.000001'])),
        );
    }

    /** Free delivery is a real line on a quotation, and it is not a negative one. */
    public function test_that_a_zero_amount_is_allowed(): void
    {
        $this->insert(['description' => 'Delivery (included)', 'amount' => '0']);

        self::assertSame('0.000000', $this->storedValue('amount'));
    }

    // ────────────────────────────────────────────── `OD-01` and `D-62`, end to end

    /**
     * The acceptance row, walked across both tables: items 10,000, delivery
     * 1,000 as a real row here, discount 1%, tax 14%. The tax base is 9,900 —
     * the delivery is in `net_amount` and absent from the base.
     */
    public function test_that_real_delivery_rows_stay_outside_the_tax_base(): void
    {
        $this->insert(['description' => 'Delivery', 'amount' => '600.000000', 'line_no' => 1]);
        $this->insert(['description' => 'Installation', 'amount' => '400.000000', 'line_no' => 2]);

        DB::table('quotations')->where('id', $this->quotationId)->update([
            'subtotal' => '10000.000000',
            'additional_total' => '1000.000000',
            'discount_percent' => '1',
            'discount_amount' => '100.000000',
            'tax_base' => '9900.000000',
            'tax_percent' => '14',
            'tax_amount' => '1386.000000',
            'net_amount' => '10900.000000',
            'total_before_round' => '12286.000000',
            'final_total' => '12286.000000',
        ]);

        $sum = DB::table('quotation_additional_items')->sum('amount');

        self::assertIsString($sum);
        self::assertSame(
            '1000.000000',
            $sum,
            'the two rows do not sum to the parent additional_total the test then stores.',
        );
    }

    /**
     * The same parent with the delivery folded into the base — the overcharge
     * `OD-01` was opened to prevent. Point 1.2's constraint is what refuses it,
     * and this walks the whole path to prove the two tables agree.
     */
    public function test_that_taxing_those_delivery_rows_is_refused(): void
    {
        $this->insert(['description' => 'Delivery', 'amount' => '1000.000000']);

        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => DB::table('quotations')->where('id', $this->quotationId)->update([
                'subtotal' => '10000.000000',
                'additional_total' => '1000.000000',
                'discount_percent' => '1',
                'discount_amount' => '100.000000',
                'tax_base' => '10900.000000',
                'tax_percent' => '14',
                'tax_amount' => '1526.000000',
                'net_amount' => '10900.000000',
                'total_before_round' => '12426.000000',
                'final_total' => '12426.000000',
            ])),
        );
    }

    // ──────────────────────────────────────────────────────────── the indexes

    public function test_that_the_indexes_exist_and_exclude_archived_rows(): void
    {
        foreach (
            [
                'quotation_additional_items_by_quotation' => "a quotation's additional lines",
                'quotation_additional_items_in_order' => '§10 display order',
            ] as $index => $serves
        ) {
            self::assertStringContainsString(
                'WHERE (deleted_at IS NULL)',
                self::indexDefinition($index),
                "{$serves}: `{$index}` is missing or counts archived rows.",
            );
        }
    }

    // ────────────────────────────────────────────────────────────────── DEV-03

    public function test_that_the_migration_rolls_back_and_forward(): void
    {
        Artisan::call('migrate:reset', ['--force' => true]);
        self::assertFalse(
            Schema::hasTable('quotation_additional_items'),
            'down() left `quotation_additional_items` behind (DEV-03).',
        );

        self::assertSame(0, Artisan::call('migrate'));
        self::assertTrue(
            Schema::hasTable('quotation_additional_items'),
            '`quotation_additional_items` did not come back.',
        );
    }

    // ───────────────────────────────────────────────────────────────── helpers

    /** @param array<string, mixed> $overrides */
    private function insert(array $overrides = []): void
    {
        DB::table('quotation_additional_items')->insert(array_merge([
            'id' => Uuid::uuid4()->toString(),
            'quotation_id' => $this->quotationId,
            'description' => 'Delivery',
            'amount' => '1000.000000',
            'line_no' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function insertQuotation(string $id): void
    {
        DB::table('quotations')->insert([
            'id' => $id,
            'code' => 'QT-2026-'.substr(str_replace('-', '', $id), -4),
            'deal_id' => $this->dealId,
            'customer_id' => $this->customerId,
            'currency_id' => $this->currencyId,
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

    private function storedValue(string $column): string
    {
        $value = DB::table('quotation_additional_items')->value($column);

        self::assertIsString($value, "`{$column}` did not come back as a string.");

        return $value;
    }

    private static function indexDefinition(string $index): string
    {
        $definition = DB::table('pg_indexes')->where('indexname', $index)->value('indexdef');

        self::assertIsString($definition, "`{$index}` does not exist.");

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
