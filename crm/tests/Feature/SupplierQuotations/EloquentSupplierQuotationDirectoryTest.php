<?php

declare(strict_types=1);

namespace Tests\Feature\SupplierQuotations;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\SupplierQuotations\Domain\Contracts\SupplierQuotationDirectoryInterface;
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

    // ───────────────────────────────────────────────────────────────── helpers

    private function directory(): SupplierQuotationDirectoryInterface
    {
        return $this->app->make(SupplierQuotationDirectoryInterface::class);
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
