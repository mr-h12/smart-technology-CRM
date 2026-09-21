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
 * F-05 · 1.3 — the idempotency guard behind `SupplierItemQuantityInterface`.
 * A technical table on `idempotency_keys`' shape (owner's ruling 2026-09-16):
 * id · line · key · quantity · created_at, `UNIQUE (idempotency_key)`, and no
 * `DB-01`/`DB-02` block — it is never edited, never deleted, and its actor is
 * the audited transition that calls it.
 */
final class SupplierQuotationItemConsumptionSchemaMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE = 'supplier_quotation_item_consumptions';

    private const MIGRATION = 'database/migrations/2026_09_16_000200_create_supplier_quotation_item_consumptions.php';

    private const UNIQUE_VIOLATION = '23505';

    private const CHECK_VIOLATION = '23514';

    private const FOREIGN_KEY_VIOLATION = '23503';

    public function test_that_the_table_carries_exactly_the_guard_columns(): void
    {
        self::assertTrue(Schema::hasTable(self::TABLE));
        self::assertSame(
            ['id', 'supplier_quotation_item_id', 'idempotency_key', 'quantity', 'created_at'],
            Schema::getColumnListing(self::TABLE),
        );
    }

    public function test_that_quantity_is_numeric_14_4(): void
    {
        /** @var object{data_type: string, numeric_precision: int, numeric_scale: int}|null $column */
        $column = DB::selectOne(
            'select data_type, numeric_precision, numeric_scale from information_schema.columns where table_name = ? and column_name = ?',
            [self::TABLE, 'quantity'],
        );

        self::assertNotNull($column);
        self::assertSame(['numeric', 14, 4], [$column->data_type, $column->numeric_precision, $column->numeric_scale], 'D-68');
    }

    public function test_that_a_key_is_unique(): void
    {
        $line = $this->line();
        $this->guard($line, 'key-1');

        self::assertSame(self::UNIQUE_VIOLATION, $this->refusedWith(fn () => $this->guard($line, 'key-1')));
    }

    public function test_that_a_non_positive_quantity_is_refused_by_the_table(): void
    {
        $line = $this->line();

        self::assertSame(self::CHECK_VIOLATION, $this->refusedWith(fn () => $this->guard($line, 'key-1', '0')));
    }

    public function test_that_an_unknown_line_is_refused(): void
    {
        self::assertSame(self::FOREIGN_KEY_VIOLATION, $this->refusedWith(fn () => $this->guard(Uuid::uuid4()->toString(), 'key-1')));
    }

    /** `DEV-03`, on the `--path` mechanism 1.2's schema test documents. */
    public function test_that_the_migration_rolls_back_and_forward(): void
    {
        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATION, '--force' => true]));
        self::assertFalse(Schema::hasTable(self::TABLE), 'down() left the table (DEV-03).');

        self::assertSame(0, Artisan::call('migrate', ['--path' => self::MIGRATION, '--force' => true]));
        self::assertTrue(Schema::hasTable(self::TABLE), 'up() did not bring it back.');
    }

    /** @param  callable(): mixed  $write */
    private function refusedWith(callable $write): ?string
    {
        try {
            $write();
        } catch (QueryException $refused) {
            return (string) $refused->getCode();
        }

        return null;
    }

    private function guard(string $lineId, string $key, string $quantity = '1'): void
    {
        DB::table(self::TABLE)->insert([
            'id' => Uuid::uuid4()->toString(), 'supplier_quotation_item_id' => $lineId,
            'idempotency_key' => $key, 'quantity' => $quantity, 'created_at' => now(),
        ]);
    }

    private function line(): string
    {
        $supplierId = Uuid::uuid4()->toString();
        $catalogItemId = Uuid::uuid4()->toString();
        $offerId = Uuid::uuid4()->toString();
        $lineId = Uuid::uuid4()->toString();

        DB::table('suppliers')->insert(['id' => $supplierId, 'name' => 'Alpha Supplies', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('catalog_items')->insert(['id' => $catalogItemId, 'kind' => 'product', 'name' => 'Widget', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('supplier_quotations')->insert([
            'id' => $offerId, 'code' => 'SQ-'.now()->format('Y').'-'.substr($lineId, 0, 4), 'supplier_id' => $supplierId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('supplier_quotation_items')->insert([
            'id' => $lineId, 'supplier_quotation_id' => $offerId, 'catalog_item_id' => $catalogItemId,
            'unit_price' => '100', 'quantity' => '7', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $lineId;
    }
}
