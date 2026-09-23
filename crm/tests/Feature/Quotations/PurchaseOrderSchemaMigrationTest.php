<?php

declare(strict_types=1);

namespace Tests\Feature\Quotations;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Module 10 · 1.6 — `purchase_orders` (§4.6, `D-12`, `D-53`): one per accepted
 * quotation while alive, its own `PO-` number, the customer's reference and
 * date, `DB-01`/`DB-02`'s block. The migration also closes the key
 * `purchase_order_files` has owed since Module 0 (`FilesMigrationTest`).
 */
final class PurchaseOrderSchemaMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE = 'purchase_orders';

    private const MIGRATION = 'database/migrations/2026_09_23_000000_create_purchase_orders.php';

    private const UNIQUE_VIOLATION = '23505';

    private const CHECK_VIOLATION = '23514';

    private const FOREIGN_KEY_VIOLATION = '23503';

    private int $currencies = 0;

    public function test_that_the_table_carries_the_documented_columns_and_the_standard_block(): void
    {
        self::assertTrue(Schema::hasTable(self::TABLE));
        self::assertEqualsCanonicalizing(
            [
                'id', 'quotation_id', 'po_number', 'customer_po_reference', 'po_date',
                'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at',
            ],
            Schema::getColumnListing(self::TABLE),
        );
    }

    public function test_that_a_quotation_has_one_alive_purchase_order(): void
    {
        $quotation = $this->quotation();
        $first = $this->order($quotation, 'PO-2026-0001');

        self::assertSame(self::UNIQUE_VIOLATION, $this->refusedWith(fn () => $this->order($quotation, 'PO-2026-0002')));

        DB::table(self::TABLE)->where('id', $first)->update(['deleted_at' => now()]);
        $this->order($quotation, 'PO-2026-0003');
        self::assertSame(2, DB::table(self::TABLE)->where('quotation_id', $quotation)->count());
    }

    public function test_that_a_po_number_is_unique(): void
    {
        $this->order($this->quotation(), 'PO-2026-0001');

        self::assertSame(self::UNIQUE_VIOLATION, $this->refusedWith(fn () => $this->order($this->quotation(), 'PO-2026-0001')));
    }

    public function test_that_a_blank_customer_reference_is_refused_by_the_table(): void
    {
        self::assertSame(self::CHECK_VIOLATION, $this->refusedWith(fn () => $this->order($this->quotation(), 'PO-2026-0001', '   ')));
    }

    public function test_that_an_unknown_quotation_is_refused(): void
    {
        self::assertSame(self::FOREIGN_KEY_VIOLATION, $this->refusedWith(fn () => $this->order(Uuid::uuid4()->toString(), 'PO-2026-0001')));
    }

    /** The debt Module 0 recorded: the pivot's column now references the table. */
    public function test_that_a_file_pivot_row_needs_a_real_purchase_order(): void
    {
        $file = Uuid::uuid7()->toString();
        DB::table('files')->insert([
            'id' => $file, 'original_name' => 'po.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1024,
            'storage_path' => '2026/09/purchase-orders/'.$file.'.pdf', 'scan_status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        self::assertSame(self::FOREIGN_KEY_VIOLATION, $this->refusedWith(fn () => DB::table('purchase_order_files')->insert([
            'purchase_order_id' => Uuid::uuid4()->toString(), 'file_id' => $file,
        ])));
    }

    /** `DEV-03`, on the `--path` mechanism the other schema tests use. */
    public function test_that_the_migration_rolls_back_and_forward(): void
    {
        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATION, '--force' => true]));
        self::assertFalse(Schema::hasTable(self::TABLE), 'down() left the table (DEV-03).');

        self::assertSame(0, Artisan::call('migrate', ['--path' => self::MIGRATION, '--force' => true]));
        self::assertTrue(Schema::hasTable(self::TABLE), 'up() did not bring it back.');
    }

    /**
     * The write runs in its own savepoint, so a refusal leaves the test's
     * transaction usable for the next statement (PostgreSQL aborts it otherwise).
     *
     * @param  Closure(): mixed  $write
     */
    private function refusedWith(Closure $write): ?string
    {
        try {
            DB::transaction($write);
        } catch (QueryException $refused) {
            return (string) $refused->getCode();
        }

        return null;
    }

    private function order(string $quotationId, string $poNumber, string $reference = '4500123987'): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table(self::TABLE)->insert([
            'id' => $id, 'quotation_id' => $quotationId, 'po_number' => $poNumber,
            'customer_po_reference' => $reference, 'po_date' => '2026-09-23',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** An `accepted` quotation row, on `EloquentQuotationDirectoryListTest`'s direct insert. */
    private function quotation(): string
    {
        $customer = Uuid::uuid4()->toString();
        $deal = Uuid::uuid4()->toString();
        $currency = Uuid::uuid4()->toString();
        $id = Uuid::uuid4()->toString();

        DB::table('customers')->insert(['id' => $customer, 'name' => 'Nile Trading', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('deals')->insert([
            'id' => $deal, 'code' => 'DL-2026-'.substr($deal, 0, 4), 'customer_id' => $customer,
            'last_activity_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('currencies')->insert([
            'id' => $currency, 'code' => 'XA'.chr(65 + $this->currencies++), 'rounding_unit' => '1', 'rounding_enabled' => false,
            'is_base' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('quotations')->insert([
            'id' => $id, 'code' => 'QT-2026-'.substr($id, 0, 4), 'deal_id' => $deal, 'customer_id' => $customer,
            'currency_id' => $currency, 'status' => 'accepted', 'quotation_date' => '2026-09-23',
            'default_margin' => '0', 'discount_percent' => '0', 'rounding_unit' => '1', 'rounding_enabled' => false,
            'subtotal' => '10', 'additional_total' => '0', 'discount_amount' => '0', 'tax_base' => '10',
            'net_amount' => '10', 'total_before_round' => '10', 'final_total' => '10', 'rounding_diff' => '0',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}
