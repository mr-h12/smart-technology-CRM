<?php

declare(strict_types=1);

namespace Tests\Feature\Quotations;

use App\Modules\Identity\Domain\Rbac\AuthorizationRefused;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Quotations\Application\Writing\CreateQuotation;
use App\Modules\Quotations\Application\Writing\QuotationCreated;
use App\Modules\Quotations\Infrastructure\Eloquent\Quotation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Module 7, Point 3.3 — `CreateQuotation`, the create §5 and §6 need.
 *
 * The highest-priority test surface in the project (`CLAUDE.md`): the full §5.1
 * line arithmetic, §5.2's totals in `D-64`'s order, and `D-65`'s optional
 * rounding. QS.2a (`D-156`, `D-157`): a line is priced from what was typed,
 * taxed at its own rate, the toggle the preparer's; `D-161` retired §5.6's
 * over-quantity warning, `D-164` the FX on a line, `D-155` the supplier offer
 * a line was priced from.
 *
 * Driven through the use case, not HTTP: `POST /quotations` is Point 3.4.
 */
final class CreateQuotationTest extends TestCase
{
    use QuotationLineFixtures;
    use RefreshDatabase;

    private string $actorId;

    private string $customerId;

    private string $dealId;

    private string $egpId;

    protected function setUp(): void
    {
        parent::setUp();

        $actorId = User::factory()->create()->getKey();
        self::assertIsString($actorId);
        $this->actorId = $actorId;

        $this->egpId = $this->currency('EGP', '1', true, true);
        $this->customerId = $this->customer(false);
        $this->dealId = $this->deal($this->customerId);
    }

    // ──────────────────────────────────── QS.2a · D-156 · D-157 · D-62 · D-64

    /**
     * The whole of §5 as QS.2a prices it, end to end through the use case: each line's tax taken out of the
     * price typed, then its own margin added (`D-156`) — 114 incl. 14 % → 100 → 120.00 × 3 = 360.00, and 50
     * incl. 0 % (allowed, `D-157`) → 50 → 55.00 × 2 = 110.00 — the discount spread over the lines before tax
     * (`D-64`, `D-158`), each line taxed at its own rate, the sum the VAT line: 360 × 0.9 × 14 % + 110 × 0.9
     * × 0 % = 45.36; the delivery untaxed (`D-62`); `D-65`'s rounding (EGP, unit 1, on) on the final total
     * alone. 470.00 + 50.00 − 47.00 + 45.36 = 518.36 → 518, −0.36 stored. Every figure by hand.
     */
    public function test_that_each_line_is_priced_from_what_was_typed_and_taxed_at_its_own_rate(): void
    {
        $taxed = $this->lineBody();
        $zeroRated = $this->lineBody(entered: '50', tax: '0', margin: '10', quantity: '2');

        $result = $this->create([
            ...$this->e1Body($taxed),
            'deal_id' => $this->dealId,
            'customer_id' => $this->customerId,
            'lines' => [$taxed, $zeroRated],
        ]);
        $id = $result->quotation->id;

        self::assertSame([
            'pricing_mode' => 'tax_included', 'default_margin' => null, 'tax_percent' => null, 'tax_percents' => '[]',
            'subtotal' => '470.000000', 'additional_total' => '50.000000', 'discount_amount' => '47.000000', 'tax_base' => '423.000000',
            'tax_amount' => '45.360000', 'net_amount' => '473.000000', 'total_before_round' => '518.360000',
            'final_total' => '518.000000', 'rounding_diff' => '-0.360000',
        ], (array) DB::table('quotations')->where('id', $id)->first([
            'pricing_mode', 'default_margin', 'tax_percent', 'tax_percents', 'subtotal', 'additional_total', 'discount_amount', 'tax_base',
            'tax_amount', 'net_amount', 'total_before_round', 'final_total', 'rounding_diff',
        ]));

        self::assertSame([
            [
                'supplier_quotation_item_id' => null, 'entered_unit_price' => '114.000000', 'tax_percent' => '14.000', 'margin_percent' => '20.000',
                'unit_cost' => '100.000000', 'unit_cost_base' => '100.000000', 'unit_price' => '120.000000', 'line_total' => '360.000000', 'line_cost' => '300.000000',
            ],
            [
                'supplier_quotation_item_id' => null, 'entered_unit_price' => '50.000000', 'tax_percent' => '0.000', 'margin_percent' => '10.000',
                'unit_cost' => '50.000000', 'unit_cost_base' => '50.000000', 'unit_price' => '55.000000', 'line_total' => '110.000000', 'line_cost' => '100.000000',
            ],
        ], DB::table('quotation_items')->where('quotation_id', $id)->orderBy('line_no')
            ->get(['supplier_quotation_item_id', 'entered_unit_price', 'tax_percent', 'margin_percent', 'unit_cost', 'unit_cost_base', 'unit_price', 'line_total', 'line_cost'])
            ->map(static fn (object $r): array => (array) $r)
            ->all());

        // AUD-01: the create is recorded.
        self::assertSame(1, DB::table('audit_log')->where('event', 'QUOTATION_CREATED')->where('entity_id', $id)->count());
    }

    /**
     * `D-157` amends `D-63`: whether prices include tax is the preparer's toggle, not forced from the
     * customer's exemption — a `tax_included` quotation to an exempt customer is taxed (example 1's 45.36).
     */
    public function test_that_an_exempt_customer_does_not_force_the_tax_off(): void
    {
        $exempt = $this->customer(true);

        $result = $this->create([...$this->e1Body(), 'deal_id' => $this->deal($exempt), 'customer_id' => $exempt]);

        self::assertSame(
            ['pricing_mode' => 'tax_included', 'tax_amount' => '45.360000'],
            (array) DB::table('quotations')->where('id', $result->quotation->id)->first(['pricing_mode', 'tax_amount']),
        );
    }

    // ────────────────────────────────────────────────────────────── D-65 rounding

    /** Rounding on: the final total alone rounds to the unit, and the difference is stored. */
    public function test_that_rounding_applies_to_the_final_total_only(): void
    {
        $line = $this->supplierLine('100.75', '1', $this->egpId);

        $result = $this->create($this->payload(
            lines: [['supplier_quotation_item_id' => $line, 'quantity' => '1', 'margin_percent' => null]],
            additional: [],
            overrides: ['default_margin' => '0', 'discount_percent' => '0'],
        ));

        $row = $this->stored($result->quotation->id);
        self::assertSame('100.750000', $row->total_before_round);
        self::assertSame('101.000000', $row->final_total);   // 100.75 → nearest 1, half up
        self::assertSame('0.250000', $row->rounding_diff);
    }

    /**
     * Rounding off (`D-65`): the total is left exact and `rounding_diff` is zero.
     * Since `D-142` the unit price and the line amount are both at 2 places
     * (100.76), so a sub-cent total comes from the discount: 100.76 − 0.5% =
     * 100.76 − 0.5038 = 100.2562, which a 0.01 unit would have rounded to 100.26.
     */
    public function test_that_rounding_off_leaves_the_total_unrounded(): void
    {
        $eur = $this->currency('EUR', '0.01', false, false);
        $line = $this->supplierLine('100.755', '1', $eur);

        $result = $this->create($this->payload(
            lines: [['supplier_quotation_item_id' => $line, 'quantity' => '1', 'margin_percent' => null]],
            additional: [],
            overrides: ['currency' => 'EUR', 'default_margin' => '0', 'discount_percent' => '0.5'],
        ));

        $row = $this->stored($result->quotation->id);
        self::assertFalse($row->rounding_enabled);
        self::assertSame('100.256200', $row->final_total);
        self::assertSame('0.000000', $row->rounding_diff);
    }

    // ──────────────────────────────────────────────── D-142 the unit price at 2 places

    /**
     * `D-142`'s own case, priced end to end: a supplier price stored at six
     * places (a tax-inclusive 30000 entering net at 14%, `D-105`) is sold at
     * 26315.79, so the line is 26315.79 × 10 = 263157.90 and the subtotal
     * adds that line. The cost columns keep `D-68`'s six places.
     */
    public function test_that_the_stored_unit_price_is_rounded_to_two_places_before_the_quantity(): void
    {
        $line = $this->supplierLine('26315.789474', '10', $this->egpId);

        $result = $this->create($this->payload(
            lines: [['supplier_quotation_item_id' => $line, 'quantity' => '10', 'margin_percent' => null]],
            additional: [],
            overrides: ['default_margin' => '0', 'discount_percent' => '0'],
        ));

        $item = DB::table('quotation_items')->where('quotation_id', $result->quotation->id)
            ->first(['unit_cost_base', 'unit_price', 'line_total', 'line_cost']);
        self::assertNotNull($item);
        self::assertSame(
            ['unit_cost_base' => '26315.789474', 'unit_price' => '26315.790000', 'line_total' => '263157.900000', 'line_cost' => '263157.894740'],
            (array) $item,
        );
        self::assertSame('263157.900000', $this->stored($result->quotation->id)->subtotal);
    }

    // ──────────────────────────────────────────── §3.5 create scope · Point 3.4

    /**
     * §3.5's `create` row is scoped, and a quotation has no owner column: the
     * owner ruled (2026-09-11) that "own" is the deal's `owner_id`. So an `own`
     * caller may quote a deal they own …
     */
    public function test_that_an_own_scoped_caller_can_quote_their_own_deal(): void
    {
        $dealId = $this->deal($this->customerId, $this->actorId);
        $line = $this->supplierLine('100', '1', $this->egpId);

        $result = $this->create($this->payload(
            lines: [['supplier_quotation_item_id' => $line, 'quantity' => '1', 'margin_percent' => null]],
            additional: [],
            overrides: ['deal_id' => $dealId],
        ), ['own']);

        self::assertSame($dealId, $this->stored($result->quotation->id)->getAttribute('deal_id'));
    }

    /** … and not one owned by somebody else — refused, and nothing written. */
    public function test_that_an_own_scoped_caller_cannot_quote_another_owners_deal(): void
    {
        $otherId = User::factory()->create()->getKey();
        self::assertIsString($otherId);
        $dealId = $this->deal($this->customerId, $otherId);
        $line = $this->supplierLine('100', '1', $this->egpId);

        try {
            $this->create($this->payload(
                lines: [['supplier_quotation_item_id' => $line, 'quantity' => '1', 'margin_percent' => null]],
                additional: [],
                overrides: ['deal_id' => $dealId],
            ), ['own']);
            self::fail('An `own` caller may not quote a deal they do not own (§3.5).');
        } catch (AuthorizationRefused) {
            // expected
        }

        self::assertSame(0, DB::table('quotations')->count());
        self::assertSame(0, DB::table('quotation_items')->count());
    }

    /** An unowned deal (`owner_id` null) is nobody's — an `own` caller is refused, fail-closed. */
    public function test_that_an_own_scoped_caller_cannot_quote_an_unowned_deal(): void
    {
        $this->expectException(AuthorizationRefused::class);

        $this->create($this->payload(lines: [], additional: [], overrides: []), ['own']);   // setUp's deal has no owner
    }

    /**
     * `D-138` — `team` reaches the deals the caller's team owns: a member is a
     * user whose `team_leader_id` is the caller.
     */
    public function test_that_a_team_scoped_caller_can_quote_a_team_members_deal(): void
    {
        $memberId = User::factory()->create(['team_leader_id' => $this->actorId])->getKey();
        self::assertIsString($memberId);
        $dealId = $this->deal($this->customerId, $memberId);

        $result = $this->create($this->payload(lines: [], additional: [], overrides: ['deal_id' => $dealId]), ['team']);

        self::assertSame($dealId, $this->stored($result->quotation->id)->getAttribute('deal_id'));
    }

    /** … and a deal whose owner is outside the caller's team is refused, fail-closed. */
    public function test_that_a_team_scoped_caller_cannot_quote_a_deal_outside_the_team(): void
    {
        $outsiderId = User::factory()->create()->getKey();
        self::assertIsString($outsiderId);

        $this->expectException(AuthorizationRefused::class);

        $this->create($this->payload(lines: [], additional: [], overrides: [
            'deal_id' => $this->deal($this->customerId, $outsiderId),
        ]), ['team']);
    }

    /** `all` quotes anybody's deal (Manager). */
    public function test_that_an_all_scoped_caller_can_quote_any_deal(): void
    {
        $otherId = User::factory()->create()->getKey();
        self::assertIsString($otherId);
        $dealId = $this->deal($this->customerId, $otherId);

        $result = $this->create($this->payload(lines: [], additional: [], overrides: ['deal_id' => $dealId]), ['all']);

        self::assertSame($dealId, $this->stored($result->quotation->id)->getAttribute('deal_id'));
    }

    /** A `deal_id` that names no live deal is a validation failure on that field, not a 500. */
    public function test_that_an_unknown_deal_is_refused_on_the_deal_id_field(): void
    {
        try {
            $this->create($this->payload(lines: [], additional: [], overrides: ['deal_id' => Uuid::uuid4()->toString()]));
            self::fail('An unknown deal must be refused.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('deal_id', $e->errors());
        }
    }

    /**
     * §6.2 carries both `deal` and `customer`; the owner ruled (2026-09-11) that
     * the quotation's customer must be its deal's. A mismatch is refused on
     * `customer_id`, before anything is priced or written.
     */
    public function test_that_a_customer_other_than_the_deals_is_refused(): void
    {
        $otherCustomer = $this->customer(false);

        try {
            $this->create($this->payload(lines: [], additional: [], overrides: ['customer_id' => $otherCustomer]));
            self::fail('A quotation addressed to somebody other than its deal\'s customer must be refused.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('customer_id', $e->errors());
        }

        self::assertSame(0, DB::table('quotations')->count());
    }

    // ─────────────────────────────────────────────────────────────────── D-119

    /** A quotation walks its deal up to `supplier_quotation`, one status line per edge passed. */
    public function test_that_a_quotation_walks_its_deal_to_supplier_quotation_one_line_per_step(): void
    {
        DB::table('deals')->where('id', $this->dealId)->update(['status' => 'contacted']);
        $line = $this->supplierLine('100', '1', $this->egpId);

        $this->create($this->payload(
            lines: [['supplier_quotation_item_id' => $line, 'quantity' => '1', 'margin_percent' => null]],
            additional: [],
            overrides: [],
        ));

        self::assertSame('supplier_quotation', DB::table('deals')->where('id', $this->dealId)->value('status'));
        // jsonb text, compared as stored; order-free, since UUIDv7 ids made in one
        // millisecond are not proven ordered — each step passed EDGES anyway.
        self::assertEqualsCanonicalizing(
            ['{"status": "waiting_customer_request"}', '{"status": "supplier_rfq"}', '{"status": "supplier_quotation"}'],
            DB::table('audit_log')->where('event', 'DEAL_STATUS_CHANGED')->where('entity_id', $this->dealId)
                ->pluck('new_values')->all(),
        );
    }

    /**
     * A failed save rolls the walk back with it: `DealOutcomeInterface` runs inside the caller's transaction.
     * QS.2a: no supplier offer can block a line any more (`D-155`), so the failure is a line naming no
     * supplier — refused at the boundary, and by `quotation_items.supplier_id`'s foreign key under it.
     */
    public function test_that_a_failed_save_leaves_its_deal_where_it_was(): void
    {
        DB::table('deals')->where('id', $this->dealId)->update(['status' => 'contacted']);

        try {
            $this->create([
                ...$this->e1Body(['supplier_id' => Uuid::uuid4()->toString()] + $this->lineBody()),
                'deal_id' => $this->dealId,
                'customer_id' => $this->customerId,
            ]);
            self::fail('A line naming no supplier cannot be saved.');
        } catch (QueryException|ValidationException) {
        }

        self::assertSame('contacted', DB::table('deals')->where('id', $this->dealId)->value('status'));
        self::assertSame(0, DB::table('audit_log')->where('event', 'DEAL_STATUS_CHANGED')->count());
        self::assertSame(0, DB::table('quotations')->count());
    }

    // ────────────────────────────────────────────────────────────────── helpers

    private function currency(string $code, string $unit, bool $enabled, bool $base): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('currencies')->insert([
            'id' => $id,
            'code' => $code,
            'rounding_unit' => $unit,
            'rounding_enabled' => $enabled,
            'is_base' => $base,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function customer(bool $exempt): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('customers')->insert([
            'id' => $id,
            'name' => 'Nile Trading',
            'is_tax_exempt' => $exempt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function deal(string $customerId, ?string $ownerId = null): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('deals')->insert([
            'id' => $id,
            'code' => 'DL-'.now()->format('Y').'-'.substr($id, 0, 4),
            'customer_id' => $customerId,
            'owner_id' => $ownerId,
            'last_activity_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** Seeds §4.1's chain and returns the `supplier_quotation_items` id a line points at. */
    private function supplierLine(string $unitPrice, string $quantity, string $currencyId): string
    {
        $supplierId = Uuid::uuid4()->toString();
        $catalogItemId = Uuid::uuid4()->toString();
        $offerId = Uuid::uuid4()->toString();
        $lineId = Uuid::uuid4()->toString();

        DB::table('suppliers')->insert([
            'id' => $supplierId, 'name' => 'Alpha Supplies',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('catalog_items')->insert([
            'id' => $catalogItemId, 'kind' => 'product', 'name' => 'Widget',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('supplier_quotations')->insert([
            'id' => $offerId,
            'code' => 'SQ-'.now()->format('Y').'-'.substr($lineId, 0, 4),
            'supplier_id' => $supplierId,
            'total_price' => '1',
            'currency_id' => $currencyId,
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

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array<string, mixed>>  $additional
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $lines, array $additional, array $overrides): array
    {
        return array_merge([
            'deal_id' => $this->dealId,
            'customer_id' => $this->customerId,
            'currency' => 'EGP',
            'default_margin' => '20',
            'discount_percent' => '0',
            'lines' => $lines,
            'additional_items' => $additional,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  list<string>  $heldScopes  §3.2 codes; `all` by default so the pricing tests stay about pricing
     */
    private function create(array $validated, array $heldScopes = ['all']): QuotationCreated
    {
        return $this->app->make(CreateQuotation::class)->create($validated, $heldScopes, $this->actorId);
    }

    private function stored(string $id): Quotation
    {
        $row = Quotation::query()->findOrFail($id);
        self::assertInstanceOf(Quotation::class, $row);

        return $row;
    }
}
