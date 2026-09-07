<?php

declare(strict_types=1);

namespace Tests\Feature\Quotations;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Quotations\Domain\Contracts\QuotationDirectoryInterface;
use App\Modules\Quotations\Domain\Writing\QuotationDraft;
use App\Modules\Quotations\Infrastructure\Eloquent\Quotation;
use App\Modules\Quotations\Infrastructure\EloquentQuotationDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Module 7, Point 1.7 — the directory behind `quotations`, §4.7's `QT-`
 * allocation, and `D-68`'s casts.
 *
 * The module has no endpoint and no use case yet, so this drives the seam
 * directly rather than through HTTP —
 * `EloquentSupplierQuotationDirectoryTest`'s precedent (Module 6 Point 1.3): a
 * test that went through a controller would be testing Step 3's point early and
 * this one not at all.
 *
 * The attributes written here are **§5.2-consistent** — they satisfy Point
 * 1.2's additive `CHECK`s — because a row that violates one never reaches the
 * behaviour under test.
 */
final class EloquentQuotationDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private string $customerId;

    private string $dealId;

    private string $currencyId;

    private string $actorId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customerId = Uuid::uuid4()->toString();
        $this->dealId = Uuid::uuid4()->toString();
        $this->currencyId = Uuid::uuid4()->toString();

        // `assertIsString` and not a cast: PHPStan runs at level 10, where
        // `(string) mixed` is an error rather than a narrowing.
        $actorId = User::factory()->create()->getKey();
        self::assertIsString($actorId);
        $this->actorId = $actorId;

        DB::table('customers')->insert([
            'id' => $this->customerId,
            'name' => 'Nile Trading',
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

        DB::table('deals')->insert([
            'id' => $this->dealId,
            'code' => 'DL-2026-0001',
            'customer_id' => $this->customerId,
            'last_activity_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ───────────────────────────────────────────────────────────── the binding

    public function test_that_the_contract_resolves_to_the_eloquent_directory(): void
    {
        self::assertInstanceOf(
            EloquentQuotationDirectory::class,
            $this->app->make(QuotationDirectoryInterface::class),
        );
    }

    // ─────────────────────────────────────────────────────── §4.7's `QT-` code

    public function test_that_the_first_quotation_of_the_year_is_numbered_one(): void
    {
        self::assertSame(
            'QT-'.now()->format('Y').'-0001',
            $this->directory()->create($this->draft(), $this->actorId)->code,
        );
    }

    public function test_that_the_next_quotation_takes_the_next_number(): void
    {
        $directory = $this->directory();

        $directory->create($this->draft(), $this->actorId);

        self::assertSame(
            'QT-'.now()->format('Y').'-0002',
            $directory->create($this->draft(), $this->actorId)->code,
        );
    }

    /**
     * §4.7 says the code is "Automatic", and this is the end-to-end proof: what
     * a caller sent is not what the row holds.
     *
     * It is **not** the proof that the draft filters, and the mutation that
     * showed it is recorded here rather than glossed: adding `code` to
     * `WRITABLE_ON_CREATE` leaves this test green, because the directory
     * overwrites `$row->code` after `fill()`. What this catches is the
     * directory forgetting to allocate. The allow-list itself is asserted by
     * {@see self::test_that_the_draft_keeps_only_the_columns_it_publishes()}.
     */
    public function test_that_a_submitted_code_is_ignored(): void
    {
        $summary = $this->directory()->create(
            $this->draft(['code' => 'QT-1999-9999']),
            $this->actorId,
        );

        self::assertSame('QT-'.now()->format('Y').'-0001', $summary->code);
        self::assertSame(0, DB::table('quotations')->where('code', 'QT-1999-9999')->count());
    }

    /**
     * The same end-to-end question for the other six: `DB-02`'s actor, §6.1's
     * default status, `D-08`'s version and `DB-12`'s lock all come from the
     * write or from the column, never from the payload.
     *
     * Two layers stand between a payload and these columns — the draft's
     * allow-list and the model's `#[Fillable]` — and this test passes while
     * *either* holds. That is the right assertion for a behaviour, and the
     * wrong one for a list, which is why the list has its own test below.
     */
    public function test_that_the_columns_the_draft_refuses_are_not_a_callers_to_set(): void
    {
        $otherUser = User::factory()->create()->getKey();
        self::assertIsString($otherUser);

        $summary = $this->directory()->create(
            $this->draft([
                'status' => 'approved',
                'version' => 7,
                'version_token' => 99,
                'parent_id' => Uuid::uuid4()->toString(),
                'is_self_approved' => true,
                'created_by' => $otherUser,
            ]),
            $this->actorId,
        );

        $row = DB::table('quotations')->where('id', $summary->id)->first();
        self::assertIsObject($row);

        self::assertSame('draft', $row->status);
        self::assertSame(1, $row->version);
        self::assertSame(1, $row->version_token);
        self::assertNull($row->parent_id);
        self::assertFalse($row->is_self_approved);
        self::assertSame($this->actorId, $row->created_by);
        self::assertSame($this->actorId, $row->updated_by);
    }

    /**
     * The allow-list, asserted as a list.
     *
     * Every key below is one §10 column the draft's own table says it refuses,
     * and `assertSame` on the whole array means this fails if **any** of them
     * joins `WRITABLE_ON_CREATE` — including `code`, which the end-to-end test
     * above cannot see because the directory overwrites it either way.
     */
    public function test_that_the_draft_keeps_only_the_columns_it_publishes(): void
    {
        $draft = QuotationDraft::forCreate([
            'deal_id' => $this->dealId,
            'code' => 'QT-1999-9999',
            'status' => 'approved',
            'version' => 7,
            'parent_id' => Uuid::uuid4()->toString(),
            'version_token' => 99,
            'rejection_reason' => 'because',
            'sent_at' => now(),
            'is_self_approved' => true,
            'created_by' => Uuid::uuid4()->toString(),
            'updated_by' => Uuid::uuid4()->toString(),
        ]);

        self::assertSame(['deal_id' => $this->dealId], $draft->attributes);
    }

    // ────────────────────────────────────────────────────────── `D-68`'s casts

    /**
     * `D-68` fixes money at `NUMERIC(18,6)`. A seventh decimal is quantized by
     * the column and the cast has to agree with it — a `decimal:7` here would
     * read back a value the database does not hold.
     */
    public function test_that_a_seventh_decimal_of_money_comes_back_at_six(): void
    {
        $summary = $this->directory()->create(
            $this->draft(['rounding_unit' => '1.2345678']),
            $this->actorId,
        );

        self::assertSame('1.234568', $this->stored($summary->id)->rounding_unit);
    }

    /** `D-68` fixes percent at `NUMERIC(6,3)`, and the cast agrees. */
    public function test_that_a_fourth_decimal_of_percent_comes_back_at_three(): void
    {
        $summary = $this->directory()->create(
            $this->draft(['default_margin' => '20.1234']),
            $this->actorId,
        );

        self::assertSame('20.123', $this->stored($summary->id)->default_margin);
    }

    /**
     * `D-63`: an exempt quotation renders **no tax line at all**, so `null` has
     * to survive the cast rather than becoming `'0.000'`. Point 1.2's
     * `(tax_percent IS NULL) = (tax_amount IS NULL)` keeps the pair honest.
     */
    public function test_that_an_absent_tax_stays_null_through_the_cast(): void
    {
        $summary = $this->directory()->create($this->draft(), $this->actorId);
        $row = $this->stored($summary->id);

        self::assertNull($row->tax_percent);
        self::assertNull($row->tax_amount);
    }

    /**
     * The model's docblock claims the booleans and the two counters need no
     * cast because the pgsql driver already returns them typed. Asserted here
     * rather than assumed — if that ever stops being true, this is what says so
     * instead of a serialiser handing `"1"` to the UI.
     *
     * Read off the **query builder**, not off the model. Through the model the
     * values arrive typed by the `@property` block, so PHPStan reports each
     * assertion as always-true — the test would be checking the docblock
     * against itself. The builder hands back a `stdClass` of driver output,
     * which is the thing the claim is actually about.
     */
    public function test_that_booleans_and_counters_come_back_natively_typed(): void
    {
        $summary = $this->directory()->create($this->draft(), $this->actorId);

        $row = DB::table('quotations')->where('id', $summary->id)->first();
        self::assertIsObject($row);

        self::assertIsBool($row->rounding_enabled);
        self::assertIsBool($row->show_delivery_terms);
        self::assertIsBool($row->is_self_approved);
        self::assertIsInt($row->version);
        self::assertIsInt($row->version_token);
    }

    // ─────────────────────────────────────────────── Points 1.3 / 1.4's children

    /**
     * The lines §5.1 priced reach `quotation_items` — the directory's second
     * write, `EloquentSupplierQuotationDirectory::writeLines()`'s precedent
     * (Module 6 Point 2.1). Each row keeps its priced columns, is stamped with
     * the header's id and `DB-02`'s actor, and — the column Module 6's table
     * lacks — takes its `line_no` from where it sat in the submitted list.
     */
    public function test_that_the_priced_lines_reach_quotation_items(): void
    {
        $first = $this->seedSupplierLine();
        $second = $this->seedSupplierLine();

        $summary = $this->directory()->create(
            $this->draft()->withLines(
                [$this->lineRow($first, null), $this->lineRow($second, '30')],
                [],
            ),
            $this->actorId,
        );

        // Both rows in one assertion, `CreateSupplierQuotationTest`'s idiom:
        // `$rows[0]` off a Collection is `stdClass|null` at PHPStan level 10, and
        // the whole shape also catches a line written twice or in the wrong order.
        // It carries, in one read: §10's positional `line_no` (1-based), §4.1's
        // one edge to the supplier line, a priced column stored as handed in,
        // `D-03`'s null-margin-inherits vs a kept margin, and `DB-02`'s actor.
        $rows = DB::table('quotation_items')
            ->where('quotation_id', $summary->id)
            ->orderBy('line_no')
            ->get(['line_no', 'supplier_quotation_item_id', 'unit_price', 'margin_percent', 'created_by', 'updated_by'])
            ->map(static fn (object $row): array => (array) $row)
            ->all();

        self::assertSame([
            [
                'line_no' => 1,
                'supplier_quotation_item_id' => $first,
                'unit_price' => '36000.000000',
                'margin_percent' => null,
                'created_by' => $this->actorId,
                'updated_by' => $this->actorId,
            ],
            [
                'line_no' => 2,
                'supplier_quotation_item_id' => $second,
                'unit_price' => '36000.000000',
                'margin_percent' => '30.000',
                'created_by' => $this->actorId,
                'updated_by' => $this->actorId,
            ],
        ], $rows);
    }

    /**
     * §5.2's additional items reach their own table (`D-62`: they never enter
     * the tax base, which is the parent row's rule; this only stores the lines).
     * `line_no` is positional here too.
     */
    public function test_that_the_additional_items_reach_their_table(): void
    {
        $summary = $this->directory()->create(
            $this->draft()->withLines([], [
                ['description' => 'Delivery', 'amount' => '1000.000000'],
                ['description' => 'Installation', 'amount' => '500.000000'],
            ]),
            $this->actorId,
        );

        $rows = DB::table('quotation_additional_items')
            ->where('quotation_id', $summary->id)
            ->orderBy('line_no')
            ->get(['line_no', 'description', 'amount', 'created_by'])
            ->map(static fn (object $row): array => (array) $row)
            ->all();

        self::assertSame([
            ['line_no' => 1, 'description' => 'Delivery', 'amount' => '1000.000000', 'created_by' => $this->actorId],
            ['line_no' => 2, 'description' => 'Installation', 'amount' => '500.000000', 'created_by' => $this->actorId],
        ], $rows);
    }

    /**
     * A quotation with no children — the empty-list short-circuit. `create()`
     * writes the header and nothing else, rather than an empty `insert()` the
     * driver would reject.
     */
    public function test_that_a_childless_quotation_writes_no_child_rows(): void
    {
        $summary = $this->directory()->create($this->draft(), $this->actorId);

        self::assertSame(0, DB::table('quotation_items')->where('quotation_id', $summary->id)->count());
        self::assertSame(
            0,
            DB::table('quotation_additional_items')->where('quotation_id', $summary->id)->count(),
        );
    }

    // ────────────────────────────────────────────────────────────────── helpers

    /**
     * The supplier line a `quotation_items` row must point at:
     * `supplier_quotation_item_id` is NOT NULL with a foreign key (Point 1.3,
     * §5.6's "block save"), so §4.1's chain has to exist before a quotation line
     * can name it. Returns the id of one seeded supplier line.
     */
    private function seedSupplierLine(): string
    {
        $supplierId = Uuid::uuid4()->toString();
        $catalogItemId = Uuid::uuid4()->toString();
        $supplierQuotationId = Uuid::uuid4()->toString();
        $lineId = Uuid::uuid4()->toString();

        DB::table('suppliers')->insert([
            'id' => $supplierId,
            'name' => 'Alpha Supplies',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('catalog_items')->insert([
            'id' => $catalogItemId,
            'kind' => 'product',
            'name' => 'Widget',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('supplier_quotations')->insert([
            'id' => $supplierQuotationId,
            'code' => 'SQ-'.now()->format('Y').'-'.substr($lineId, 0, 4),
            'supplier_id' => $supplierId,
            // `supplier_quotations_price_needs_currency`: total_price and
            // currency_id are both-or-neither. This chain only exists to give a
            // line a real id to point at, so the pair is the cheapest that clears.
            'total_price' => '1000',
            'currency_id' => $this->currencyId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('supplier_quotation_items')->insert([
            'id' => $lineId,
            'supplier_quotation_id' => $supplierQuotationId,
            'catalog_item_id' => $catalogItemId,
            'unit_price' => '1000',
            'quantity' => '2',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $lineId;
    }

    /**
     * One priced `quotation_items` row as Point 3.3 will hand it in — every
     * column of the table the directory does not fill itself. The figures only
     * have to clear Point 1.3's bounds (quantity > 0, prices ≥ 0, rate > 0);
     * the §5.1 arithmetic between them is Step 2's, not asserted here.
     *
     * @return array<string, mixed>
     */
    private function lineRow(string $supplierLineId, ?string $margin): array
    {
        return [
            'supplier_quotation_item_id' => $supplierLineId,
            'unit_cost' => '1000.000000',
            'unit_cost_currency' => 'USD',
            'unit_cost_fx_rate_at_time' => '30',
            'unit_cost_base' => '30000.000000',
            'margin_percent' => $margin,
            'unit_price' => '36000.000000',
            'quantity' => '2',
            'line_total' => '72000.000000',
            'line_cost' => '60000.000000',
        ];
    }

    private function directory(): EloquentQuotationDirectory
    {
        $directory = $this->app->make(QuotationDirectoryInterface::class);
        self::assertInstanceOf(EloquentQuotationDirectory::class, $directory);

        return $directory;
    }

    /** @param  array<string, mixed>  $overrides */
    private function draft(array $overrides = []): QuotationDraft
    {
        return QuotationDraft::forCreate(array_merge([
            'deal_id' => $this->dealId,
            'customer_id' => $this->customerId,
            'currency_id' => $this->currencyId,
            'default_margin' => '20',
            'discount_percent' => '0',
            'rounding_unit' => '1',
            'rounding_enabled' => true,
            'subtotal' => '0',
            'additional_total' => '0',
            'discount_amount' => '0',
            'tax_base' => '0',
            'net_amount' => '0',
            'total_before_round' => '0',
            'final_total' => '0',
            'rounding_diff' => '0',
        ], $overrides));
    }

    /** The row as the model reads it, which is what puts the casts under test. */
    private function stored(string $id): Quotation
    {
        $row = Quotation::query()->findOrFail($id);
        self::assertInstanceOf(Quotation::class, $row);

        return $row;
    }
}
