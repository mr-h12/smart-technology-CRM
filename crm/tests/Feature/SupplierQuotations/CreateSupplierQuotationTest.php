<?php

declare(strict_types=1);

namespace Tests\Feature\SupplierQuotations;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\SupplierQuotations\Application\Writing\CreateSupplierQuotation;
use App\Modules\SupplierQuotations\Domain\Listing\SupplierQuotationSummary;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Module 6, Point 2.1 — the write path, one layer below HTTP.
 *
 * §7.2's offer is a header **and its lines**, and `DB-11` says they arrive
 * together or not at all. This point owns that transaction and `AUD-01`'s
 * record of it; the route, the Form Request and the response envelope are
 * Point 2.2's.
 *
 * ── The owner's ruling of 2026-09-02, pinned here ──────────────────────────
 *
 * **`total_price` is entered by the user.** §7.2 lists it as a field of its
 * own, which permits it to differ from the sum of the lines, and the owner
 * chose that reading over "always computed" and over "typed with a warning".
 * The test below therefore submits a total that does **not** match its lines
 * and asserts the submitted figure survives — a later point that starts
 * summing the lines will turn this red, which is the point of writing it.
 */
final class CreateSupplierQuotationTest extends TestCase
{
    use RefreshDatabase;

    private string $supplierId;

    private string $currencyId;

    private string $catalogItemId;

    private string $actorId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplierId = Uuid::uuid4()->toString();
        $this->currencyId = Uuid::uuid4()->toString();
        $this->catalogItemId = Uuid::uuid4()->toString();

        $actorId = User::factory()->create()->getKey();
        self::assertIsString($actorId);
        $this->actorId = $actorId;

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

    // ─────────────────────────────────────────────── the header and its lines

    public function test_that_the_header_is_written_with_an_allocated_code(): void
    {
        $summary = $this->create();

        self::assertSame('SQ-'.now()->format('Y').'-0001', $summary->code);
        self::assertSame($this->supplierId, $summary->supplierId);
    }

    /** §7.2: an offer is "Product · **price** · quantity (+ to add more)". */
    public function test_that_every_line_is_written_in_the_same_call(): void
    {
        $summary = $this->create(['items' => [
            ['catalog_item_id' => $this->catalogItemId, 'unit_price' => '1500', 'quantity' => '3'],
            ['catalog_item_id' => $this->catalogItemId, 'unit_price' => '250.5', 'quantity' => '1'],
        ]]);

        $rows = DB::table('supplier_quotation_items')
            ->where('supplier_quotation_id', $summary->id)
            ->orderBy('unit_price')
            ->get(['unit_price', 'quantity'])
            ->map(static fn (object $row): array => (array) $row)
            ->all();

        // Both rows in one assertion rather than four property reads: `$rows[0]`
        // off a Collection is `stdClass|null` at PHPStan level 10, and comparing
        // the whole shape also catches a line written twice or in the wrong order.
        self::assertSame([
            ['unit_price' => '250.500000', 'quantity' => '1.0000'],
            ['unit_price' => '1500.000000', 'quantity' => '3.0000'],
        ], $rows);
    }

    /** `DB-02`: the actor is the use case's, on the lines as much as the header. */
    public function test_that_the_actor_is_recorded_on_the_lines_too(): void
    {
        $summary = $this->create();

        $row = DB::table('supplier_quotation_items')->where('supplier_quotation_id', $summary->id)->first();

        self::assertNotNull($row);
        self::assertSame($this->actorId, $row->created_by);
        self::assertSame($this->actorId, $row->updated_by);
    }

    /** An offer may be a header alone; §7.2 marks no line count as required. */
    public function test_that_an_offer_with_no_lines_is_accepted(): void
    {
        $summary = $this->create(['items' => []]);

        self::assertSame(0, DB::table('supplier_quotation_items')->where('supplier_quotation_id', $summary->id)->count());
    }

    // ──────────────────────────────── the owner's ruling on `total_price`

    /**
     * Owner's ruling, 2026-09-02: **entered by the user.** The lines below sum
     * to 4500 and the submitted total is 1; the submitted total is what the row
     * must hold.
     */
    public function test_that_the_submitted_total_is_not_replaced_by_the_sum_of_the_lines(): void
    {
        $summary = $this->create([
            'total_price' => '1.000000',
            'items' => [
                ['catalog_item_id' => $this->catalogItemId, 'unit_price' => '1500', 'quantity' => '3'],
            ],
        ]);

        self::assertSame('1.000000', $summary->totalPrice);
    }

    // ─────────────────────────────────────────────────────────────── `AUD-01`

    public function test_that_the_create_is_written_to_the_audit_log(): void
    {
        $summary = $this->create();

        $row = DB::table('audit_log')
            ->where('event', 'SUPPLIER_QUOTATION_CREATED')
            ->where('entity_id', $summary->id)
            ->first();

        self::assertNotNull($row, 'AUD-01 requires a record of the create.');
        self::assertSame('supplier_quotation', $row->entity_type);
        self::assertNull($row->old_values, 'A create has no old values.');
    }

    // ─────────────────────────────────────────────────────────────── `DB-11`

    /**
     * The whole point of the transaction: a line that the database refuses must
     * take the header, the allocated code's row and the audit entry with it.
     * `catalog_item_id` is the easiest thing to make fail — the foreign key
     * Point 1.2 put there refuses an id no catalog item has.
     */
    public function test_that_a_refused_line_rolls_back_the_whole_offer(): void
    {
        $before = DB::table('supplier_quotations')->count();

        $threw = false;

        try {
            $this->create(['items' => [
                ['catalog_item_id' => $this->catalogItemId, 'unit_price' => '1500', 'quantity' => '3'],
                ['catalog_item_id' => Uuid::uuid4()->toString(), 'unit_price' => '10', 'quantity' => '1'],
            ]]);
        } catch (QueryException) {
            // `QueryException` and not `Throwable`: while this point's use case
            // did not yet exist, a `Throwable` catch made this test pass on a
            // container resolution error, with all three counts at zero for the
            // wrong reason. Measured, not suspected.
            $threw = true;
        }

        self::assertTrue($threw, 'The database accepted a line pointing at no catalog item.');
        self::assertSame($before, DB::table('supplier_quotations')->count(), 'DB-11: the header survived a failed line.');
        self::assertSame(0, DB::table('supplier_quotation_items')->count(), 'DB-11: a line survived its own failure.');
        self::assertSame(0, DB::table('audit_log')->where('event', 'SUPPLIER_QUOTATION_CREATED')->count(),
            'DB-11: the audit row survived a write that never happened.');
    }

    // ──────────────────────────────── `D-22`'s automatic add (Point 3.4)

    /**
     * The build plan's criterion, closed: "an offer containing a product not in
     * the catalog → the product is added automatically". `D-22` says without
     * review, and §7.3 gives a product a name and a `kind` — the rest of the
     * row is left to its defaults, which is what "without review" can honestly
     * mean for a line that carries only a name, a price and a quantity.
     */
    public function test_that_a_line_naming_a_product_the_catalog_lacks_adds_it(): void
    {
        $summary = $this->create(['items' => [
            ['product_name' => 'Copper Cable 4mm', 'unit_price' => '10', 'quantity' => '2'],
        ]]);

        $product = DB::table('catalog_items')->where('name', 'Copper Cable 4mm')->first();

        self::assertNotNull($product, 'D-22: the product was not added to the catalog.');
        self::assertSame('product', $product->kind, '§7.3: it belongs under the Product tab.');

        $line = DB::table('supplier_quotation_items')->where('supplier_quotation_id', $summary->id)->first();

        self::assertNotNull($line);
        self::assertSame($product->id, $line->catalog_item_id, 'The line was not linked to the product it named.');
    }

    /**
     * The owner's ruling of 2026-09-03: a name already in the catalog is the
     * **same product**, reused rather than added twice. Case is not part of the
     * name — Point 3.1's `lower(name) = lower(?)`.
     */
    public function test_that_a_name_the_catalog_already_has_is_reused(): void
    {
        $before = DB::table('catalog_items')->count();

        $summary = $this->create(['items' => [
            ['product_name' => 'split UNIT 1.5hp', 'unit_price' => '10', 'quantity' => '1'],
        ]]);

        self::assertSame($before, DB::table('catalog_items')->count(), 'A second row was added for one product.');

        $line = DB::table('supplier_quotation_items')->where('supplier_quotation_id', $summary->id)->first();

        self::assertNotNull($line);
        self::assertSame($this->catalogItemId, $line->catalog_item_id);
    }

    /**
     * `DB-11`, and the reason the resolution happens **inside** the
     * transaction: a product added for line one must not outlive an offer that
     * line two destroyed. Resolving before the transaction opened would leave a
     * catalog row behind for an offer that never existed.
     */
    public function test_that_a_product_added_for_a_refused_offer_is_rolled_back(): void
    {
        $before = DB::table('catalog_items')->count();

        $threw = false;

        try {
            $this->create(['items' => [
                ['product_name' => 'Copper Cable 4mm', 'unit_price' => '10', 'quantity' => '2'],
                ['catalog_item_id' => Uuid::uuid4()->toString(), 'unit_price' => '10', 'quantity' => '1'],
            ]]);
        } catch (QueryException) {
            $threw = true;
        }

        self::assertTrue($threw, 'The database accepted a line pointing at no catalog item.');
        self::assertSame($before, DB::table('catalog_items')->count(), 'DB-11: the added product outlived the offer.');
        self::assertSame(
            0,
            DB::table('audit_log')->where('event', 'CATALOG_ITEM_CREATED')->count(),
            'DB-11: the catalog audit row outlived the write it recorded.',
        );
    }

    /**
     * `AUD-02` wants the values this write actually stored. By the time the
     * audit row is written the name has become an id, so that is what it
     * records — a row naming a product the reader cannot resolve would answer
     * "what was created?" with a string.
     */
    public function test_that_the_audit_records_the_product_the_name_resolved_to(): void
    {
        $summary = $this->create(['items' => [
            ['product_name' => 'Copper Cable 4mm', 'unit_price' => '10', 'quantity' => '2'],
        ]]);

        $row = DB::table('audit_log')
            ->where('event', 'SUPPLIER_QUOTATION_CREATED')
            ->where('entity_id', $summary->id)
            ->first();

        self::assertNotNull($row);

        $product = DB::table('catalog_items')->where('name', 'Copper Cable 4mm')->value('id');

        self::assertIsString($product);
        self::assertIsString($row->new_values);
        self::assertStringContainsString($product, $row->new_values, 'The audit did not record the resolved product.');
        self::assertStringNotContainsString('product_name', $row->new_values, 'The audit recorded the unresolved name.');
    }

    // ───────────────────────────────────────────────────────────────── helpers

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
}
