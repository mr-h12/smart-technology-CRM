<?php

declare(strict_types=1);

namespace Tests\Feature\SupplierQuotations;

use App\Modules\SupplierQuotations\Domain\Contracts\SupplierItemPricingInterface;
use App\Modules\SupplierQuotations\Infrastructure\EloquentSupplierItemPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Module 7, Point 3.3 — the price read §5.6 forces on customer quotations,
 * over `supplier_quotation_items`.
 */
final class SupplierItemPricingTest extends TestCase
{
    use RefreshDatabase;

    private string $currencyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currencyId = Uuid::uuid4()->toString();
        DB::table('currencies')->insert([
            'id' => $this->currencyId, 'code' => 'USD', 'rounding_unit' => '0.01',
            'rounding_enabled' => true, 'is_base' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_that_the_contract_resolves_to_the_eloquent_reader(): void
    {
        self::assertInstanceOf(
            EloquentSupplierItemPricing::class,
            $this->app->make(SupplierItemPricingInterface::class),
        );
    }

    public function test_that_a_present_line_returns_its_price_currency_and_recorded_quantity(): void
    {
        $line = $this->supplierLine('1250.500000', '7', $this->currencyId);

        $price = $this->reader()->priceFor($line);

        self::assertNotNull($price);
        self::assertSame('1250.500000', $price->unitPrice);
        self::assertSame($this->currencyId, $price->currencyId);
        // `quantity` is NUMERIC(_,4) — read back at its own scale, not money's.
        self::assertSame('7.0000', $price->recordedQuantity);
        // D-81: nothing consumed yet, so the whole offer is available.
        self::assertSame('0.0000', $price->consumedQuantity);
        self::assertSame('7.0000', $price->availableQuantity);
    }

    /** `D-81`: available = quantity − consumed_quantity, computed where NUMERIC is exact. */
    public function test_that_a_partly_consumed_line_reports_what_is_left(): void
    {
        $line = $this->supplierLine('100', '7', $this->currencyId);
        DB::table('supplier_quotation_items')->where('id', $line)->update(['consumed_quantity' => '2.5']);

        $price = $this->reader()->priceFor($line);

        self::assertNotNull($price);
        self::assertSame('7.0000', $price->recordedQuantity);
        self::assertSame('2.5000', $price->consumedQuantity);
        self::assertSame('4.5000', $price->availableQuantity);
    }

    public function test_that_an_absent_line_has_no_price(): void
    {
        self::assertNull($this->reader()->priceFor(Uuid::uuid4()->toString()));
    }

    public function test_that_a_soft_deleted_line_is_gone(): void
    {
        $line = $this->supplierLine('100', '1', $this->currencyId);
        DB::table('supplier_quotation_items')->where('id', $line)->update(['deleted_at' => now()]);

        self::assertNull($this->reader()->priceFor($line));
    }

    public function test_that_a_soft_deleted_offer_takes_its_lines(): void
    {
        $line = $this->supplierLine('100', '1', $this->currencyId, softDeleteOffer: true);

        self::assertNull($this->reader()->priceFor($line));
    }

    /** A price whose offer recorded no currency is reported honestly — the caller blocks (§5.6). */
    public function test_that_a_line_whose_offer_recorded_no_currency_reports_a_null_currency(): void
    {
        $line = $this->supplierLine('100', '1', null);

        $price = $this->reader()->priceFor($line);

        self::assertNotNull($price);
        self::assertNull($price->currencyId);
        self::assertSame('100.000000', $price->unitPrice);
    }

    private function reader(): EloquentSupplierItemPricing
    {
        $reader = $this->app->make(SupplierItemPricingInterface::class);
        self::assertInstanceOf(EloquentSupplierItemPricing::class, $reader);

        return $reader;
    }

    private function supplierLine(string $unitPrice, string $quantity, ?string $currencyId, bool $softDeleteOffer = false): string
    {
        $supplierId = Uuid::uuid4()->toString();
        $catalogItemId = Uuid::uuid4()->toString();
        $offerId = Uuid::uuid4()->toString();
        $lineId = Uuid::uuid4()->toString();

        DB::table('suppliers')->insert([
            'id' => $supplierId, 'name' => 'Alpha Supplies', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('catalog_items')->insert([
            'id' => $catalogItemId, 'kind' => 'product', 'name' => 'Widget', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('supplier_quotations')->insert([
            'id' => $offerId,
            'code' => 'SQ-'.now()->format('Y').'-'.substr($lineId, 0, 4),
            'supplier_id' => $supplierId,
            // The price-needs-currency CHECK is both-or-neither.
            'total_price' => $currencyId === null ? null : '1',
            'currency_id' => $currencyId,
            'deleted_at' => $softDeleteOffer ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('supplier_quotation_items')->insert([
            'id' => $lineId,
            'supplier_quotation_id' => $offerId,
            'catalog_item_id' => $catalogItemId,
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $lineId;
    }
}
