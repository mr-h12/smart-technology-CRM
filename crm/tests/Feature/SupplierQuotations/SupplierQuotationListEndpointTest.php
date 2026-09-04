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
 * ── What this point does NOT assert ───────────────────────────────────────
 *
 * The filters, the ordering, and the full `400` matrix are **Point 4.4**. One
 * `400` case appears here, and only to prove the renderer is wired at all — a
 * handler registered with no test is a handler nobody knows is registered.
 */
final class SupplierQuotationListEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/supplier-quotations';

    private const PASSWORD = 'Passw0rd123';

    /** @var array<string, User> */
    private array $users = [];

    private string $supplierId;

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

    // ───────────────────────────────────────────────────────────────  helpers

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

    private function created(): string
    {
        $id = $this->postJson(self::ENDPOINT, $this->payload(), $this->bearerFor(RoleName::Manager))
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
