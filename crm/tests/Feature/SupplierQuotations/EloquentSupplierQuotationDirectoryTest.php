<?php

declare(strict_types=1);

namespace Tests\Feature\SupplierQuotations;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\SupplierQuotations\Domain\Contracts\SupplierQuotationDirectoryInterface;
use App\Modules\SupplierQuotations\Domain\Listing\SupplierQuotationListCriteria;
use App\Modules\SupplierQuotations\Domain\Writing\SupplierQuotationDraft;
use App\Modules\SupplierQuotations\Infrastructure\EloquentSupplierQuotationDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Module 6, Point 1.3 — the directory behind `supplier_quotations`, and §4.7's
 * `SQ-` allocation.
 *
 * The module has no endpoint yet, so this drives the seam directly rather than
 * through HTTP: what Point 1.3 owes is a contract, an adapter and a code, and
 * a test that went through a controller would be testing Step 2's point early
 * and this one not at all.
 */
final class EloquentSupplierQuotationDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private string $supplierId;

    private string $currencyId;

    private string $actorId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplierId = Uuid::uuid4()->toString();
        $this->currencyId = Uuid::uuid4()->toString();
        // `assertIsString` and not a cast: PHPStan runs at level 10, where
        // `(string) mixed` is an error rather than a narrowing.
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
    }

    // ────────────────────────────────────────────────────────────── the seam

    public function test_that_the_contract_resolves_to_the_eloquent_directory(): void
    {
        self::assertInstanceOf(
            EloquentSupplierQuotationDirectory::class,
            $this->app->make(SupplierQuotationDirectoryInterface::class),
        );
    }

    // ─────────────────────────────────────────────────────── §4.7's `SQ-` code

    public function test_that_the_first_offer_of_the_year_is_numbered_one(): void
    {
        self::assertSame(
            'SQ-'.now()->format('Y').'-0001',
            $this->directory()->create($this->draft(), $this->actorId)->code,
        );
    }

    public function test_that_the_next_offer_takes_the_next_number(): void
    {
        $directory = $this->directory();

        $directory->create($this->draft(), $this->actorId);

        self::assertSame(
            'SQ-'.now()->format('Y').'-0002',
            $directory->create($this->draft(), $this->actorId)->code,
        );
    }

    /**
     * `document_sequences` keys on `(prefix, year)` (Module 0), so `DL`'s count
     * is not `SQ`'s. Asserted rather than assumed: a shared counter would still
     * produce unique codes and would still look right in a single-module test.
     */
    public function test_that_the_deal_counter_does_not_move_this_one(): void
    {
        DB::table('document_sequences')->insert([
            'prefix' => 'DL',
            'year' => (int) now()->format('Y'),
            'last_value' => 41,
        ]);

        self::assertSame(
            'SQ-'.now()->format('Y').'-0001',
            $this->directory()->create($this->draft(), $this->actorId)->code,
        );
    }

    // ───────────────────────────────────────────────────── what a caller writes

    /**
     * Read off the summary rather than the raw row, deliberately: the row would
     * prove the insert and nothing else, while `hydrate()` is where a field can
     * be wired to the wrong column and still look right. Both ends are covered
     * because the row is asserted too.
     */
    public function test_that_the_draft_fields_are_written(): void
    {
        $summary = $this->directory()->create($this->draft([
            'offer_date' => '2026-09-02',
            'valid_until' => '2026-10-02',
            'notes' => 'Two weeks lead time.',
        ]), $this->actorId);

        self::assertSame($this->supplierId, $summary->supplierId);
        self::assertSame($this->currencyId, $summary->currencyId);
        self::assertSame('2026-09-02', $summary->offerDate);
        self::assertSame('2026-10-02', $summary->validUntil);
        self::assertSame('Two weeks lead time.', $summary->notes);

        $row = DB::table('supplier_quotations')->where('id', $summary->id)->first();

        self::assertNotNull($row);
        self::assertSame($summary->code, $row->code);
        self::assertSame('2026-09-02', $row->offer_date);
    }

    /** `D-51`: standalone, and available to any deal. */
    public function test_that_an_offer_needs_no_deal(): void
    {
        $summary = $this->directory()->create($this->draft(), $this->actorId);

        self::assertNull($summary->dealId);
    }

    /**
     * `D-68` end to end, on a value chosen so the assertion can only pass one
     * way: seven decimals in, six out. Without the model's cast the caller gets
     * back the string it sent (`1234.5678914`), and under `decimal()`'s default
     * (8,2) it would be `1234.57`. The first version of this test passed a
     * six-decimal value and stayed green with the cast deleted — measured, not
     * suspected, which is why it now reads like this.
     */
    public function test_that_a_price_comes_back_at_the_scale_d_68_fixes(): void
    {
        $summary = $this->directory()->create(
            $this->draft(['total_price' => '1234.5678914']),
            $this->actorId,
        );

        self::assertSame('1234.567891', $summary->totalPrice);
    }

    // ─────────────────────────────────────── what a caller may **not** write

    /**
     * §7.2 marks the code "Automatic". A caller naming one must not get it —
     * otherwise two offers can be made to share a code that
     * `document_sequences` never issued.
     */
    public function test_that_a_caller_cannot_choose_the_code(): void
    {
        $summary = $this->directory()->create(
            $this->draft(['code' => 'SQ-1999-9999']),
            $this->actorId,
        );

        self::assertSame('SQ-'.now()->format('Y').'-0001', $summary->code);
    }

    /**
     * The draft's own filter, asserted separately — and the separation is the
     * point. Breaking `WRITABLE_ON_CREATE` by adding `code` to it left the test
     * above green, because the model's `#[Fillable]` list drops the key and the
     * directory overwrites the column afterwards anyway. Two guards, one test:
     * so the test above pins the *outcome* (the allocated code wins) and this
     * one pins the *filter*, and neither can now be removed unnoticed.
     */
    public function test_that_the_draft_drops_a_field_it_does_not_publish(): void
    {
        $draft = SupplierQuotationDraft::forCreate([
            'code' => 'SQ-1999-9999',
            'created_by' => Uuid::uuid4()->toString(),
            'supplier_id' => $this->supplierId,
        ]);

        self::assertSame(['supplier_id' => $this->supplierId], $draft->attributes);
    }

    /** `DB-02`: the actor is the use case's, never a field the caller fills. */
    public function test_that_the_actor_is_recorded_on_both_columns(): void
    {
        $summary = $this->directory()->create(
            $this->draft(['created_by' => Uuid::uuid4()->toString()]),
            $this->actorId,
        );

        $row = DB::table('supplier_quotations')->where('id', $summary->id)->first();

        self::assertNotNull($row);
        self::assertSame($this->actorId, $row->created_by);
        self::assertSame($this->actorId, $row->updated_by);
    }

    /**
     * Point 3.3. A line may name its product instead of identifying it
     * (`D-22`), so the name is a fourth writable key — and the filter still
     * drops everything else, which is the half `test_that_the_draft_drops_a_
     * field_it_does_not_publish` pins for the header.
     */
    public function test_that_a_line_may_carry_the_product_name_it_was_given(): void
    {
        $draft = SupplierQuotationDraft::forCreate([
            'supplier_id' => $this->supplierId,
            'items' => [[
                'product_name' => 'Copper Cable 4mm',
                'unit_price' => '10.000000',
                'quantity' => '2.000',
                'category' => 'a column of another table',
            ]],
        ]);

        self::assertSame([[
            'product_name' => 'Copper Cable 4mm',
            'unit_price' => '10.000000',
            'quantity' => '2.000',
        ]], $draft->items);
    }

    // ─────────────────────────────── the list (Point 4.2), §6's query answered

    /**
     * The build plan's "Linked Quotations": one supplier's offers, and nobody
     * else's. §7.1 lists `linked_quotations` as an **Automatic** field of a
     * supplier, and this filter is what makes it automatic.
     */
    public function test_that_the_list_returns_only_the_supplier_asked_for(): void
    {
        $other = $this->supplier('Beta Trading');

        $mine = $this->offer();
        $this->offer(['supplier_id' => $other]);

        $page = $this->directory()->list(
            SupplierQuotationListCriteria::fromQuery(['filter' => ['supplier_id' => $this->supplierId]]),
        );

        self::assertSame(1, $page->total);
        self::assertSame([$mine], array_map(static fn ($item): string => $item->id, $page->items));
    }

    /**
     * `D-51`: the offer is standalone and "available to any deal", so an offer
     * with no deal is a normal row of this list — and a deal is a filter over
     * it, not a parent that owns it.
     */
    public function test_that_offers_can_be_narrowed_to_one_deal_without_hiding_the_unlinked(): void
    {
        $deal = $this->deal();

        $linked = $this->offer(['deal_id' => $deal]);
        $this->offer();

        $all = $this->directory()->list(SupplierQuotationListCriteria::fromQuery([]));
        self::assertSame(2, $all->total, 'D-51: an offer with no deal is still an offer.');

        $filtered = $this->directory()->list(
            SupplierQuotationListCriteria::fromQuery(['filter' => ['deal_id' => $deal]]),
        );

        self::assertSame(1, $filtered->total);
        self::assertSame([$linked], array_map(static fn ($item): string => $item->id, $filtered->items));
    }

    /** `DB-01`: an archived offer is gone from the screen, not from the table. */
    public function test_that_a_soft_deleted_offer_is_not_listed(): void
    {
        $kept = $this->offer();
        $removed = $this->offer();

        DB::table('supplier_quotations')->where('id', $removed)->update(['deleted_at' => now()]);

        $page = $this->directory()->list(SupplierQuotationListCriteria::fromQuery([]));

        self::assertSame(1, $page->total);
        self::assertSame([$kept], array_map(static fn ($item): string => $item->id, $page->items));
    }

    /**
     * The owner's default order (2026-09-04), and the half Point 4.1 left here:
     * `offer_date` is nullable and PostgreSQL sorts nulls **first** on a
     * descending order, which would open the list with the offers nobody dated.
     */
    public function test_that_the_newest_offer_leads_and_the_undated_come_last(): void
    {
        $older = $this->offer(['offer_date' => '2026-01-01']);
        $newest = $this->offer(['offer_date' => '2026-03-01']);
        $undated = $this->offer(['offer_date' => null]);

        $page = $this->directory()->list(SupplierQuotationListCriteria::fromQuery([]));

        self::assertSame(
            [$newest, $older, $undated],
            array_map(static fn ($item): string => $item->id, $page->items),
        );
    }

    /** §6.1: `total` is the whole query's, not the page's — the paginator cannot count otherwise. */
    public function test_that_a_page_carries_the_total_of_the_whole_query(): void
    {
        $this->offer(['offer_date' => '2026-01-01']);
        $this->offer(['offer_date' => '2026-02-01']);
        $this->offer(['offer_date' => '2026-03-01']);

        $first = $this->directory()->list(SupplierQuotationListCriteria::fromQuery(['per_page' => '2']));

        self::assertSame(3, $first->total);
        self::assertCount(2, $first->items);
        self::assertTrue($first->hasNextPage());

        $second = $this->directory()->list(
            SupplierQuotationListCriteria::fromQuery(['per_page' => '2', 'page' => '2']),
        );

        self::assertSame(3, $second->total);
        self::assertCount(1, $second->items);
        self::assertFalse($second->hasNextPage());
    }

    // ───────────────────────────────────────────────────────────────── helpers

    private function directory(): SupplierQuotationDirectoryInterface
    {
        return $this->app->make(SupplierQuotationDirectoryInterface::class);
    }

    /**
     * The id of an offer created through the directory.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function offer(array $overrides = []): string
    {
        return $this->directory()->create($this->draft($overrides), $this->actorId)->id;
    }

    private function supplier(string $name): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('suppliers')->insert([
            'id' => $id,
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** A customer and a deal, because `deal_id` carries a foreign key (Point 1.1). */
    private function deal(): string
    {
        $customerId = Uuid::uuid4()->toString();
        $dealId = Uuid::uuid4()->toString();

        DB::table('customers')->insert([
            'id' => $customerId,
            'name' => 'Nile Contracting',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('deals')->insert([
            'id' => $dealId,
            'code' => 'DL-'.now()->format('Y').'-9001',
            'customer_id' => $customerId,
            'last_activity_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $dealId;
    }

    /** @param array<string, mixed> $overrides */
    private function draft(array $overrides = []): SupplierQuotationDraft
    {
        return SupplierQuotationDraft::forCreate(array_merge([
            'supplier_id' => $this->supplierId,
            'total_price' => '1500.000000',
            'currency_id' => $this->currencyId,
        ], $overrides));
    }
}
