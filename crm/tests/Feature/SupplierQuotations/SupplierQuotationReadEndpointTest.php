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
 * Module 6, Point 2.3 — `GET /api/v1/supplier-quotations/{id}`.
 *
 * ── The read row of §3.6 is not the write row, and the CEO proves it ───────
 *
 * §3.6 grants `view` as `All` to six roles — Manager, Team Leader, Outdoor
 * Sales, Indoor Sales, Procurement and the **CEO** — and a dash to the Outdoor
 * Supervisor. The CEO is the documented pair for this module: a 403 on
 * `POST` (Point 2.2) and a 200 here, from one table read two ways. So this
 * endpoint carries `permission:supplier_quotation.view`, not `create`.
 *
 * **No scope anywhere**, for the reason §3.6 states outright: "a shared screen
 * — not restricted by ownership". Every role that may read holds `Scope::All`,
 * so there is no owner column to file under and no invisible-to-this-caller
 * case to hide.
 *
 * ── This is the point that gives the lines a read path ─────────────────────
 *
 * Point 2.2's 201 carries the header alone, because `OpenAPI §4.1` asks for a
 * single-resource envelope and says nothing about nesting children — and a
 * payload field with no read path behind it would have been that point
 * inventing one. The approved point list puts the lines here: "`find()` on the
 * contract, no scope, **lines included**".
 *
 * ── 404 is one of `OpenAPI §5.1`'s two cases, and it must not say which ────
 *
 * §5.1: "Resource does not exist or is not visible to the caller. Do not reveal
 * which case applies." Only the first case can arise under §3.6, but an absent
 * row and a `DB-01` soft-deleted row must answer identically, or the response
 * tells a caller that an offer it may not see nevertheless exists.
 */
final class SupplierQuotationReadEndpointTest extends TestCase
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
     * anonymous one — measured: after a Manager request, a header-less GET
     * returns 404 (the Manager's answer for an absent row) instead of 401.
     * It is a leak between requests in one test method, not in the endpoint;
     * a real caller gets a fresh process per request. Recorded in
     * `CHECKLIST.md`, because every endpoint test in the repository shares the
     * harness.
     *
     * `auth` runs before anything looks at the id, so an id that exists proves
     * nothing extra here.
     */
    public function test_that_an_unauthenticated_caller_cannot_read(): void
    {
        $this->getJson(self::ENDPOINT.'/'.Uuid::uuid4()->toString())->assertStatus(401);
    }

    #[DataProvider('readers')]
    public function test_that_every_role_section_3_6_grants_view_to_can_read(RoleName $role): void
    {
        $id = $this->created();

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor($role))->assertStatus(200);
    }

    /**
     * §3.6 gives the CEO `view` as `All` and a dash under `create / edit` —
     * the same caller Point 2.2 refuses is served here, which is the whole
     * reason this endpoint's middleware names `view` and not `create`.
     */
    public function test_that_the_ceo_who_cannot_create_can_still_read(): void
    {
        $id = $this->created();

        $this->postJson(self::ENDPOINT, $this->payload(), $this->bearerFor(RoleName::Ceo))->assertStatus(403);
        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Ceo))->assertStatus(200);
    }

    /** §3.6 gives the Outdoor Supervisor a dash in every column, `view` included. */
    public function test_that_the_outdoor_supervisor_cannot_read(): void
    {
        $id = $this->created();

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::OutdoorSupervisor))
            ->assertStatus(403);
    }

    /** `SEC-09`: the grant is data, so withdrawing it is what actually refuses. */
    public function test_that_withdrawing_the_view_grant_refuses_a_role_that_had_it(): void
    {
        $id = $this->created();

        DB::table('permissions')
            ->where('resource', 'supplier_quotation')
            ->where('action', 'view')
            ->delete();

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))->assertStatus(403);
    }

    // ─────────────────────────────────────────────────────────── what is read

    public function test_that_the_body_carries_section_7_2_header_fields(): void
    {
        $id = $this->created();

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.supplier_id', $this->supplierId)
            ->assertJsonPath('data.deal_id', null)
            ->assertJsonPath('data.deal_code', null)
            ->assertJsonStructure(['data' => ['deal_code']])
            ->assertJsonPath('data.total_price', '4500.000000')
            ->assertJsonPath('data.currency_id', $this->currencyId);
    }

    /** `D-88`: the single offer carries its deal's code beside `deal_id`; `null` with no deal is asserted above. */
    public function test_that_the_single_offer_carries_its_deal_code(): void
    {
        $customerId = Uuid::uuid4()->toString();
        $dealId = Uuid::uuid4()->toString();
        DB::table('customers')->insert(['id' => $customerId, 'name' => 'Nile Contracting', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('deals')->insert(['id' => $dealId, 'code' => 'DL-2026-0003', 'customer_id' => $customerId, 'last_activity_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $id = $this->postJson(self::ENDPOINT, $this->payload(['deal_id' => $dealId]), $this->bearerFor(RoleName::Manager))
            ->assertStatus(201)
            ->json('data.id');
        self::assertIsString($id);

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.deal_id', $dealId)
            ->assertJsonPath('data.deal_code', 'DL-2026-0003');
    }

    /** §4.7's `SQ-YYYY-NNNN`, read back exactly as it was allocated. */
    public function test_that_the_body_carries_the_allocated_code(): void
    {
        $id = $this->created();

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.code', 'SQ-'.now()->format('Y').'-0001');
    }

    /**
     * The point of this point. §7.2's `Line items` row — "Product · price ·
     * quantity" — read back for the two lines Point 2.1 wrote.
     *
     * The prices are **strings** at `D-68`'s scales: money is six decimals and
     * quantity is four, and `DB-07` forbids a float anywhere near either.
     *
     * **Asserted by content, not by position.** `supplier_quotation_items` has
     * no ordering column: Point 2.1 writes the batch with one `insert()` under
     * a single `now()`, and the ids are random UUIDs, so the order a user typed
     * the lines in is not recoverable from the row. The endpoint orders by `id`
     * so that two calls agree with each other, and this test asserts that both
     * lines came back whole rather than pretending an order exists. Recorded as
     * debt in `CHECKLIST.md`.
     */
    public function test_that_the_body_carries_both_lines_whole(): void
    {
        $id = $this->created();

        // D-81: the balance beside the offer — consumed as Module 10 will write
        // it, available as `quantity − consumed_quantity`, both strings at scale 4.
        DB::table('supplier_quotation_items')
            ->where('supplier_quotation_id', $id)->where('quantity', '3.0000')
            ->update(['consumed_quantity' => '1.0000']);

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonFragment([
                'catalog_item_id' => $this->catalogItemId,
                'unit_price' => '1500.000000',
                'quantity' => '3.0000',
                'consumed_quantity' => '1.0000',
                'available_quantity' => '2.0000',
            ])
            ->assertJsonFragment([
                'catalog_item_id' => $this->catalogItemId,
                'unit_price' => '250.500000',
                'quantity' => '1.0000',
                'consumed_quantity' => '0.0000',
                'available_quantity' => '1.0000',
            ]);
    }

    /**
     * Module 7 Point 6.2. A customer quotation's line is a
     * `supplier_quotation_item_id` (Module 7 Point 3.3), so the builder must
     * see the id of the line it picks. Each item carries its own row's `id`,
     * and nothing else about the row leaks with it.
     */
    public function test_that_each_line_carries_its_own_id(): void
    {
        $id = $this->created();

        $items = $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->json('data.items');
        $this->assertIsArray($items);

        $stored = DB::table('supplier_quotation_items')
            ->where('supplier_quotation_id', $id)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertSame($stored, array_column($items, 'id'));
        $this->assertIsArray($items[0]);
        // D-81 (F-05 · 1.2) added the balance pair beside the offer's `quantity`.
        $this->assertSame(['id', 'catalog_item_id', 'unit_price', 'quantity', 'consumed_quantity', 'available_quantity'], array_keys($items[0]));
    }

    /** Two calls agree with each other, which is what ordering by `id` buys. */
    public function test_that_the_line_order_is_stable_across_calls(): void
    {
        $id = $this->created();

        $first = $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))->json('data.items');
        $second = $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))->json('data.items');

        self::assertSame($first, $second);
    }

    /** An offer with no lines reads back as an empty list, not as a missing key. */
    public function test_that_an_offer_without_lines_carries_an_empty_list(): void
    {
        $id = $this->postJson(self::ENDPOINT, $this->payload(['items' => []]), $this->bearerFor(RoleName::Manager))
            ->assertStatus(201)
            ->json('data.id');

        self::assertIsString($id);

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.items', []);
    }

    /**
     * `DB-01` soft-deletes a line as it does everything else. The header's
     * `SoftDeletes` trait does not reach the lines — they are read through the
     * connection, with no model — so the read filters them explicitly.
     */
    public function test_that_a_soft_deleted_line_is_not_read_back(): void
    {
        $id = $this->created();

        DB::table('supplier_quotation_items')
            ->where('supplier_quotation_id', $id)
            ->where('unit_price', '1500.000000')
            ->update(['deleted_at' => now()]);

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.unit_price', '250.500000');
    }

    // ─────────────────────────────────────────────────────────────────── 404

    public function test_that_an_unknown_id_is_a_404_in_the_documented_envelope(): void
    {
        $this->getJson(self::ENDPOINT.'/'.Uuid::uuid4()->toString(), $this->bearerFor(RoleName::Manager))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found')
            // Coding Standards §11: the sentence comes from a lang file. Asserted
            // because `__()` returns the key itself when the file is missing —
            // without this the lang file could be deleted and stay green.
            ->assertJsonPath('error.message', 'This supplier quotation was not found.');
    }

    /**
     * `DB-01` soft-deletes every business row, and `OpenAPI §5.1` forbids
     * saying which of its two cases produced the 404 — so an archived offer
     * must answer exactly as an absent one does.
     */
    public function test_that_a_soft_deleted_offer_is_a_404(): void
    {
        $id = $this->created();

        DB::table('supplier_quotations')->where('id', $id)->update(['deleted_at' => now()]);

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found');
    }

    // ───────────────────────────────────────────────────────────────── helpers

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
                ['catalog_item_id' => $this->catalogItemId, 'unit_price' => '250.5', 'quantity' => '1'],
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
