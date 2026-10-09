<?php

declare(strict_types=1);

namespace Tests\Feature\SupplierQuotations;

use App\Modules\Admin\Domain\Contracts\SettingsCacheInterface;
use App\Modules\Admin\Domain\Contracts\SettingsRepositoryInterface;
use App\Modules\Admin\Domain\Settings\SystemSetting;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\SupplierQuotations\Application\Writing\CreateSupplierQuotation;
use App\Modules\SupplierQuotations\Application\Writing\UpdateSupplierQuotation;
use App\Modules\SupplierQuotations\Domain\Listing\SupplierQuotationNotFound;
use App\Modules\SupplierQuotations\Domain\Listing\SupplierQuotationSummary;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use stdClass;
use Tests\TestCase;

/**
 * Module 6, Point 2.4 — the edit path, one layer below HTTP.
 *
 * ── Why this exists beside the endpoint test ───────────────────────────────
 *
 * `DB-11`'s rollback **cannot be proved through the endpoint**, and that was
 * measured rather than assumed: with the transaction removed, all 26 tests in
 * `SupplierQuotationEditEndpointTest` still passed. Point 2.2's Form Request
 * refuses a bad line at the boundary with a 422, so no partial write is ever
 * attempted and there is nothing for a rollback to undo. The only failure that
 * reaches the database is one the boundary cannot see — a `catalog_item_id`
 * that passed `exists` and then vanished, or, as here, a use case invoked
 * directly with an id no catalog item has.
 *
 * `CreateSupplierQuotationTest` makes the same argument for the create half and
 * this file mirrors it deliberately.
 */
final class UpdateSupplierQuotationTest extends TestCase
{
    use RefreshDatabase;

    private string $supplierId;

    private string $currencyId;

    private string $catalogItemId;

    private string $actorId;

    private string $editorId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplierId = Uuid::uuid4()->toString();
        $this->currencyId = Uuid::uuid4()->toString();
        $this->catalogItemId = Uuid::uuid4()->toString();

        $actorId = User::factory()->create()->getKey();
        $editorId = User::factory()->create()->getKey();
        self::assertIsString($actorId);
        self::assertIsString($editorId);
        $this->actorId = $actorId;
        $this->editorId = $editorId;

        DB::table('suppliers')->insert([
            'id' => $this->supplierId,
            'name' => 'Alpha Supplies',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('currencies')->insert([
            'id' => $this->currencyId,
            'code' => 'EGP',
            'rounding_unit' => '1',
            'rounding_enabled' => true,
            'is_base' => true,
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

    /**
     * The whole point of the transaction: a line the database refuses must take
     * the header change, the removal of the old lines and the audit entry with
     * it. `catalog_item_id` is the easiest thing to make fail — the foreign key
     * Point 1.2 put there refuses an id no catalog item has.
     */
    public function test_that_a_refused_line_rolls_back_the_whole_edit(): void
    {
        $offer = $this->create();

        $threw = false;

        try {
            $this->update($offer->id, [
                'notes' => 'Should not survive',
                'items' => [['catalog_item_id' => Uuid::uuid4()->toString(), 'unit_price' => '10', 'quantity' => '1']],
            ]);
        } catch (QueryException) {
            // `QueryException` and not `Throwable`, for the reason
            // `CreateSupplierQuotationTest` measured: a `Throwable` catch passes
            // on a container resolution error with every count at zero for the
            // wrong reason.
            $threw = true;
        }

        self::assertTrue($threw, 'The database accepted a line pointing at no catalog item.');

        $row = DB::table('supplier_quotations')->where('id', $offer->id)->first();
        self::assertInstanceOf(stdClass::class, $row);
        self::assertNull($row->notes, 'DB-11: the header change survived a failed line.');

        self::assertSame(1, DB::table('supplier_quotation_items')
            ->where('supplier_quotation_id', $offer->id)
            ->whereNull('deleted_at')
            ->count(), 'DB-11: the original line was removed by an edit that failed.');

        self::assertSame(0, DB::table('audit_log')
            ->where('event', 'SUPPLIER_QUOTATION_UPDATED')
            ->count(), 'DB-11: the audit row survived a write that never happened.');
    }

    /** `OpenAPI §5.1` — the use case owns the 404, not the controller. */
    public function test_that_editing_an_unknown_offer_throws_not_found(): void
    {
        $this->expectException(SupplierQuotationNotFound::class);

        $this->update(Uuid::uuid4()->toString(), ['notes' => 'x']);
    }

    /** `DB-02`: the edit stamps `updated_by` and leaves `created_by` alone. */
    public function test_that_the_editor_is_recorded_without_replacing_the_author(): void
    {
        $offer = $this->create();

        $this->update($offer->id, ['notes' => 'Revised']);

        $row = DB::table('supplier_quotations')->where('id', $offer->id)->first();
        self::assertInstanceOf(stdClass::class, $row);
        self::assertSame($this->actorId, $row->created_by);
        self::assertSame($this->editorId, $row->updated_by);
    }

    // ──────────────────────────────── `D-22`'s automatic add (Point 3.5)

    /**
     * The edit half of the criterion Point 3.4 closed for the create. A
     * replacement set is lines like any other, and `D-22` does not distinguish
     * the verb that carried them: a product the catalog lacks is added
     * "automatically, without review".
     */
    public function test_that_a_replacement_line_may_name_a_product_the_catalog_lacks(): void
    {
        $offer = $this->create();

        $this->update($offer->id, ['items' => [
            ['product_name' => 'Copper Cable 4mm', 'unit_price' => '10', 'quantity' => '2'],
        ]]);

        $product = DB::table('catalog_items')->where('name', 'Copper Cable 4mm')->first();

        self::assertInstanceOf(stdClass::class, $product, 'D-22: the product was not added to the catalog.');

        self::assertSame($product->id, DB::table('supplier_quotation_items')
            ->where('supplier_quotation_id', $offer->id)
            ->whereNull('deleted_at')
            ->value('catalog_item_id'), 'The replacement line was not linked to the product it named.');
    }

    /**
     * `DB-11` again, and for the same reason the create has it: a product added
     * for the first replacement line must not outlive an edit the second one
     * destroyed. The original lines come back with it.
     */
    public function test_that_a_product_added_for_a_refused_edit_is_rolled_back(): void
    {
        $offer = $this->create();

        $before = DB::table('catalog_items')->count();

        $threw = false;

        try {
            $this->update($offer->id, ['items' => [
                ['product_name' => 'Copper Cable 4mm', 'unit_price' => '10', 'quantity' => '2'],
                ['catalog_item_id' => Uuid::uuid4()->toString(), 'unit_price' => '10', 'quantity' => '1'],
            ]]);
        } catch (QueryException) {
            $threw = true;
        }

        self::assertTrue($threw, 'The database accepted a line pointing at no catalog item.');
        self::assertSame($before, DB::table('catalog_items')->count(), 'DB-11: the added product outlived the edit.');
        self::assertSame(1, DB::table('supplier_quotation_items')
            ->where('supplier_quotation_id', $offer->id)
            ->whereNull('deleted_at')
            ->count(), 'DB-11: the original line was removed by an edit that failed.');
    }

    // ─────────────────────────────────────── D-105: an offer entered tax-inclusive

    /**
     * D-105: "a later change to the setting never alters a saved offer", and
     * the edit sends the amounts as entered, so it never strips twice.
     */
    public function test_that_an_edit_strips_with_the_captured_rate_not_the_current_setting(): void
    {
        $settings = app(SettingsRepositoryInterface::class);
        $settings->put(SystemSetting::DefaultTaxPercent, '14');
        $offer = $this->create($this->taxInclusive());

        // `put()` writes underneath `PRF-08`'s cache; the Admin use case that
        // backs the settings screen is what forgets it, so this test does too.
        $settings->put(SystemSetting::DefaultTaxPercent, '15');
        app(SettingsCacheInterface::class)->forgetSettings();
        $this->update($offer->id, $this->taxInclusive());

        $header = DB::table('supplier_quotations')->where('id', $offer->id)->first();

        self::assertNotNull($header);
        self::assertSame('14.000', $header->included_tax_percent, 'D-105: the captured rate moved with the setting.');
        self::assertSame('1000.000000', $header->total_price, 'D-105: stripped twice, or with the new rate.');
        self::assertSame('100.000000', $this->liveLine($offer->id)->unit_price);
    }

    /** An offer flagged on an edit captures the rate in force at that save. */
    public function test_that_flagging_an_offer_on_edit_captures_the_current_rate(): void
    {
        $offer = $this->create();

        app(SettingsRepositoryInterface::class)->put(SystemSetting::DefaultTaxPercent, '14');
        $this->update($offer->id, $this->taxInclusive());

        self::assertSame('14.000', DB::table('supplier_quotations')->where('id', $offer->id)->value('included_tax_percent'));
        self::assertSame('100.000000', $this->liveLine($offer->id)->unit_price);
    }

    /** Unflagging returns the offer to D-62: taken as recorded, no rate and no entered amounts. */
    public function test_that_unflagging_an_offer_on_edit_takes_it_as_recorded(): void
    {
        app(SettingsRepositoryInterface::class)->put(SystemSetting::DefaultTaxPercent, '14');
        $offer = $this->create($this->taxInclusive());

        $this->update($offer->id, array_merge($this->taxInclusive(), ['prices_include_tax' => false]));

        $header = DB::table('supplier_quotations')->where('id', $offer->id)->first();
        $line = $this->liveLine($offer->id);

        self::assertNotNull($header);
        self::assertFalse($header->prices_include_tax);
        self::assertNull($header->included_tax_percent);
        self::assertNull($header->entered_total_price);
        self::assertSame('1140.000000', $header->total_price);
        self::assertSame('114.000000', $line->unit_price);
        self::assertNull($line->entered_unit_price);
    }

    // ───────────────────────────────────────────────────────────────── helpers

    /** @return array<string, mixed> 114 a unit, 1140 in all, entered tax-inclusive. */
    private function taxInclusive(): array
    {
        return [
            'supplier_id' => $this->supplierId,
            'prices_include_tax' => true,
            'total_price' => '1140',
            'currency_id' => $this->currencyId,
            'items' => [
                ['catalog_item_id' => $this->catalogItemId, 'unit_price' => '114', 'quantity' => '10'],
            ],
        ];
    }

    private function liveLine(string $quotationId): stdClass
    {
        $line = DB::table('supplier_quotation_items')
            ->where('supplier_quotation_id', $quotationId)
            ->whereNull('deleted_at')
            ->first();

        self::assertInstanceOf(stdClass::class, $line);

        return $line;
    }

    /** @param array<string, mixed> $overrides */
    private function create(array $overrides = []): SupplierQuotationSummary
    {
        $useCase = $this->app->make(CreateSupplierQuotation::class);

        return $useCase->create(array_merge([
            'supplier_id' => $this->supplierId,
            'total_price' => '4500.000000',
            'currency_id' => $this->currencyId,
            'items' => [
                ['catalog_item_id' => $this->catalogItemId, 'unit_price' => '1500', 'quantity' => '3'],
            ],
        ], $overrides), $this->actorId);
    }

    /** @param array<string, mixed> $validated */
    private function update(string $quotationId, array $validated): SupplierQuotationSummary
    {
        return $this->app->make(UpdateSupplierQuotation::class)->update($quotationId, $validated, $this->editorId);
    }
}
