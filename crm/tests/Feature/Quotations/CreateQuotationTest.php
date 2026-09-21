<?php

declare(strict_types=1);

namespace Tests\Feature\Quotations;

use App\Modules\Identity\Domain\Rbac\AuthorizationRefused;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Quotations\Application\Writing\CreateQuotation;
use App\Modules\Quotations\Application\Writing\QuotationCreated;
use App\Modules\Quotations\Domain\Pricing\QuotationNotPriceable;
use App\Modules\Quotations\Infrastructure\Eloquent\Quotation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Module 7, Point 3.3 — `CreateQuotation`, the create §5 and §6 need.
 *
 * The highest-priority test surface in the project (`CLAUDE.md`): the full §5.1
 * line arithmetic, §5.2's totals in `D-64`'s order, `D-63`'s tax derivation,
 * `D-09`'s per-line FX capture, `D-65`'s optional rounding, and §5.6's two
 * outcomes — a missing price blocks, an over-quantity only warns.
 *
 * Driven through the use case, not HTTP: `POST /quotations` is Point 3.4. The
 * supplier prices are seeded as real rows because §5.6 puts them on the supplier
 * side and this use case reads them there.
 */
final class CreateQuotationTest extends TestCase
{
    use RefreshDatabase;

    private string $actorId;

    private string $customerId;

    private string $dealId;

    private string $egpId;

    private string $usdId;

    protected function setUp(): void
    {
        parent::setUp();

        $actorId = User::factory()->create()->getKey();
        self::assertIsString($actorId);
        $this->actorId = $actorId;

        $this->egpId = $this->currency('EGP', '1', true, true);
        $this->usdId = $this->currency('USD', '0.01', true, false);
        $this->customerId = $this->customer(false);
        $this->dealId = $this->deal($this->customerId);
    }

    // ─────────────────────────────────────────────── §5.1 · §5.2 · D-09 · D-64

    /**
     * The whole of §5, priced end to end: two USD lines converted to an EGP
     * quotation at the captured rate, a line margin overriding the quotation's,
     * an untaxed additional item, a discount taken **before** tax, and 14% tax on
     * the reduced base. Every figure is asserted against §5.1/§5.2 by hand.
     */
    public function test_that_a_quotation_is_priced_end_to_end(): void
    {
        $this->fxRate($this->usdId, $this->egpId, '30');
        $line1 = $this->supplierLine('1000', '5', $this->usdId);
        $line2 = $this->supplierLine('500', '10', $this->usdId);

        $result = $this->create($this->payload(
            lines: [
                ['supplier_quotation_item_id' => $line1, 'quantity' => '2', 'margin_percent' => null],
                ['supplier_quotation_item_id' => $line2, 'quantity' => '3', 'margin_percent' => '50'],
            ],
            additional: [['description' => 'Delivery', 'amount' => '1000']],
            overrides: ['default_margin' => '20', 'discount_percent' => '10', 'tax_percent' => '14'],
        ));

        self::assertSame([], $result->quantityWarnings);

        $row = $this->stored($result->quotation->id);
        self::assertSame($this->egpId, $row->currency_id);
        self::assertSame('14.000', $row->tax_percent);
        self::assertSame('139500.000000', $row->subtotal);       // 72000 + 67500
        self::assertSame('1000.000000', $row->additional_total);
        self::assertSame('13950.000000', $row->discount_amount);  // 139500 × 10%
        self::assertSame('125550.000000', $row->tax_base);        // subtotal − discount (D-64)
        self::assertSame('17577.000000', $row->tax_amount);       // 125550 × 14%
        self::assertSame('126550.000000', $row->net_amount);      // 139500 + 1000 − 13950
        self::assertSame('144127.000000', $row->total_before_round);
        self::assertSame('144127.000000', $row->final_total);
        self::assertSame('0.000000', $row->rounding_diff);

        // §5.1 per line, with the supplier currency and rate captured (D-09).
        $lines = DB::table('quotation_items')
            ->where('quotation_id', $row->id)
            ->orderBy('line_no')
            ->get(['unit_cost', 'unit_cost_currency', 'unit_cost_fx_rate_at_time', 'unit_cost_base',
                'margin_percent', 'unit_price', 'line_total', 'line_cost'])
            ->map(static fn (object $r): array => (array) $r)
            ->all();

        self::assertSame([
            [
                'unit_cost' => '1000.000000', 'unit_cost_currency' => 'USD',
                'unit_cost_fx_rate_at_time' => '30.00000000', 'unit_cost_base' => '30000.000000',
                'margin_percent' => null, 'unit_price' => '36000.000000',   // 30000 × 1.20
                'line_total' => '72000.000000', 'line_cost' => '60000.000000',
            ],
            [
                'unit_cost' => '500.000000', 'unit_cost_currency' => 'USD',
                'unit_cost_fx_rate_at_time' => '30.00000000', 'unit_cost_base' => '15000.000000',
                'margin_percent' => '50.000', 'unit_price' => '22500.000000',  // 15000 × 1.50 (line margin wins)
                'line_total' => '67500.000000', 'line_cost' => '45000.000000',
            ],
        ], $lines);

        // AUD-01: the create is recorded.
        self::assertSame(1, DB::table('audit_log')
            ->where('event', 'QUOTATION_CREATED')
            ->where('entity_id', $row->id)
            ->count());
    }

    // ─────────────────────────────────────────────────────────────────── D-63

    /** An exempt customer renders no tax line at all, even when the request sends a rate. */
    public function test_that_an_exempt_customer_gets_no_tax_line(): void
    {
        $exempt = $this->customer(true);
        $deal = $this->deal($exempt);
        $line = $this->supplierLine('100', '1', $this->egpId);

        $result = $this->create($this->payload(
            lines: [['supplier_quotation_item_id' => $line, 'quantity' => '1', 'margin_percent' => null]],
            additional: [],
            overrides: ['customer_id' => $exempt, 'deal_id' => $deal, 'tax_percent' => '14'],
        ));

        $row = $this->stored($result->quotation->id);
        self::assertNull($row->tax_percent);
        self::assertNull($row->tax_amount);
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

    /** Rounding off (`D-65`): the total is left exact and `rounding_diff` is zero. */
    public function test_that_rounding_off_leaves_the_total_unrounded(): void
    {
        $eur = $this->currency('EUR', '0.01', false, false);
        $line = $this->supplierLine('100.755', '1', $eur);

        $result = $this->create($this->payload(
            lines: [['supplier_quotation_item_id' => $line, 'quantity' => '1', 'margin_percent' => null]],
            additional: [],
            overrides: ['currency' => 'EUR', 'default_margin' => '0', 'discount_percent' => '0'],
        ));

        $row = $this->stored($result->quotation->id);
        self::assertFalse($row->rounding_enabled);
        self::assertSame('100.755000', $row->final_total);
        self::assertSame('0.000000', $row->rounding_diff);
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
     * `team` resolves to nothing (no team entity — `QuotationRowScope`'s recorded
     * gap), so a Team Leader's `Team` create permits no deal. Fail-closed, and
     * asserted by name so the gap stays visible until a team exists.
     */
    public function test_that_a_team_scoped_caller_is_refused_until_teams_exist(): void
    {
        $this->expectException(AuthorizationRefused::class);

        $this->create($this->payload(lines: [], additional: [], overrides: [
            'deal_id' => $this->deal($this->customerId, $this->actorId),
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

    // ────────────────────────────────────────────────────────────── §5.6 block

    /** A supplier line whose price is gone blocks the whole save — nothing is written. */
    public function test_that_a_missing_supplier_price_blocks_and_writes_nothing(): void
    {
        $line = $this->supplierLine('100', '1', $this->egpId);
        DB::table('supplier_quotation_items')->where('id', $line)->update(['deleted_at' => now()]);

        try {
            $this->create($this->payload(
                lines: [['supplier_quotation_item_id' => $line, 'quantity' => '1', 'margin_percent' => null]],
                additional: [],
                overrides: [],
            ));
            self::fail('A missing supplier price must block the save (§5.6).');
        } catch (QuotationNotPriceable $e) {
            self::assertSame(QuotationNotPriceable::SUPPLIER_PRICE_MISSING, $e->reason);
        }

        self::assertSame(0, DB::table('quotations')->count());
        self::assertSame(0, DB::table('quotation_items')->count());
    }

    /** A supplier currency the quotation cannot convert — no FX rate — blocks too (`D-09`). */
    public function test_that_a_missing_fx_rate_blocks(): void
    {
        $line = $this->supplierLine('1000', '1', $this->usdId);   // USD line, EGP quotation, no rate seeded

        try {
            $this->create($this->payload(
                lines: [['supplier_quotation_item_id' => $line, 'quantity' => '1', 'margin_percent' => null]],
                additional: [],
                overrides: [],
            ));
            self::fail('A pair with no FX rate cannot be priced at creation (D-09).');
        } catch (QuotationNotPriceable $e) {
            self::assertSame(QuotationNotPriceable::FX_RATE_MISSING, $e->reason);
        }

        self::assertSame(0, DB::table('quotations')->count());
    }

    // ────────────────────────────────────────────────────────────── §5.6 warn

    /** A requested quantity above the supplier's recorded amount warns and does not block. */
    public function test_that_a_quantity_above_the_recorded_amount_warns_without_blocking(): void
    {
        $line = $this->supplierLine('100', '5', $this->egpId);

        $result = $this->create($this->payload(
            lines: [['supplier_quotation_item_id' => $line, 'quantity' => '100', 'margin_percent' => null]],
            additional: [],
            overrides: [],
        ));

        self::assertSame([1], $result->quantityWarnings);
        self::assertSame(1, DB::table('quotations')->where('id', $result->quotation->id)->count());
    }

    /**
     * `D-81` (F-05 · 1.4): the ceiling §5.6 warns against is what is left of
     * the offer, not what it started as. 5 recorded, 3 already drawn → 4 is
     * within the offer and still over the balance.
     */
    public function test_that_a_quantity_within_the_recorded_amount_but_above_the_available_balance_warns(): void
    {
        $line = $this->supplierLine('100', '5', $this->egpId);
        DB::table('supplier_quotation_items')->where('id', $line)->update(['consumed_quantity' => '3']);

        $result = $this->create($this->payload(
            lines: [['supplier_quotation_item_id' => $line, 'quantity' => '4', 'margin_percent' => null]],
            additional: [],
            overrides: [],
        ));

        self::assertSame([1], $result->quantityWarnings);
        self::assertSame(1, DB::table('quotations')->where('id', $result->quotation->id)->count());
    }

    public function test_that_a_quantity_at_exactly_the_available_balance_does_not_warn(): void
    {
        $line = $this->supplierLine('100', '5', $this->egpId);
        DB::table('supplier_quotation_items')->where('id', $line)->update(['consumed_quantity' => '3']);

        $result = $this->create($this->payload(
            lines: [['supplier_quotation_item_id' => $line, 'quantity' => '2', 'margin_percent' => null]],
            additional: [],
            overrides: [],
        ));

        self::assertSame([], $result->quantityWarnings);
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

    private function fxRate(string $fromId, string $toId, string $rate): void
    {
        DB::table('fx_rates')->insert([
            'id' => Uuid::uuid4()->toString(),
            'from_currency_id' => $fromId,
            'to_currency_id' => $toId,
            'rate' => $rate,
            'effective_from' => now()->subDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
