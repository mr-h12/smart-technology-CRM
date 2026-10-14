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
 * F-48 · 2 — `D-118`: a tax-inclusive supplier offer keeps the rates as typed
 * in `included_tax_percents`, beside `included_tax_percent`, their sum.
 */
final class SupplierQuotationTaxRatesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_14_000100_add_included_tax_percents_to_supplier_quotations.php';

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

    public function test_that_an_offer_has_no_rates_by_default(): void
    {
        $id = $this->insert();

        self::assertSame('[]', DB::table('supplier_quotations')->where('id', $id)->value('included_tax_percents'));
    }

    /** D-118: rates belong to a flagged offer, and the flag already demands a sum (D-105's CHECK). */
    public function test_that_rates_on_an_offer_that_is_not_flagged_are_refused(): void
    {
        $this->expectException(QueryException::class);

        $this->insert(['included_tax_percents' => '["14.000"]']);
    }

    public function test_that_a_flagged_offer_with_its_rates_and_their_sum_is_accepted(): void
    {
        $id = $this->insert(['prices_include_tax' => true, 'included_tax_percent' => '19.000', 'included_tax_percents' => '["14.000", "5.000"]']);

        self::assertSame(['14.000', '5.000'], json_decode((string) DB::table('supplier_quotations')->where('id', $id)->value('included_tax_percents'), true));
    }

    /** An offer flagged before F-48 · 2 keeps its one captured rate as a one-rate list. */
    public function test_that_an_existing_flagged_offer_becomes_a_one_rate_list(): void
    {
        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATION, '--force' => true]));
        self::assertFalse(Schema::hasColumn('supplier_quotations', 'included_tax_percents'), 'down() left the column behind (DEV-03).');

        $id = $this->insert(['prices_include_tax' => true, 'included_tax_percent' => '14.000']);

        self::assertSame(0, Artisan::call('migrate', ['--path' => self::MIGRATION, '--force' => true]));
        self::assertSame(['14.000'], json_decode((string) DB::table('supplier_quotations')->where('id', $id)->value('included_tax_percents'), true));
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
}
