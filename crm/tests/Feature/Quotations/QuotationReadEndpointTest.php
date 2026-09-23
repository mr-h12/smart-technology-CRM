<?php

declare(strict_types=1);

namespace Tests\Feature\Quotations;

use App\Modules\Customers\Domain\Contracts\CustomerNamesInterface;
use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Quotations\Domain\Listing\QuotationLine;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * `GET /api/v1/quotations/{id}` — Module 7 Point 3.5.
 *
 * §3.5's `view` row at the API (`SEC-07`, `SEC-08`) with "own" resolved against
 * the deal's `owner_id` (owner ruling 2026-09-11); `OpenAPI §5.1`'s one 404 for
 * absent-or-invisible; `§9.2`'s `etag`; and `view cost & margin` deciding
 * whether the cost fields are in the body at all.
 */
final class QuotationReadEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/quotations';

    private const PASSWORD = 'Passw0rd123';

    /** @var array<string, User> */
    private array $users = [];

    private string $customerId;

    /** @var array<string, string> deal id => its customer, for `quotation()`'s POST */
    private array $dealCustomers = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->currency('EGP', '1', false, true);
        $this->customerId = $this->customer();
    }

    // ────────────────────────────────────────────────────────── authorisation

    public function test_that_an_unauthenticated_caller_cannot_read(): void
    {
        $this->getJson(self::ENDPOINT.'/'.Uuid::uuid4()->toString())->assertStatus(401);
    }

    /**
     * §3.5 `view` = `All` for Manager and CEO, and for the Team Leader and
     * Procurement since `D-91` (Module 10 · 2.2a) — an unowned deal's quotation
     * included.
     */
    #[DataProvider('unrestricted')]
    public function test_that_an_all_scoped_role_reads_any_quotation(RoleName $role): void
    {
        $id = $this->quotation($this->deal(null));

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor($role))
            ->assertStatus(200)
            ->assertJsonPath('data.id', $id);
    }

    /** @return array<string, array{RoleName}> */
    public static function unrestricted(): array
    {
        return [
            'manager' => [RoleName::Manager],
            'ceo' => [RoleName::Ceo],
            'team leader' => [RoleName::TeamLeader],
            'procurement' => [RoleName::Procurement],
        ];
    }

    /** @return array<string, array{RoleName}> */
    public static function ownScoped(): array
    {
        return ['outdoor sales' => [RoleName::OutdoorSales], 'indoor sales' => [RoleName::IndoorSales]];
    }

    #[DataProvider('ownScoped')]
    public function test_that_an_own_scoped_role_reads_its_own_deals_quotation(RoleName $role): void
    {
        $id = $this->quotation($this->deal($this->userWith($role)->id));

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor($role))->assertStatus(200);
    }

    /** §5.1: not visible is a 404, never a 403 — the two cases must be indistinguishable. */
    #[DataProvider('ownScoped')]
    public function test_that_an_own_scoped_role_cannot_read_another_owners_quotation(RoleName $role): void
    {
        $id = $this->quotation($this->deal($this->userWith(RoleName::Manager)->id));

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor($role))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found');
    }

    #[DataProvider('ownScoped')]
    public function test_that_an_own_scoped_role_cannot_read_an_unowned_deals_quotation(RoleName $role): void
    {
        $id = $this->quotation($this->deal(null));

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor($role))->assertStatus(404);
    }

    /** §3.5's `view` cell for the Outdoor Supervisor is `—`. */
    public function test_that_a_role_without_the_grant_is_refused(): void
    {
        $id = $this->quotation($this->deal(null));

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::OutdoorSupervisor))->assertStatus(403);
    }

    /** `SEC-07`: the grant is a database row, and withdrawing it withdraws the route. */
    public function test_that_withdrawing_the_grant_refuses_a_role_that_had_it(): void
    {
        $id = $this->quotation($this->deal(null));
        DB::table('permissions')->where('resource', 'quotation')->where('action', 'view')->delete();

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))->assertStatus(403);
    }

    public function test_that_an_unknown_id_is_a_404(): void
    {
        $this->getJson(self::ENDPOINT.'/'.Uuid::uuid4()->toString(), $this->bearerFor(RoleName::Manager))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found');
    }

    /** `DB-01`: a soft-deleted quotation reads as absent. */
    public function test_that_a_soft_deleted_quotation_is_a_404(): void
    {
        $id = $this->quotation($this->deal(null));
        DB::table('quotations')->where('id', $id)->update(['deleted_at' => now()]);

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))->assertStatus(404);
    }

    // ───────────────────────────────────────────────── the list (Point 5.4)

    public function test_that_an_unauthenticated_caller_cannot_list(): void
    {
        $this->getJson(self::ENDPOINT)->assertStatus(401);
    }

    /** §3.5 `view` = `All`: every quotation, an unowned deal's included, in one page. */
    #[DataProvider('unrestricted')]
    public function test_that_an_all_scoped_role_lists_every_quotation(RoleName $role): void
    {
        $this->quotation($this->deal(null));
        $this->quotation($this->deal($this->userWith(RoleName::IndoorSales)->id));

        $this->getJson(self::ENDPOINT, $this->bearerFor($role))
            ->assertStatus(200)
            ->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonPath('meta.pagination.per_page', 25)
            ->assertJsonPath('meta.pagination.page', 1)
            ->assertJsonCount(2, 'data');
    }

    /** `SEC-08` in the list: own deals' quotations only — another owner's and an unowned deal's absent. */
    #[DataProvider('ownScoped')]
    public function test_that_an_own_scoped_role_lists_only_its_own_deals_quotations(RoleName $role): void
    {
        $mine = $this->quotation($this->deal($this->userWith($role)->id));
        $this->quotation($this->deal($this->userWith(RoleName::Manager)->id));
        $this->quotation($this->deal(null));

        $this->getJson(self::ENDPOINT, $this->bearerFor($role))
            ->assertStatus(200)
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.id', $mine);
    }

    public function test_that_a_role_without_the_grant_cannot_list(): void
    {
        $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::OutdoorSupervisor))->assertStatus(403);
    }

    public function test_that_withdrawing_the_grant_refuses_the_list(): void
    {
        DB::table('permissions')->where('resource', 'quotation')->where('action', 'view')->delete();

        $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::Manager))->assertStatus(403);
    }

    /**
     * Every refusal 5.2's contract makes reaches the wire as `OpenAPI §6.2`'s
     * `400 invalid_request`, the offending parameter in `details[0].field`.
     *
     * @param  array<string, mixed>  $query
     */
    #[DataProviderExternal(QuotationListCriteriaTest::class, 'refusedQueries')]
    public function test_that_a_refused_query_is_a_400_on_the_wire(array $query, string $parameter, string $detailCode): void
    {
        $this->getJson(self::ENDPOINT.'?'.http_build_query($query), $this->bearerFor(RoleName::Manager))
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'invalid_request')
            ->assertJsonPath('error.details.0.field', $parameter)
            ->assertJsonPath('error.details.0.code', $detailCode);
    }

    /** §6.1: the cap is accepted at exactly 100; 101 is the provider's first row. */
    public function test_that_the_page_size_cap_is_accepted_at_the_cap(): void
    {
        $this->getJson(self::ENDPOINT.'?per_page=100', $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('meta.pagination.per_page', 100)
            ->assertJsonPath('meta.pagination.total_pages', 1);
    }

    /** Q6: §6.6's columns, `meta.request_id`, and nothing from the cost side — no grant asked. */
    public function test_that_a_list_row_carries_the_columns_and_no_cost_field(): void
    {
        $id = $this->quotation($this->deal(null));

        $response = $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::Manager))->assertStatus(200);

        self::assertIsString($response->json('meta.request_id'));
        $row = $response->json('data.0');
        self::assertIsArray($row);
        self::assertSame(
            ['id', 'code', 'status', 'customer_id', 'customer_name', 'deal_id', 'currency_id', 'currency', 'final_total', 'quotation_date', 'valid_until', 'submitted_at', 'days_waiting', 'sla_exceeded', 'is_self_approved', 'version', 'parent_id', 'created_at', 'updated_at'],
            array_keys($row),
        );
        self::assertSame($id, $row['id']);
        self::assertSame('draft', $row['status']);
        // Module 7 Point 6.3 (owner's ruling A, 2026-09-13): the row names its
        // currency — `GET /currencies` is an admin's, so the SPA cannot join.
        self::assertSame('EGP', $row['currency']);
        self::assertSame(1, $row['version']);
        foreach ([...QuotationLine::COST_FIELDS, 'default_margin', 'lines'] as $absent) {
            self::assertArrayNotHasKey($absent, $row);
        }
    }

    // ─────────────────────────────────────────────── group_by (Point 5.5)

    /**
     * `employee` groups by the deal's owner through `ownersOf()`; the label is
     * the owner's name through `namesOf()` (Step 6 Q2), groups ordered by that
     * label, counts per group.
     */
    public function test_that_group_by_employee_groups_the_page_by_deal_owner(): void
    {
        $a = $this->userWith(RoleName::IndoorSales)->id;
        $b = $this->userWith(RoleName::OutdoorSales)->id;
        $dealA = $this->deal($a);
        $this->quotation($dealA);
        $this->quotation($dealA);
        $this->quotation($this->deal($b));

        $response = $this->getJson(self::ENDPOINT.'?group_by=employee', $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.pagination.total', 3);

        $groups = $response->json('data');
        self::assertIsArray($groups);
        // "Test Indoor Sales" sorts before "Test Outdoor Sales" whatever the ids are.
        self::assertSame([$a, $b], array_column($groups, 'key'));
        self::assertSame(['Test Indoor Sales', 'Test Outdoor Sales'], array_column($groups, 'label'));
        self::assertSame([2, 1], array_column($groups, 'count'));
        $items = $response->json('data.0.items');
        self::assertIsArray($items);
        self::assertCount(2, $items);
        self::assertIsArray($items[0]);
        self::assertSame(['id', 'code', 'status', 'customer_id', 'customer_name', 'deal_id', 'currency_id', 'currency', 'final_total', 'quotation_date', 'valid_until', 'submitted_at', 'days_waiting', 'sla_exceeded', 'is_self_approved', 'version', 'parent_id', 'created_at', 'updated_at'], array_keys($items[0]));
    }

    /** A deal with no owner groups under the `null` key, labelled from the lang file. */
    public function test_that_an_unowned_deals_quotation_groups_under_null(): void
    {
        $this->quotation($this->deal(null));

        $this->getJson(self::ENDPOINT.'?group_by=employee', $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.key', null)
            ->assertJsonPath('data.0.label', (string) __('quotations.groups.unassigned'))
            ->assertJsonPath('data.0.count', 1);
    }

    /** The hidden Super Admin (§3.1) names nobody's group: the label falls back to the key. */
    public function test_that_a_hidden_owners_group_carries_no_name(): void
    {
        $hidden = $this->userWith(RoleName::SuperAdmin)->id;
        $this->quotation($this->deal($hidden));

        $this->getJson(self::ENDPOINT.'?group_by=employee', $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.key', $hidden)
            ->assertJsonPath('data.0.label', $hidden);
    }

    /**
     * Q7 reversed by `D-83` (F-07 · 1.3): the customer group's key is the
     * `customer_id` and its label is the customer's name through `namesOf()`.
     */
    public function test_that_group_by_customer_groups_by_customer_id_and_labels_the_name(): void
    {
        $this->quotation($this->deal(null));
        $this->quotation($this->deal(null));

        $this->getJson(self::ENDPOINT.'?group_by=customer', $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.key', $this->customerId)
            ->assertJsonPath('data.0.label', 'Nile Trading')
            ->assertJsonPath('data.0.count', 2)
            ->assertJsonCount(2, 'data.0.items');
    }

    // ─────────────────────────────────────────── the customer's name (F-07 · 1.3)

    /** `D-83`: the row carries the name beside `customer_id`, the way it carries `currency` beside `currency_id`. */
    public function test_that_a_list_row_carries_the_customers_name(): void
    {
        $this->quotation($this->deal(null));

        $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.0.customer_id', $this->customerId)
            ->assertJsonPath('data.0.customer_name', 'Nile Trading');
    }

    /** `D-83` mechanism 3: archiving the customer (`DB-01`) does not take the name off the row. */
    public function test_that_an_archived_customers_name_is_still_on_the_row(): void
    {
        $this->quotation($this->deal(null));
        DB::table('customers')->where('id', $this->customerId)->update(['is_archived' => true]);

        $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.0.customer_name', 'Nile Trading');
    }

    /**
     * `D-83` mechanism 4: `§3.3` scopes `customer.view` apart from
     * `quotation.view`. An `own`-scoped role reads its own deal's quotation
     * even when the customer belongs to somebody else — and the row still
     * carries the name, because the port takes no scope.
     */
    public function test_that_an_own_scoped_role_sees_the_name_of_a_customer_it_does_not_own(): void
    {
        DB::table('customers')->where('id', $this->customerId)->update(['sales_owner_id' => $this->userWith(RoleName::Manager)->id]);
        $mine = $this->quotation($this->deal($this->userWith(RoleName::OutdoorSales)->id));

        $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::OutdoorSales))
            ->assertStatus(200)
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.id', $mine)
            ->assertJsonPath('data.0.customer_name', 'Nile Trading');
    }

    /**
     * `D-83`: "one query for N ids, never N" — the use case calls the port
     * **once per page** with the page's distinct customer ids, for the plain
     * list and for `group_by=customer` alike (the label reads the same map).
     */
    public function test_that_the_port_is_called_once_per_page(): void
    {
        $other = $this->customer('Delta Steel');
        $this->quotation($this->deal(null));
        $this->quotation($this->deal(null));
        $this->quotation($this->deal(null, $other));

        $fake = new class implements CustomerNamesInterface
        {
            /** @var list<list<string>> */
            public array $calls = [];

            public function namesOf(array $customerIds): array
            {
                $this->calls[] = $customerIds;

                return array_fill_keys($customerIds, 'Faked');
            }
        };
        $this->app->instance(CustomerNamesInterface::class, $fake);

        $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.0.customer_name', 'Faked');
        self::assertCount(1, $fake->calls);
        self::assertEqualsCanonicalizing([$this->customerId, $other], $fake->calls[0]);

        $fake->calls = [];
        $this->getJson(self::ENDPOINT.'?group_by=customer', $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.label', 'Faked');
        self::assertCount(1, $fake->calls);
    }

    // ────────────────────────────────────────────────────── what a 200 carries

    public function test_that_the_detail_carries_the_header_lines_and_etag(): void
    {
        $id = $this->quotation($this->deal(null));

        $response = $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.currency_id', $this->currencyId('EGP'))
            ->assertJsonPath('data.currency', 'EGP')
            ->assertJsonPath('data.deal_id', fn (string $dealId): bool => $dealId !== '')
            ->assertJsonPath('data.customer_id', $this->customerId)
            ->assertJsonPath('data.default_margin', '20.000')
            ->assertJsonPath('data.tax_percent', null)
            // §5: one line of 2 × (10 × 1.20) = 24; the 5 delivery item is added
            // untaxed (`D-62`); no discount, no tax, no rounding → 29.
            ->assertJsonPath('data.subtotal', '24.000000')
            ->assertJsonPath('data.additional_total', '5.000000')
            ->assertJsonPath('data.final_total', '29.000000')
            ->assertJsonPath('data.additional_items.0.description', 'Delivery')
            ->assertJsonPath('data.additional_items.0.amount', '5.000000')
            ->assertJsonPath('data.items.0.line_no', 1)
            ->assertJsonPath('data.items.0.product_name', 'Widget')
            ->assertJsonPath('data.items.0.quantity', '2.0000')
            ->assertJsonPath('data.items.0.unit_price', '12.000000')
            ->assertJsonPath('data.items.0.line_total', '24.000000')
            ->assertJsonPath('data.items.0.unit_cost', '10.000000')
            ->assertJsonPath('data.items.0.line_cost', '20.000000')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.etag', 'quotation:'.$id.':1')
            ->assertJsonStructure(['data' => ['code', 'created_by', 'created_at', 'updated_by', 'updated_at'], 'meta' => ['request_id']]);

        $code = $response->json('data.code');
        self::assertIsString($code);
        self::assertMatchesRegularExpression('/^QT-\d{4}-\d{4}$/', $code);
    }

    /** §3.5 `view cost & margin`: withdrawn, the cost fields are absent — not null, not zero. */
    public function test_that_without_the_cost_grant_the_cost_fields_are_absent(): void
    {
        $id = $this->quotation($this->deal(null));
        DB::table('permissions')->where('resource', 'quotation')->where('action', 'view_cost_and_margin')->delete();

        $line = $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonMissingPath('data.default_margin')
            ->assertJsonPath('data.items.0.unit_price', '12.000000')
            // F-16 · 1.1: what the line is, is not a cost — it stays.
            ->assertJsonPath('data.items.0.product_name', 'Widget')
            ->json('data.items.0');

        self::assertIsArray($line);
        self::assertSame([], array_intersect(QuotationLine::COST_FIELDS, array_keys($line)));
    }

    /** F-16 · 1.1 / §7.3: a service is named by its `service_type`, the builder's `catalogLabel()` rule. */
    public function test_that_a_service_line_is_named_by_its_service_type(): void
    {
        $id = $this->quotation($this->deal(null));
        $catalogItemId = DB::table('supplier_quotation_items')
            ->where('id', DB::table('quotation_items')->where('quotation_id', $id)->value('supplier_quotation_item_id'))
            ->value('catalog_item_id');
        DB::table('catalog_items')->where('id', $catalogItemId)->update(['kind' => 'service', 'name' => null, 'service_type' => 'Installation']);

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.items.0.product_name', 'Installation');
    }

    /** F-16 · 1.1: an imported, incomplete item may carry neither label — the line is then unnamed, not an id. */
    public function test_that_an_item_with_no_label_leaves_the_line_unnamed(): void
    {
        $id = $this->quotation($this->deal(null));
        $catalogItemId = DB::table('supplier_quotation_items')
            ->where('id', DB::table('quotation_items')->where('quotation_id', $id)->value('supplier_quotation_item_id'))
            ->value('catalog_item_id');
        DB::table('catalog_items')->where('id', $catalogItemId)->update(['name' => null, 'service_type' => null]);

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.items.0.product_name', null);
    }

    // ─────────────────────────────────────────── §10.3 price drift (Point 4.5)

    /** `D-36`: the quotation keeps its captured price and warns, per line, in `meta.warnings`. */
    public function test_that_a_moved_supplier_price_warns_on_a_draft(): void
    {
        $id = $this->quotation($this->deal(null));
        $this->moveSupplierPrice($id, '11');

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.items.0.unit_cost', '10.000000')
            ->assertJsonCount(1, 'meta.warnings')
            ->assertJsonPath('meta.warnings.0.field', 'lines.0.unit_cost')
            ->assertJsonPath('meta.warnings.0.code', 'supplier_price_changed')
            ->assertJsonPath('meta.warnings.0.message', (string) __('quotations.warnings.supplier_price_changed'));
    }

    /** §6.5 / `D-50`: the yellow badge on the list (Module 8 · 3.3) reads a row field, not the detail. */
    public function test_that_a_list_row_says_whether_it_was_self_approved(): void
    {
        $id = $this->quotation($this->deal(null));
        DB::table('quotations')->where('id', $id)->update(['status' => 'approved', 'is_self_approved' => true]);

        $this->getJson(self::ENDPOINT.'?filter[bucket]=active', $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.is_self_approved', true);
    }

    /** §10.3's first row names Draft **or Pending**. */
    public function test_that_a_moved_supplier_price_warns_on_a_pending_quotation(): void
    {
        $id = $this->quotation($this->deal(null));
        DB::table('quotations')->where('id', $id)->update(['status' => 'pending']);
        $this->moveSupplierPrice($id, '11');

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('meta.warnings.0.code', 'supplier_price_changed');
    }

    /** §10.3: "Sent or beyond — completely unaffected, fixed snapshot": nothing is compared. */
    public function test_that_a_sent_quotation_is_silent_about_a_moved_supplier_price(): void
    {
        $id = $this->quotation($this->deal(null));
        DB::table('quotations')->where('id', $id)->update(['status' => 'sent']);
        $this->moveSupplierPrice($id, '11');

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonMissingPath('meta.warnings');
    }

    /** The captured currency is part of the comparison: the same number in another currency is a change. */
    public function test_that_a_moved_supplier_currency_warns(): void
    {
        $this->currency('USD', '1', false, false);
        $id = $this->quotation($this->deal(null));
        DB::table('supplier_quotations')->where('id', $this->offerOf($id))->update(['currency_id' => $this->currencyId('USD')]);

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('meta.warnings.0.code', 'supplier_price_changed');
    }

    /** `D-09`: the FX rate is captured once; a moved rate is not a moved supplier price. The compare reads `unit_cost`, never `unit_cost_base`. */
    public function test_that_a_moved_fx_rate_alone_carries_no_warning(): void
    {
        $id = $this->quotation($this->deal(null));
        DB::table('quotation_items')->where('quotation_id', $id)->update(['unit_cost_fx_rate_at_time' => '2', 'unit_cost_base' => '20']);

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonMissingPath('meta.warnings');
    }

    /** The key is absent, not an empty list, when nothing moved (`store()`'s convention). */
    public function test_that_an_unmoved_supplier_price_carries_no_warning(): void
    {
        $id = $this->quotation($this->deal(null));

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonMissingPath('meta.warnings');
    }

    // ────────────────────────────────────────────────────────────── fixtures

    /** Changes the supplier's price under the quotation's only line, after the quotation captured it. */
    private function moveSupplierPrice(string $quotationId, string $unitPrice): void
    {
        $lineId = DB::table('quotation_items')->where('quotation_id', $quotationId)->value('supplier_quotation_item_id');
        self::assertIsString($lineId);

        DB::table('supplier_quotation_items')->where('id', $lineId)->update(['unit_price' => $unitPrice]);
    }

    /** The `supplier_quotations` id behind the quotation's only line. */
    private function offerOf(string $quotationId): string
    {
        $offerId = DB::table('quotation_items')
            ->join('supplier_quotation_items', 'supplier_quotation_items.id', '=', 'quotation_items.supplier_quotation_item_id')
            ->where('quotation_items.quotation_id', $quotationId)
            ->value('supplier_quotation_items.supplier_quotation_id');
        self::assertIsString($offerId);

        return $offerId;
    }

    /** Creates a quotation through Point 3.4's endpoint: one line of 2 × 10 at 20 % margin, plus a 5 delivery item. */
    private function quotation(string $dealId): string
    {
        $id = $this->postJson(self::ENDPOINT, [
            'deal_id' => $dealId,
            'customer_id' => $this->dealCustomers[$dealId],
            'currency' => 'EGP',
            'default_margin' => '20',
            'discount_percent' => '0',
            'lines' => [['supplier_quotation_item_id' => $this->supplierLine('10', '5'), 'quantity' => '2']],
            'additional_items' => [['description' => 'Delivery', 'amount' => '5']],
        ], ['Idempotency-Key' => Uuid::uuid4()->toString()] + $this->bearerFor(RoleName::Manager))->assertStatus(201)->json('data.id');

        self::assertIsString($id);

        return $id;
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

    private function currencyId(string $code): string
    {
        $id = DB::table('currencies')->where('code', $code)->value('id');
        self::assertIsString($id);

        return $id;
    }

    private function customer(string $name = 'Nile Trading'): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('customers')->insert([
            'id' => $id,
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function deal(?string $ownerId, ?string $customerId = null): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('deals')->insert([
            'id' => $id,
            'code' => 'DL-'.now()->format('Y').'-'.substr($id, 0, 4),
            'customer_id' => $this->dealCustomers[$id] = $customerId ?? $this->customerId,
            'owner_id' => $ownerId,
            'last_activity_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** Seeds §4.1's chain in EGP and returns the `supplier_quotation_items` id a line points at. */
    private function supplierLine(string $unitPrice, string $quantity): string
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
            'currency_id' => $this->currencyId('EGP'),
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

        return ['Authorization' => 'Bearer '.$token];
    }
}
