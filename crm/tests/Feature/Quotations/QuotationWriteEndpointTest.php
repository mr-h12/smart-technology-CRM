<?php

declare(strict_types=1);

namespace Tests\Feature\Quotations;

use App\Modules\Idempotency\Domain\IdempotencyRefused;
use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Quotations\Application\Writing\CreateQuotation;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Tests\TestCase;

/**
 * `POST /api/v1/quotations` — Module 7 Point 3.4.
 *
 * `CreateQuotationTest` proves §5's arithmetic; this file proves the boundary
 * around it: §3.5's `create` row at the API (`SEC-07`, `SEC-08`), the Form
 * Request's mirror of the table's CHECKs (so the database never answers 500),
 * and `OpenAPI §4.1`'s `201`. QS.2a (`D-155`…`D-165`): the body a line is
 * typed with, `D-156`'s worked examples stored to the figure, and one refusal
 * per field the new body takes or no longer takes.
 */
final class QuotationWriteEndpointTest extends TestCase
{
    use QuotationLineFixtures;
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/quotations';

    private const PASSWORD = 'Passw0rd123';

    /** @var array<string, User> */
    private array $users = [];

    private string $egpId;

    private string $customerId;

    /** An unowned deal — `owner_id` null — that only an `all` scope may quote. */
    private string $dealId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->egpId = $this->currency('EGP', '1', false, true);
        $this->customerId = $this->customer();
        $this->dealId = $this->deal($this->customerId, null);
    }

    // ────────────────────────────────────────────────────────── authorisation

    public function test_that_an_unauthenticated_caller_cannot_create(): void
    {
        $this->postJson(self::ENDPOINT, $this->payload())->assertStatus(401);
    }

    /** §3.5 `create` = `All` — the Manager quotes anybody's deal, an unowned one included. */
    public function test_that_the_manager_can_quote_any_deal(): void
    {
        $this->postJson(self::ENDPOINT, $this->payload(), $this->bearerFor(RoleName::Manager))->assertStatus(201);
    }

    /**
     * §3.5 `create` = `Own` for both Sales roles, and the owner ruled that "own"
     * is the deal's `owner_id`: their own deal is quotable …
     *
     * @return array<string, array{RoleName}>
     */
    public static function ownScoped(): array
    {
        return [
            'outdoor sales' => [RoleName::OutdoorSales],
            'indoor sales' => [RoleName::IndoorSales],
        ];
    }

    #[DataProvider('ownScoped')]
    public function test_that_an_own_scoped_role_can_quote_its_own_deal(RoleName $role): void
    {
        $ownerId = $this->userWith($role)->getKey();
        self::assertIsString($ownerId);
        $ownDeal = $this->deal($this->customerId, $ownerId);

        $this->postJson(self::ENDPOINT, $this->payload(['deal_id' => $ownDeal]), $this->bearerFor($role))
            ->assertStatus(201);
    }

    /** … and somebody else's is a 403 that reveals nothing about the deal. */
    #[DataProvider('ownScoped')]
    public function test_that_an_own_scoped_role_cannot_quote_another_owners_deal(RoleName $role): void
    {
        $otherId = $this->userWith(RoleName::Manager)->getKey();
        self::assertIsString($otherId);
        $othersDeal = $this->deal($this->customerId, $otherId);

        $this->postJson(self::ENDPOINT, $this->payload(['deal_id' => $othersDeal]), $this->bearerFor($role))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'permission_denied');

        self::assertSame(0, DB::table('quotations')->count());
    }

    #[DataProvider('ownScoped')]
    public function test_that_an_own_scoped_role_cannot_quote_an_unowned_deal(RoleName $role): void
    {
        $this->postJson(self::ENDPOINT, $this->payload(), $this->bearerFor($role))->assertStatus(403);
    }

    /**
     * §3.5 `create` = `Team` for the Team Leader, backed by `D-138`: the deals
     * the leader or a member (`team_leader_id` = the leader) owns are quotable.
     */
    public function test_that_the_team_leader_can_quote_their_own_and_a_members_deal(): void
    {
        $leaderId = $this->userWith(RoleName::TeamLeader)->getKey();
        self::assertIsString($leaderId);
        $member = $this->userWith(RoleName::IndoorSales);
        $member->forceFill(['team_leader_id' => $leaderId])->save();
        $memberId = $member->getKey();
        self::assertIsString($memberId);

        // One bearer per POST: each carries a fresh `Idempotency-Key` (§9.1).
        $this->postJson(self::ENDPOINT, $this->payload(['deal_id' => $this->deal($this->customerId, $leaderId)]), $this->bearerFor(RoleName::TeamLeader))
            ->assertStatus(201);
        $this->postJson(self::ENDPOINT, $this->payload(['deal_id' => $this->deal($this->customerId, $memberId)]), $this->bearerFor(RoleName::TeamLeader))
            ->assertStatus(201);
    }

    /** … and a deal outside the team is the own-scoped roles' 403 that reveals nothing. */
    public function test_that_the_team_leader_cannot_quote_a_deal_outside_the_team(): void
    {
        $outsiderId = $this->userWith(RoleName::OutdoorSales)->getKey();
        self::assertIsString($outsiderId);
        $outsidersDeal = $this->deal($this->customerId, $outsiderId);

        $this->postJson(self::ENDPOINT, $this->payload(['deal_id' => $outsidersDeal]), $this->bearerFor(RoleName::TeamLeader))
            ->assertStatus(403);
    }

    /**
     * §3.5's dashes under `create`: the CEO (read-only), the Outdoor Supervisor
     * and Procurement.
     *
     * @return array<string, array{RoleName}>
     */
    public static function nonWriters(): array
    {
        return [
            'ceo' => [RoleName::Ceo],
            'outdoor supervisor' => [RoleName::OutdoorSupervisor],
            'procurement' => [RoleName::Procurement],
        ];
    }

    #[DataProvider('nonWriters')]
    public function test_that_a_role_without_the_grant_cannot_create(RoleName $role): void
    {
        $this->postJson(self::ENDPOINT, $this->payload(), $this->bearerFor($role))->assertStatus(403);
    }

    /** `SEC-07`: the grant is a database row, and withdrawing it withdraws the route. */
    public function test_that_withdrawing_the_grant_refuses_a_role_that_had_it(): void
    {
        DB::table('permissions')->where('resource', 'quotation')->where('action', 'create')->delete();

        $this->postJson(self::ENDPOINT, $this->payload(), $this->bearerFor(RoleName::Manager))->assertStatus(403);
    }

    /** §3.5 `edit margin` / `edit tax` are their own cells: a create that sets a line's margin or tax asks for them. */
    public function test_that_a_create_with_a_line_margin_needs_the_edit_margin_grant(): void
    {
        DB::table('permissions')->where('resource', 'quotation')->where('action', 'edit_margin')->delete();

        $this->postBody($this->e3Body())->assertStatus(403);
    }

    public function test_that_a_create_with_a_line_tax_needs_the_edit_tax_grant(): void
    {
        DB::table('permissions')->where('resource', 'quotation')->where('action', 'edit_tax')->delete();

        $this->postBody($this->e1Body($this->lineBody(margin: '0')))->assertStatus(403);
    }

    public function test_that_a_create_with_a_margin_and_a_tax_succeeds_with_the_grants(): void
    {
        $this->postBody($this->e1Body())->assertStatus(201);
    }

    // ────────────────────────────────────────────────── what a 201 carries

    /** `OpenAPI §4.1`'s example, to the field: `{id, code}` and `meta.request_id`. */
    public function test_that_a_created_quotation_answers_with_its_id_and_code(): void
    {
        $response = $this->postJson(self::ENDPOINT, $this->payload(), $this->bearerFor(RoleName::Manager))
            ->assertStatus(201)
            ->assertJsonStructure(['data' => ['id', 'code'], 'meta' => ['request_id']]);

        $code = $response->json('data.code');
        self::assertIsString($code);
        self::assertMatchesRegularExpression('/^QT-\d{4}-\d{4}$/', $code);
        self::assertSame(['id', 'code'], array_keys((array) $response->json('data')));
    }

    /**
     * QS.2a · `D-156` example 1, stored to the figure: 114 typed including 14 % is 100 net (the unit cost,
     * `D-164`: in the quotation's own currency at rate 1), +20 % is 120.00, × 3 is 360.00; 10 % off before the
     * tax (`D-64`), the line's own 14 % on 324.00 is 45.36 (`D-157`), the delivery untaxed (`D-62`) → 419.36.
     * No quotation margin, no quotation tax rates, no supplier offer behind the line (`D-155`). `D-161`: no
     * warning, so no `meta.warnings` key — a client testing it for truthiness and for presence agree.
     */
    public function test_that_a_tax_included_line_is_priced_from_what_was_typed(): void
    {
        $line = $this->lineBody();

        $id = $this->postBody($this->e1Body($line))
            ->assertStatus(201)
            ->assertJsonMissingPath('meta.warnings')
            ->json('data.id');
        self::assertIsString($id);

        self::assertSame([
            'pricing_mode' => 'tax_included', 'default_margin' => null, 'tax_percent' => null, 'tax_percents' => '[]',
            'subtotal' => '360.000000', 'discount_amount' => '36.000000', 'tax_base' => '324.000000', 'tax_amount' => '45.360000',
            'additional_total' => '50.000000', 'net_amount' => '374.000000', 'total_before_round' => '419.360000',
            'final_total' => '419.360000', 'rounding_diff' => '0.000000',
        ], (array) DB::table('quotations')->where('id', $id)->first([
            'pricing_mode', 'default_margin', 'tax_percent', 'tax_percents', 'subtotal', 'discount_amount', 'tax_base', 'tax_amount',
            'additional_total', 'net_amount', 'total_before_round', 'final_total', 'rounding_diff',
        ]));

        self::assertSame([
            'supplier_quotation_item_id' => null, 'supplier_id' => $line['supplier_id'], 'catalog_item_id' => $line['catalog_item_id'],
            'unit_code' => 'piece', 'entered_unit_price' => '114.000000', 'tax_percent' => '14.000', 'margin_percent' => '20.000',
            'unit_cost' => '100.000000', 'unit_cost_currency' => 'EGP', 'unit_cost_fx_rate_at_time' => '1.00000000', 'unit_cost_base' => '100.000000',
            'unit_price' => '120.000000', 'quantity' => '3.0000', 'line_total' => '360.000000', 'line_cost' => '300.000000',
        ], (array) DB::table('quotation_items')->where('quotation_id', $id)->first([
            'supplier_quotation_item_id', 'supplier_id', 'catalog_item_id', 'unit_code', 'entered_unit_price', 'tax_percent', 'margin_percent',
            'unit_cost', 'unit_cost_currency', 'unit_cost_fx_rate_at_time', 'unit_cost_base', 'unit_price', 'quantity', 'line_total', 'line_cost',
        ]));
    }

    /** `D-65` (`D-165`: unchanged): the quotation currency's rounding, when on, rounds the final total alone — 419.36 → 419, −0.36 stored. */
    public function test_that_the_currencys_rounding_rounds_a_tax_included_total(): void
    {
        DB::table('currencies')->where('id', $this->egpId)->update(['rounding_enabled' => true]);

        $id = $this->postBody($this->e1Body())->assertStatus(201)->json('data.id');
        self::assertIsString($id);

        self::assertSame(
            ['total_before_round' => '419.360000', 'final_total' => '419.000000', 'rounding_diff' => '-0.360000'],
            (array) DB::table('quotations')->where('id', $id)->first(['total_before_round', 'final_total', 'rounding_diff']),
        );
    }

    /**
     * `D-157` example 3, the toggle off: no tax at all — no tax line, not a zero (`D-63`) — and the typed 100
     * used as-is, no extraction: 120.00 × 3 = 360.00 − 36.00 + 50.00 = 374.00.
     */
    public function test_that_a_tax_exempt_quotation_has_no_tax_line_and_uses_the_typed_price(): void
    {
        $id = $this->postBody($this->e3Body())->assertStatus(201)->json('data.id');
        self::assertIsString($id);

        self::assertSame(
            ['pricing_mode' => 'tax_exempt', 'tax_percent' => null, 'tax_amount' => null, 'net_amount' => '374.000000', 'final_total' => '374.000000'],
            (array) DB::table('quotations')->where('id', $id)->first(['pricing_mode', 'tax_percent', 'tax_amount', 'net_amount', 'final_total']),
        );
        self::assertSame(
            ['entered_unit_price' => '100.000000', 'tax_percent' => null, 'unit_cost_base' => '100.000000', 'unit_price' => '120.000000', 'line_total' => '360.000000'],
            (array) DB::table('quotation_items')->where('quotation_id', $id)->first(['entered_unit_price', 'tax_percent', 'unit_cost_base', 'unit_price', 'line_total']),
        );
    }

    public function test_that_a_create_is_written_to_the_audit_log(): void
    {
        $id = $this->postJson(self::ENDPOINT, $this->payload(), $this->bearerFor(RoleName::Manager))
            ->assertStatus(201)
            ->json('data.id');

        $row = DB::table('audit_log')->where('event', 'QUOTATION_CREATED')->where('entity_id', $id)->first();

        self::assertNotNull($row);
        self::assertSame($this->userWith(RoleName::Manager)->getKey(), $row->user_id);
    }

    /** `D-103`: the terms a quotation is created with come back as sent, in order. */
    public function test_that_a_quotation_is_created_with_its_terms(): void
    {
        $terms = [
            ['key' => 'payment_terms', 'title' => null, 'body' => '50% advance'],
            ['key' => 'warranty', 'title' => 'Guarantee', 'body' => 'One year'],
            ['key' => null, 'title' => 'Installation', 'body' => 'On site, two days'],
        ];

        $id = $this->postJson(self::ENDPOINT, $this->payload(['terms' => $terms]), $this->bearerFor(RoleName::Manager))
            ->assertStatus(201)
            ->json('data.id');
        self::assertIsString($id);

        self::assertEquals($terms, $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))->json('data.terms'));
    }

    /** A quotation created without terms has an empty list, never `null`. */
    public function test_that_a_quotation_created_without_terms_has_an_empty_list(): void
    {
        $id = $this->postJson(self::ENDPOINT, $this->payload(), $this->bearerFor(RoleName::Manager))
            ->assertStatus(201)
            ->json('data.id');
        self::assertIsString($id);

        self::assertSame([], $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))->json('data.terms'));
    }

    public function test_that_sixteen_terms_are_refused_on_create(): void
    {
        $this->post422(['terms' => array_fill(0, 16, ['key' => null, 'title' => 'Note', 'body' => 'Text'])], 'terms');
    }

    /** D-166 (proposed, owner 2026-10-23): a quotation takes up to 25 distinct suppliers. */
    public function test_that_a_twenty_sixth_supplier_is_refused_on_create(): void
    {
        $lines = array_map(fn (): array => $this->lineBody(), range(1, 26));

        $this->postBody([...$this->e1Body(), 'lines' => $lines])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonFragment(['field' => 'lines', 'code' => 'invalid']);
    }

    public function test_that_twenty_five_suppliers_are_accepted_on_create(): void
    {
        $lines = array_map(fn (): array => $this->lineBody(), range(1, 25));

        $this->postBody([...$this->e1Body(), 'lines' => $lines])->assertStatus(201);
    }

    // ──────────────────────────────── the boundary mirrors the table's CHECKs

    /** `DB-07`: a JSON number is a float by the time PHP sees it; only a decimal string passes. */
    public function test_that_a_json_number_is_refused_where_a_decimal_string_is_required(): void
    {
        $this->post422(['lines' => [['quantity' => 2.5] + $this->lineBody()]], 'lines.0.quantity');
        $this->post422(['lines' => [['entered_unit_price' => 114] + $this->lineBody()]], 'lines.0.entered_unit_price');
        $this->post422(['discount_percent' => 0], 'discount_percent');
    }

    /** `D-118`: the old single field is refused, not silently dropped with its tax. */
    public function test_that_the_old_single_tax_percent_is_refused(): void
    {
        $this->postJson(self::ENDPOINT, $this->payload(['tax_percent' => '14']), $this->bearerFor(RoleName::Manager))
            ->assertStatus(422)
            ->assertJsonFragment(['field' => 'tax_percent']);
    }

    /**
     * QS.2a (contract §4): each field the line-owned body requires, and each one it no longer takes — the
     * quotation's margin and tax rates (`D-156`, `D-157`), the `legacy` mode (`D-165`), a supplier offer line
     * (`D-155`) — refused on its own field; and every bound a column or a CHECK would otherwise answer with a
     * 500: `entered_unit_price >= 0`, `tax_percent` NUMERIC(6,3) and `>= 0`, `unit_price >= 0` (so a margin
     * floor of −100 %, now that no quotation margin moves it, `D-106` retired by `D-156`).
     *
     * @return array<string, array{array<string, mixed>, list<string>, string}> what to set, what to remove, the refused field
     */
    public static function refusedLineOwnedBodies(): array
    {
        return [
            'no pricing mode' => [[], ['pricing_mode'], 'pricing_mode'],
            'the legacy mode' => [['pricing_mode' => 'legacy'], [], 'pricing_mode'],
            'a quotation margin' => [['default_margin' => '20'], [], 'default_margin'],
            'quotation tax rates' => [['tax_percents' => ['14']], [], 'tax_percents'],
            'a line without its supplier' => [[], ['lines.0.supplier_id'], 'lines.0.supplier_id'],
            'a line without its product' => [[], ['lines.0.catalog_item_id'], 'lines.0.catalog_item_id'],
            'a line without its unit' => [[], ['lines.0.unit_code'], 'lines.0.unit_code'],
            'a line without its entered price' => [[], ['lines.0.entered_unit_price'], 'lines.0.entered_unit_price'],
            'a line without its quantity' => [[], ['lines.0.quantity'], 'lines.0.quantity'],
            'a line without its margin' => [[], ['lines.0.margin_percent'], 'lines.0.margin_percent'],
            'a tax-included line without its tax' => [[], ['lines.0.tax_percent'], 'lines.0.tax_percent'],
            'a tax on a tax-exempt quotation' => [['pricing_mode' => 'tax_exempt'], [], 'lines.0.tax_percent'],
            'a supplier offer line' => [['lines.0.supplier_quotation_item_id' => Uuid::uuid4()->toString()], [], 'lines.0.supplier_quotation_item_id'],
            'an unknown supplier' => [['lines.0.supplier_id' => Uuid::uuid4()->toString()], [], 'lines.0.supplier_id'],
            'an unknown product' => [['lines.0.catalog_item_id' => Uuid::uuid4()->toString()], [], 'lines.0.catalog_item_id'],
            'an unknown unit' => [['lines.0.unit_code' => 'parsec'], [], 'lines.0.unit_code'],
            'a negative entered price' => [['lines.0.entered_unit_price' => '-1'], [], 'lines.0.entered_unit_price'],
            'a negative tax' => [['lines.0.tax_percent' => '-1'], [], 'lines.0.tax_percent'],
            'a tax finer than three places' => [['lines.0.tax_percent' => '0.0004'], [], 'lines.0.tax_percent'],
            'a tax past its column' => [['lines.0.tax_percent' => '1000'], [], 'lines.0.tax_percent'],
            'a margin below minus a hundred' => [['lines.0.margin_percent' => '-100.001'], [], 'lines.0.margin_percent'],
            'a margin finer than three places' => [['lines.0.margin_percent' => '20.0009'], [], 'lines.0.margin_percent'],
        ];
    }

    /**
     * @param  array<string, mixed>  $set
     * @param  list<string>  $forget
     */
    #[DataProvider('refusedLineOwnedBodies')]
    public function test_that_a_line_owned_body_is_refused_on_the_field_at_fault(array $set, array $forget, string $field): void
    {
        $body = $this->e1Body();
        foreach ($set as $path => $value) {
            Arr::set($body, $path, $value);
        }
        Arr::forget($body, $forget);
        /** @var array<string, mixed> $body Arr::set() and Arr::forget() keep the top-level keys strings */
        $this->postBody($body)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonFragment(['field' => $field, 'code' => 'invalid']);

        self::assertSame(0, DB::table('quotations')->count());
    }

    /** CHECK `discount_percent >= 0 AND < 100`. */
    public function test_that_a_full_discount_is_refused(): void
    {
        $this->post422(['discount_percent' => '100'], 'discount_percent');
    }

    /** CHECK `valid_until >= quotation_date`. */
    public function test_that_an_expiry_before_the_quotation_date_is_refused(): void
    {
        $this->post422(['quotation_date' => '2026-09-11', 'valid_until' => '2026-09-10'], 'valid_until');
    }

    public function test_that_an_unoffered_currency_code_is_refused(): void
    {
        $this->post422(['currency' => 'GBP'], 'currency');
    }

    public function test_that_a_caller_supplied_code_is_refused(): void
    {
        $this->post422(['code' => 'QT-1999-9999'], 'code');
    }

    public function test_that_a_line_of_no_quantity_is_refused(): void
    {
        $line = $this->supplierLine('100', '5', $this->egpId);

        $this->post422(['lines' => [['supplier_quotation_item_id' => $line, 'quantity' => '0']]], 'lines.0.quantity');
    }

    public function test_that_a_blank_additional_item_is_refused(): void
    {
        $this->post422(['additional_items' => [['description' => '   ', 'amount' => '10']]], 'additional_items.0.description');
    }

    public function test_that_a_negative_additional_amount_is_refused(): void
    {
        $this->post422(['additional_items' => [['description' => 'Delivery', 'amount' => '-1']]], 'additional_items.0.amount');
    }

    /** `D-106`'s ruling (a), kept by `D-156` on the line's own margin: −20 sells 100 net at 80.00. */
    public function test_that_a_negative_line_margin_is_accepted(): void
    {
        $id = $this->postBody($this->e1Body($this->lineBody(margin: '-20')))->assertStatus(201)->json('data.id');

        self::assertSame('80.000000', DB::table('quotation_items')->where('quotation_id', $id)->value('unit_price'));
    }

    /** The bound itself: −100 % prices the line at zero, which CHECK `unit_price >= 0` allows; −100.001 is in the refusals above. */
    public function test_that_a_line_margin_of_exactly_minus_a_hundred_is_accepted(): void
    {
        $id = $this->postBody($this->e1Body($this->lineBody(margin: '-100')))->assertStatus(201)->json('data.id');

        self::assertSame('0.000000', DB::table('quotation_items')->where('quotation_id', $id)->value('unit_price'));
    }

    // ───────────────────────────────────── the deal, at the boundary

    public function test_that_an_unknown_deal_is_refused_on_its_field(): void
    {
        $this->post422(['deal_id' => Uuid::uuid4()->toString()], 'deal_id');
    }

    /** The owner's 2026-09-11 ruling: the quotation's customer is its deal's, or the request is wrong. */
    public function test_that_a_customer_other_than_the_deals_is_refused_on_its_field(): void
    {
        $this->post422(['customer_id' => $this->customer()], 'customer_id');
    }

    // ──────────────────────────────── `OpenAPI §9.1` — Idempotency-Key (Point 3.7)

    /** §9.1 requires the header on a critical create; §5.1's 400 is "invalid header". */
    public function test_that_a_create_without_an_idempotency_key_is_refused(): void
    {
        $headers = $this->bearerFor(RoleName::Manager);
        unset($headers['Idempotency-Key']);

        $this->postJson(self::ENDPOINT, $this->payload(), $headers)
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'invalid_request')
            ->assertJsonFragment(['field' => 'Idempotency-Key', 'code' => IdempotencyRefused::KEY_REQUIRED]);

        self::assertSame(0, DB::table('quotations')->count());
    }

    /** §9.1: same actor + route + key + payload → the original response, and no second quotation. */
    public function test_that_replaying_a_key_returns_the_original_response_without_a_second_quotation(): void
    {
        $headers = $this->bearerFor(RoleName::Manager);

        $first = $this->postJson(self::ENDPOINT, $this->payload(), $headers)->assertStatus(201);
        $replay = $this->postJson(self::ENDPOINT, $this->payload(), $headers)->assertStatus(201);

        self::assertSame($first->json(), $replay->json());
        self::assertSame(1, DB::table('quotations')->count());
        self::assertSame(1, DB::table('idempotency_keys')->count());
    }

    /** §9.1: same key, changed payload → `409 idempotency_conflict`, and the first quotation stands alone. */
    public function test_that_reusing_a_key_with_a_different_payload_is_a_conflict(): void
    {
        $headers = $this->bearerFor(RoleName::Manager);

        $this->postJson(self::ENDPOINT, $this->payload(), $headers)->assertStatus(201);
        $this->postJson(self::ENDPOINT, $this->payload(['discount_percent' => '5']), $headers)
            ->assertStatus(409)
            ->assertJsonPath('error.code', IdempotencyRefused::CONFLICT);

        self::assertSame(1, DB::table('quotations')->count());
    }

    /** §9.1: "every replay is checked against … current permission" — the grant goes, the replay goes with it. */
    public function test_that_a_replay_is_refused_once_the_grant_is_withdrawn(): void
    {
        $headers = $this->bearerFor(RoleName::Manager);

        $this->postJson(self::ENDPOINT, $this->payload(), $headers)->assertStatus(201);
        DB::table('permissions')->where('resource', 'quotation')->where('action', 'create')->delete();

        $this->postJson(self::ENDPOINT, $this->payload(), $headers)->assertStatus(403);
    }

    /** §9.1 keys on the actor: two people may pick the same key and each gets their own quotation. */
    public function test_that_another_actor_may_use_the_same_key(): void
    {
        $key = Uuid::uuid4()->toString();
        $manager = ['Idempotency-Key' => $key] + $this->bearerFor(RoleName::Manager);
        $ownerId = $this->userWith(RoleName::IndoorSales)->getKey();
        self::assertIsString($ownerId);
        $sales = ['Idempotency-Key' => $key] + $this->bearerFor(RoleName::IndoorSales);

        $this->postJson(self::ENDPOINT, $this->payload(), $manager)->assertStatus(201);
        $this->postJson(self::ENDPOINT, $this->payload(['deal_id' => $this->deal($this->customerId, $ownerId)]), $sales)
            ->assertStatus(201);

        self::assertSame(2, DB::table('quotations')->count());
    }

    /** §9.1 stores a "final status"; a 5xx is not one, so the key is given back and the same retry succeeds. */
    public function test_that_a_failed_create_releases_the_key_for_a_retry(): void
    {
        $headers = $this->bearerFor(RoleName::Manager);

        $this->app->bind(CreateQuotation::class, function (): never {
            throw new RuntimeException('simulated failure inside the use case');
        });
        $this->postJson(self::ENDPOINT, $this->payload(), $headers)->assertStatus(500);
        $this->app->offsetUnset(CreateQuotation::class);

        self::assertSame(0, DB::table('idempotency_keys')->count());

        $this->postJson(self::ENDPOINT, $this->payload(), $headers)->assertStatus(201);
        self::assertSame(1, DB::table('quotations')->count());
    }

    /**
     * A key whose first request has not finished — the row is claimed and
     * carries no response yet — is a reuse, not a replay: 409, and no second
     * create runs beside the first.
     */
    public function test_that_a_key_still_in_flight_is_a_conflict(): void
    {
        $headers = $this->bearerFor(RoleName::Manager);
        $userId = $this->userWith(RoleName::Manager)->getKey();
        self::assertIsString($userId);

        DB::table('idempotency_keys')->insert([
            'id' => Uuid::uuid4()->toString(),
            'user_id' => $userId,
            'route' => 'POST api/v1/quotations',
            'key' => $headers['Idempotency-Key'],
            'request_hash' => hash('sha256', (string) json_encode($this->payload())),
            'created_at' => now(),
        ]);

        $this->postJson(self::ENDPOINT, $this->payload(), $headers)
            ->assertStatus(409)
            ->assertJsonPath('error.code', IdempotencyRefused::CONFLICT);

        self::assertSame(0, DB::table('quotations')->count());
    }

    // ────────────────────────────────────────────────────────────── fixtures

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'deal_id' => $this->dealId,
            'customer_id' => $this->customerId,
            'currency' => 'EGP',
            'pricing_mode' => 'tax_exempt',
            'discount_percent' => '0',
            'lines' => [],
            'additional_items' => [],
        ], $overrides);
    }

    /**
     * QS.2a's body, `$body` (`e1Body()`, `e3Body()`) on setUp's deal, as the Manager.
     *
     * @param  array<string, mixed>  $body
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function postBody(array $body): TestResponse
    {
        return $this->postJson(self::ENDPOINT, ['deal_id' => $this->dealId, 'customer_id' => $this->customerId, ...$body], $this->bearerFor(RoleName::Manager));
    }

    /** @param  array<string, mixed>  $overrides */
    private function post422(array $overrides, string $field): void
    {
        $this->postJson(self::ENDPOINT, $this->payload($overrides), $this->bearerFor(RoleName::Manager))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonFragment(['field' => $field, 'code' => 'invalid']);
    }

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

    private function customer(): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('customers')->insert([
            'id' => $id,
            'name' => 'Nile Trading',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function deal(string $customerId, ?string $ownerId): string
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

    private function userWith(RoleName $role): User
    {
        if (isset($this->users[$role->value])) {
            return $this->users[$role->value];
        }

        $row = Role::query()->where('slug', $role->value)->firstOrFail();

        $user = new User;
        $user->fill([
            'name' => 'Test '.$role->label(),
            'email' => str_replace('_', '.', $role->value).'@example.test',
            'password' => self::PASSWORD,
            'role_id' => $row->id,
            'is_active' => true,
            'is_hidden' => $role->isHidden(),
        ]);
        $user->save();

        return $this->users[$role->value] = $user;
    }

    /** @return array<string, string> */
    private function bearerFor(RoleName $role): array
    {
        $user = $this->userWith($role);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertStatus(201)->json('data.token');

        self::assertIsString($token);

        // §9.1: every create here carries a fresh key unless a test pins one.
        return ['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => Uuid::uuid4()->toString()];
    }
}
