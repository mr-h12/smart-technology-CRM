<?php

declare(strict_types=1);

namespace Tests\Feature\SupplierQuotations;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Module 6, Point 4.3 — `GET /api/v1/supplier-quotations`.
 *
 * ── The same `view` row Point 2.3 read, on the collection ──────────────────
 *
 * §3.6 grants `view` as `All` to six roles — Manager, Team Leader, Outdoor
 * Sales, Indoor Sales, Procurement and the **CEO** — and a dash to the Outdoor
 * Supervisor. So this route carries `permission:supplier_quotation.view`, not
 * `create`: the caller Point 2.2 refuses is served here, exactly as on the
 * detail route.
 *
 * **No scope**, for the reason §3.6 states outright — "a shared screen — not
 * restricted by ownership". Every role that may read holds `Scope::All`, so
 * there is no owner column to narrow by and no row this caller must not see.
 *
 * ── The list carries headers, never lines ─────────────────────────────────
 *
 * `OpenAPI §6.2` tells `include` not to return unrestricted collections, and a
 * list of offers each carrying its own line items is exactly that. The lines
 * belong to `find()` and to Point 2.3's detail route. Asserted below, because a
 * payload that grows a nested collection later would break §6.2 silently.
 *
 * ── Point 4.4 closes two of §7.2's acceptance criteria here ───────────────
 *
 * "Saved offer appears on the supplier page under **Linked Quotations**" is a
 * filter on this list, not offers embedded in the supplier payload: `OpenAPI
 * §6.2` forbids `include` returning unrestricted collections, and reading
 * Module 6's rows from inside Module 4 would be the cross-module database
 * access `CLAUDE.md` forbids. So the criterion is `filter[supplier_id]`, and it
 * is closed by proving the filter returns that supplier's offers **and nothing
 * else** — a filter that returns everything passes a test that only checks the
 * wanted row is present.
 *
 * "Offer **not linked to a deal** → saves normally, available to any deal"
 * (`D-51`) is closed by listing an offer whose `deal_id` is `null` beside one
 * that has a deal, and by `filter[deal_id]` selecting only the latter.
 *
 * ── The 400s are §6's, not the validator's ────────────────────────────────
 *
 * `per_page` above the maximum, an undeclared filter and an undeclared sort all
 * answer **400 `invalid_request`**, never 422. That is the whole reason the
 * query is parsed in Domain instead of by a Form Request, and the assertions
 * below pin the status rather than only the body — a 422 with the right message
 * is still the wrong contract.
 */
final class SupplierQuotationListEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/supplier-quotations';

    private const PASSWORD = 'Passw0rd123';

    /** @var array<string, User> */
    private array $users = [];

    private string $supplierId;

    private string $otherSupplierId;

    private string $currencyId;

    private string $catalogItemId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->supplierId = Uuid::uuid4()->toString();
        $this->currencyId = Uuid::uuid4()->toString();
        $this->catalogItemId = Uuid::uuid4()->toString();

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

        // The second supplier exists so that "Linked Quotations" can be shown
        // to exclude, not merely to include. A filter that returns every row
        // passes any test that only asserts the wanted offer is present.
        $this->otherSupplierId = Uuid::uuid4()->toString();

        DB::table('suppliers')->insert([
            'id' => $this->otherSupplierId,
            'name' => 'Beta Trading',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * §3.6's `view` row — six `All` grants and one dash.
     *
     * @return array<string, array{RoleName}>
     */
    public static function readers(): array
    {
        return [
            'manager' => [RoleName::Manager],
            'team leader' => [RoleName::TeamLeader],
            'outdoor sales' => [RoleName::OutdoorSales],
            'indoor sales' => [RoleName::IndoorSales],
            'procurement' => [RoleName::Procurement],
            'ceo' => [RoleName::Ceo],
        ];
    }

    // ────────────────────────────────────────────────────────── authorisation

    /**
     * The only request this method makes, and deliberately so.
     *
     * Creating an offer first would authenticate this test's client, and a
     * later header-less call then answers as that caller rather than as an
     * anonymous one. It is a leak between requests in one test method, not in
     * the endpoint; recorded in `CHECKLIST.md`, and every endpoint test in the
     * repository works around it the same way.
     */
    public function test_that_an_unauthenticated_caller_cannot_list(): void
    {
        $this->getJson(self::ENDPOINT)->assertStatus(401);
    }

    #[DataProvider('readers')]
    public function test_that_every_role_section_3_6_grants_view_to_can_list(RoleName $role): void
    {
        $this->created();

        $this->getJson(self::ENDPOINT, $this->bearerFor($role))->assertStatus(200);
    }

    /**
     * §3.6 gives the CEO `view` as `All` and a dash under `create / edit` — the
     * reason this route names `view` and not `create`, on the collection just
     * as on the detail.
     */
    public function test_that_the_ceo_who_cannot_create_can_still_list(): void
    {
        $this->created();

        $this->postJson(self::ENDPOINT, $this->payload(), $this->bearerFor(RoleName::Ceo))->assertStatus(403);
        $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::Ceo))->assertStatus(200);
    }

    /** §3.6 gives the Outdoor Supervisor a dash in every column, `view` included. */
    public function test_that_the_outdoor_supervisor_cannot_list(): void
    {
        $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::OutdoorSupervisor))
            ->assertStatus(403);
    }

    /** `SEC-09`: the grant is data, so withdrawing it is what actually refuses. */
    public function test_that_withdrawing_the_view_grant_refuses_a_role_that_had_it(): void
    {
        $this->created();

        DB::table('permissions')
            ->where('resource', 'supplier_quotation')
            ->where('action', 'view')
            ->delete();

        $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::Manager))->assertStatus(403);
    }

    // ─────────────────────────────────────────────────────────── the envelope

    public function test_that_the_payload_is_the_documented_collection_envelope(): void
    {
        $this->created();

        $body = $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->json();

        self::assertIsArray($body);
        self::assertArrayHasKey('data', $body);
        self::assertArrayHasKey('meta', $body);

        $meta = $body['meta'];
        self::assertIsArray($meta);
        self::assertArrayHasKey('request_id', $meta);
        self::assertArrayHasKey('pagination', $meta);

        $pagination = $meta['pagination'];
        self::assertIsArray($pagination);

        foreach (['page', 'per_page', 'total', 'total_pages', 'has_next_page', 'has_previous_page'] as $key) {
            self::assertArrayHasKey($key, $pagination, "OpenAPI §4.2 requires `{$key}`.");
        }

        self::assertSame(25, $pagination['per_page'], 'OpenAPI §6.1 sets the default page size to 25.');
        self::assertSame(1, $pagination['total']);
    }

    /**
     * An empty list is a 200 with an empty `data`, never a 404.
     *
     * `SupplierQuotationPage::totalPages()` returns 1 for an empty result on
     * purpose: page 1 of an empty list is a valid request.
     */
    public function test_that_an_empty_list_is_an_empty_page_and_not_a_not_found(): void
    {
        $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.pagination.total', 0)
            ->assertJsonPath('meta.pagination.total_pages', 1)
            ->assertJsonPath('meta.pagination.has_next_page', false);
    }

    public function test_that_a_listed_offer_carries_section_7_2_header_fields(): void
    {
        $id = $this->created();

        $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.supplier_id', $this->supplierId)
            ->assertJsonPath('data.0.deal_id', null)
            ->assertJsonPath('data.0.total_price', '4500.000000')
            ->assertJsonPath('data.0.currency_id', $this->currencyId);
    }

    /**
     * `OpenAPI §6.2` — a collection does not nest another unrestricted
     * collection. The lines are the detail route's, and `SupplierQuotationPage`
     * carries summaries for exactly this reason.
     */
    public function test_that_the_list_does_not_carry_the_line_items(): void
    {
        $this->created();

        $row = $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->json('data.0');

        self::assertIsArray($row);
        self::assertArrayNotHasKey('items', $row, 'OpenAPI §6.2 — the list carries headers, not lines.');
        self::assertArrayHasKey('code', $row);
    }

    // ──────────────────────────────── §7.2's criterion: "Linked Quotations"

    /**
     * The acceptance criterion, closed as a filter.
     *
     * Asserted in both directions on purpose: the offer of the filtered
     * supplier is present **and** the other supplier's offer is absent. Only
     * the second half can fail when a filter is dropped, which is exactly how
     * a filter regression escapes.
     */
    public function test_that_the_supplier_filter_returns_that_suppliers_offers_and_nothing_else(): void
    {
        $mine = $this->created();
        $theirs = $this->created(['supplier_id' => $this->otherSupplierId]);

        $ids = $this->ids('?filter[supplier_id]='.$this->supplierId);

        self::assertContains($mine, $ids);
        self::assertNotContains($theirs, $ids, '"Linked Quotations" must not show another supplier\'s offers.');
        self::assertCount(1, $ids);
    }

    /** A well-formed id that belongs to no supplier is an empty page, not a 404. */
    public function test_that_a_supplier_with_no_offers_lists_empty(): void
    {
        $this->created();

        $this->getJson(self::ENDPOINT.'?filter[supplier_id]='.Uuid::uuid4()->toString(), $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.pagination.total', 0);
    }

    // ─────────────────────── §7.2's criterion: an offer with no deal (`D-51`)

    /**
     * `D-51` — the offer is standalone and "available to any deal", so a `null`
     * `deal_id` is an ordinary row on this list rather than a hidden one.
     */
    public function test_that_an_offer_with_no_deal_is_listed_normally(): void
    {
        $unlinked = $this->created();
        $linked = $this->created(['deal_id' => $this->deal()]);

        $ids = $this->ids('');

        self::assertContains($unlinked, $ids, 'D-51: an offer with no deal is a normal offer.');
        self::assertContains($linked, $ids);
    }

    public function test_that_the_deal_filter_narrows_to_that_deal(): void
    {
        $unlinked = $this->created();
        $dealId = $this->deal();
        $linked = $this->created(['deal_id' => $dealId]);

        $ids = $this->ids('?filter[deal_id]='.$dealId);

        self::assertSame([$linked], $ids);
        self::assertNotContains($unlinked, $ids);
    }

    /**
     * `DB-01` — a soft-deleted offer is gone from the list, and the count goes
     * with it. `SoftDeletes` on the model is what does this; the total is
     * asserted because a row hidden from `data` while still counted would give
     * the SPA a page it cannot fill.
     */
    public function test_that_a_soft_deleted_offer_is_absent_from_the_list(): void
    {
        $kept = $this->created();
        $removed = $this->created();

        DB::table('supplier_quotations')->where('id', $removed)->update(['deleted_at' => now()]);

        $body = $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('meta.pagination.total', 1)
            ->json('data');

        self::assertIsArray($body);
        self::assertSame([$kept], array_column($body, 'id'));
    }

    // ───────────────────────────────────────────────── the 400 is reachable

    /**
     * Point 4.4 owns the whole `400` matrix. This one case exists only to prove
     * `InvalidSupplierQuotationListQuery` reaches a registered renderer — the
     * exception has existed since Point 4.1 with no handler behind it, so
     * without this the wiring would be asserted by nothing.
     *
     * `OpenAPI §6.1` caps the page size at 100; §5 shapes the answer as
     * `400 invalid_request` with `details` as a **list**.
     */
    public function test_that_a_page_size_above_the_maximum_is_rendered_as_400_and_not_500(): void
    {
        $this->getJson(self::ENDPOINT.'?per_page=101', $this->bearerFor(RoleName::Manager))
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'invalid_request')
            ->assertJsonPath('error.details.0.field', 'per_page')
            ->assertJsonPath('error.details.0.code', 'above_maximum');
    }

    /**
     * §6.2: "Unknown filters return `400`". Refused rather than ignored — a
     * silently dropped filter answers a different question than the one asked,
     * and the caller cannot tell.
     */
    public function test_that_an_unknown_filter_is_refused_with_400_and_not_422(): void
    {
        $this->getJson(self::ENDPOINT.'?filter[colour]=red', $this->bearerFor(RoleName::Manager))
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'invalid_request')
            ->assertJsonPath('error.details.0.code', 'unknown_filter');
    }

    /** §6.2 again, for the sort allowlist — `total_price` is deliberately not sortable. */
    public function test_that_an_unknown_sort_is_refused_with_400_and_not_422(): void
    {
        $this->getJson(self::ENDPOINT.'?sort=-total_price', $this->bearerFor(RoleName::Manager))
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'invalid_request')
            ->assertJsonPath('error.details.0.code', 'unknown_sort_field');
    }

    /**
     * `OpenAPI §5` — `details` is a **list** of `{field, code, message}`, not a
     * map keyed by field. Asserted as a shape because a renderer that returns
     * the map form still produces a 400 and would pass every case above.
     */
    public function test_that_the_error_details_are_a_list(): void
    {
        $details = $this->getJson(self::ENDPOINT.'?per_page=101', $this->bearerFor(RoleName::Manager))
            ->assertStatus(400)
            ->json('error.details');

        self::assertIsArray($details);
        self::assertArrayHasKey(0, $details, 'OpenAPI §5 makes `details` a list.');
        self::assertSame([0], array_keys($details));

        $first = $details[0];
        self::assertIsArray($first);
        self::assertSame(['field', 'code', 'message'], array_keys($first));
        self::assertNotSame('', $first['message'], 'The detail message comes from a lang file, not from an empty key.');
    }

    // ───────────────────────────────────────────────────────────────  helpers

    /**
     * The ids on one page of the list, in the order the endpoint returned them.
     *
     * @return list<string>
     */
    private function ids(string $query): array
    {
        $data = $this->getJson(self::ENDPOINT.$query, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->json('data');

        self::assertIsArray($data);

        // Built by hand rather than with `array_column`, which loses the
        // element type: PHPStan level 10 will not accept `list` as
        // `list<string>`, and asserting the shape here is what makes it true.
        $ids = [];

        foreach ($data as $row) {
            self::assertIsArray($row);
            self::assertIsString($row['id']);

            $ids[] = $row['id'];
        }

        return $ids;
    }

    /** A customer and a deal, so `filter[deal_id]` has something real to select. */
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

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'supplier_id' => $this->supplierId,
            'total_price' => '4500.000000',
            'currency_id' => $this->currencyId,
            'items' => [
                ['catalog_item_id' => $this->catalogItemId, 'unit_price' => '1500', 'quantity' => '3'],
            ],
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function created(array $overrides = []): string
    {
        $id = $this->postJson(self::ENDPOINT, $this->payload($overrides), $this->bearerFor(RoleName::Manager))
            ->assertStatus(201)
            ->json('data.id');

        self::assertIsString($id);

        return $id;
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
