<?php

declare(strict_types=1);

namespace Tests\Feature\SupplierQuotations;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;
use stdClass;
use Tests\TestCase;

/**
 * F-39 · 1.3 — `D-105`: a supplier quotation flagged tax-inclusive. The flag,
 * the rate captured when it was saved, and the amounts as entered beside the
 * net `total_price` / `unit_price` every reader downstream keeps using.
 */
final class SupplierQuotationTaxInclusiveMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_09_000000_add_tax_inclusive_to_supplier_quotations.php';

    private const CHECK_VIOLATION = '23514';

    private string $supplierId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplierId = Uuid::uuid4()->toString();

        DB::table('suppliers')->insert([
            'id' => $this->supplierId,
            'name' => 'Alpha Supplies',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** D-105: "one flag for the whole offer … off by default". */
    public function test_that_the_flag_is_a_boolean_off_by_default(): void
    {
        $column = $this->column('supplier_quotations', 'prices_include_tax');

        self::assertSame('boolean', $column->data_type);
        self::assertSame('NO', $column->is_nullable);
        self::assertSame('false', $column->column_default);

        $id = $this->insert();

        self::assertFalse(DB::table('supplier_quotations')->where('id', $id)->value('prices_include_tax'));
    }

    /** D-105 + D-68: the captured rate is a percent, NUMERIC(6,3), like every other percent. */
    public function test_that_the_captured_rate_is_a_nullable_percent(): void
    {
        $this->assertNumeric('supplier_quotations', 'included_tax_percent', 6, 3);
    }

    /** D-105: "the amounts as entered are stored beside them", at money scale (D-68). */
    public function test_that_the_entered_amounts_are_nullable_money(): void
    {
        $this->assertNumeric('supplier_quotations', 'entered_total_price', 18, 6);
        $this->assertNumeric('supplier_quotation_items', 'entered_unit_price', 18, 6);
    }

    /** The CHECK tying the rate to the flag: a flagged offer always knows its rate. */
    public function test_that_a_flagged_offer_without_a_rate_is_refused(): void
    {
        self::assertSame(self::CHECK_VIOLATION, $this->refusedWith(fn () => $this->insert([
            'prices_include_tax' => true,
            'included_tax_percent' => null,
        ])));
    }

    /** …and an offer taken as recorded (D-62) carries no rate to be misread. */
    public function test_that_a_rate_without_the_flag_is_refused(): void
    {
        self::assertSame(self::CHECK_VIOLATION, $this->refusedWith(fn () => $this->insert([
            'prices_include_tax' => false,
            'included_tax_percent' => '14.000',
        ])));
    }

    public function test_that_a_flagged_offer_with_its_rate_is_accepted(): void
    {
        $id = $this->insert(['prices_include_tax' => true, 'included_tax_percent' => '14.000']);

        self::assertSame('14.000', DB::table('supplier_quotations')->where('id', $id)->value('included_tax_percent'));
    }

    // ────────────────────────────────────────────────────────────────── DEV-03

    public function test_that_the_migration_rolls_back_and_forward(): void
    {
        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATION, '--force' => true]));

        foreach ([
            ['supplier_quotations', 'prices_include_tax'],
            ['supplier_quotations', 'included_tax_percent'],
            ['supplier_quotations', 'entered_total_price'],
            ['supplier_quotation_items', 'entered_unit_price'],
        ] as [$table, $name]) {
            self::assertFalse(Schema::hasColumn($table, $name), "down() left `{$table}.{$name}` behind (DEV-03).");
        }

        self::assertSame(0, Artisan::call('migrate', ['--path' => self::MIGRATION, '--force' => true]));
        self::assertTrue(Schema::hasColumn('supplier_quotations', 'prices_include_tax'), 'up() did not bring the flag back.');
    }

    // ───────────────────────────────────────────────────────────────── helpers

    private function column(string $table, string $name): stdClass
    {
        $column = DB::selectOne(
            'select data_type, is_nullable, column_default, numeric_precision, numeric_scale '
            .'from information_schema.columns where table_name = ? and column_name = ?',
            [$table, $name],
        );

        self::assertInstanceOf(stdClass::class, $column, "`{$table}.{$name}` is not a column (D-105).");

        return $column;
    }

    private function assertNumeric(string $table, string $name, int $precision, int $scale): void
    {
        $column = $this->column($table, $name);

        self::assertSame('numeric', $column->data_type, 'DB-07: money and percents are never floats.');
        self::assertSame($precision, $column->numeric_precision);
        self::assertSame($scale, $column->numeric_scale);
        self::assertSame('YES', $column->is_nullable, 'An offer taken as recorded has nothing here.');
    }

    /** @param array<string, mixed> $overrides */
    private function insert(array $overrides = []): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('supplier_quotations')->insert(array_merge([
            'id' => $id,
            'code' => 'SQ-2026-'.substr(str_replace('-', '', $id), -4),
            'supplier_id' => $this->supplierId,
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
}
