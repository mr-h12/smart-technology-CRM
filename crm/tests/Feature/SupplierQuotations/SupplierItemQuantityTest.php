<?php

declare(strict_types=1);

namespace Tests\Feature\SupplierQuotations;

use App\Modules\SupplierQuotations\Domain\Contracts\SupplierItemQuantityInterface;
use App\Modules\SupplierQuotations\Infrastructure\EloquentSupplierItemQuantity;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * F-05 · 1.3 — `D-81`: what the accepted customer quotation draws from a
 * supplier line accumulates in `consumed_quantity`, once per idempotency key,
 * in one atomic statement. The caller is Module 10's `sent → accepted`
 * transition, not built yet; this proves the contract it will call.
 */
final class SupplierItemQuantityTest extends TestCase
{
    use RefreshDatabase;

    public function test_that_the_contract_resolves_to_the_eloquent_writer(): void
    {
        self::assertInstanceOf(
            EloquentSupplierItemQuantity::class,
            $this->app->make(SupplierItemQuantityInterface::class),
        );
    }

    public function test_that_consuming_adds_to_the_balance_and_returns_it(): void
    {
        $line = $this->supplierLine('7');

        $after = $this->writer()->consume($line, '2.5', 'key-1');

        self::assertSame('2.5000', $after, 'consume() returns the balance after the write (UPDATE … RETURNING).');
        self::assertSame('2.5000', $this->consumed($line));
    }

    /**
     * The concurrency property, as close as a single process gets: two writers
     * built independently — what two simultaneous transitions get — with the
     * same key must move the balance once. The database decides in one
     * statement (`ON CONFLICT DO NOTHING`), not a read-then-write.
     */
    public function test_that_the_same_key_consumes_once_across_independent_writers(): void
    {
        $line = $this->supplierLine('7');

        $first = $this->writer()->consume($line, '2.5', 'key-1');
        $replay = $this->writer()->consume($line, '2.5', 'key-1');

        self::assertSame('2.5000', $first);
        self::assertSame('2.5000', $replay, 'a replay returns the unchanged balance');
        self::assertSame('2.5000', $this->consumed($line));
        self::assertSame(1, DB::table('supplier_quotation_item_consumptions')->where('idempotency_key', 'key-1')->count());
    }

    public function test_that_two_keys_consume_twice(): void
    {
        $line = $this->supplierLine('7');

        $this->writer()->consume($line, '2.5', 'key-1');
        $after = $this->writer()->consume($line, '1', 'key-2');

        self::assertSame('3.5000', $after);
        self::assertSame('3.5000', $this->consumed($line));
    }

    /** `D-81` / §5.6: no ceiling — exceeding the offer is allowed (and warned about elsewhere). */
    public function test_that_consuming_past_the_recorded_quantity_is_allowed(): void
    {
        $line = $this->supplierLine('7');

        self::assertSame('9.0000', $this->writer()->consume($line, '9', 'key-1'));
    }

    public function test_that_a_non_positive_quantity_is_refused_before_any_write(): void
    {
        $line = $this->supplierLine('7');

        $this->expectException(InvalidArgumentException::class);

        try {
            $this->writer()->consume($line, '0', 'key-1');
        } finally {
            self::assertSame(0, DB::table('supplier_quotation_item_consumptions')->count(), 'nothing was written');
        }
    }

    /**
     * The guard table's FK refuses an unknown line at the insert itself, so
     * nothing is written. (Proved by mutation: this passes with or without the
     * transaction — the FK, not the rollback, is what stops the row. The
     * transaction's own case, a guard row inserted and the update then
     * failing, has no cheap provocation and is not tested.)
     */
    public function test_that_an_unknown_line_is_refused_by_the_guards_foreign_key(): void
    {
        $this->expectException(QueryException::class);

        try {
            $this->writer()->consume(Uuid::uuid4()->toString(), '1', 'key-1');
        } finally {
            self::assertSame(0, DB::table('supplier_quotation_item_consumptions')->count(), 'nothing was written');
        }
    }

    private function writer(): EloquentSupplierItemQuantity
    {
        $writer = $this->app->make(SupplierItemQuantityInterface::class);
        self::assertInstanceOf(EloquentSupplierItemQuantity::class, $writer);

        return $writer;
    }

    private function consumed(string $lineId): string
    {
        /** @var object{consumed_quantity: string} $row */
        $row = DB::table('supplier_quotation_items')->where('id', $lineId)->first(['consumed_quantity']);

        return $row->consumed_quantity;
    }

    /** A priced-or-not offer with one line; the sixth private copy of this fixture in `tests/` — named in the point's waste audit. */
    private function supplierLine(string $quantity): string
    {
        $supplierId = Uuid::uuid4()->toString();
        $catalogItemId = Uuid::uuid4()->toString();
        $offerId = Uuid::uuid4()->toString();
        $lineId = Uuid::uuid4()->toString();

        DB::table('suppliers')->insert(['id' => $supplierId, 'name' => 'Alpha Supplies', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('catalog_items')->insert(['id' => $catalogItemId, 'kind' => 'product', 'name' => 'Widget', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('supplier_quotations')->insert([
            'id' => $offerId, 'code' => 'SQ-'.now()->format('Y').'-'.substr($lineId, 0, 4), 'supplier_id' => $supplierId,
            'total_price' => null, 'currency_id' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('supplier_quotation_items')->insert([
            'id' => $lineId, 'supplier_quotation_id' => $offerId, 'catalog_item_id' => $catalogItemId,
            'unit_price' => '100', 'quantity' => $quantity, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $lineId;
    }
}
