<?php

declare(strict_types=1);

namespace Tests\Feature\Deals;

use App\Modules\Customers\Domain\Contracts\CustomerNamesInterface;
use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Module 5, Point 2.2 — `GET /deals` and `GET /deals/{id}` —
 * `CustomerListEndpointTest`'s shape (Module 3 Point 3.2), on the same
 * reasoning.
 *
 * ── What the row scope actually produces today ─────────────────────────────
 *
 * §3.4's view row reads `All · Team · Out · Own · Own · Asgn · All`, and
 * `DealRowScope` (Point 2.1) backs only `all` and `own`:
 *
 * | role | sees |
 * |---|---|
 * | Super Admin | everything, on §3.1's unconditional access |
 * | Manager, CEO | everything (`all`) |
 * | Outdoor Sales, Indoor Sales | their own rows (`own`) |
 * | Team Leader, Outdoor Supervisor, Procurement | **nothing**, `team`/`out`/`asgn` have no mechanism |
 *
 * ── An out-of-scope row is 404, not 403 ────────────────────────────────────
 *
 * `OpenAPI §5.1`: 404 covers "does not exist **or** is not visible to the
 * caller. Do not reveal which case applies."
 */
final class DealListEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/deals';

    private const PASSWORD = 'Passw0rd123';

    /** @var array<string, User> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();

        // §3.12 rule 5 puts the matrix in the database, so the seeded §3.4
        // rows are the authority this endpoint is checked against.
        $this->seed(RolePermissionSeeder::class);
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

    private function customerId(string $name = 'Test Customer'): string
    {
        $id = (string) Str::uuid7();

        DB::table('customers')->insert([
            'id' => $id,
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * Inserted directly: Point 2.2 is the read side, and there is no create
     * endpoint until a later point.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function deal(string $title, ?string $ownerId = null, array $overrides = []): string
    {
        $id = (string) Str::uuid7();

        DB::table('deals')->insert(array_merge([
            'id' => $id,
            'code' => 'DL-2026-'.substr(str_replace('-', '', $id), -4),
            'customer_id' => $this->customerId(),
            'title' => $title,
            'owner_id' => $ownerId,
            'status' => 'lead',
            'last_activity_at' => now(),
            'created_by' => $ownerId,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $id;
    }

    // ─────────────────────────────── the envelope and the contract

    public function test_that_an_unauthenticated_caller_is_refused(): void
    {
        $this->getJson(self::ENDPOINT)->assertStatus(401);
    }

    public function test_that_the_payload_is_the_documented_collection_envelope(): void
    {
        $this->deal('Alpha Trading Request');

        $body = $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->json();

        self::assertIsArray($body);
        self::assertArrayHasKey('data', $body);
        self::assertArrayHasKey('meta', $body);

        $meta = $body['meta'];
        self::assertIsArray($meta);
        self::assertArrayHasKey('pagination', $meta);

        $pagination = $meta['pagination'];
        self::assertIsArray($pagination);

        foreach (['page', 'per_page', 'total', 'total_pages', 'has_next_page', 'has_previous_page'] as $key) {
            self::assertArrayHasKey($key, $pagination, "OpenAPI §4.2 requires {$key}.");
        }
    }

    /** §3.12 rule 2's spirit: a customer-facing field never belongs on an internal deal row. */
    public function test_that_the_payload_carries_no_supplier_cost_or_margin_field(): void
    {
        $this->deal('Alpha Trading Request');

        $row = $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->json('data.0');

        self::assertIsArray($row);

        foreach (array_keys($row) as $field) {
            self::assertStringNotContainsString('cost', (string) $field);
            self::assertStringNotContainsString('margin', (string) $field);
            self::assertStringNotContainsString('supplier', (string) $field);
        }
    }

    // ─────────────────────────────── row scope (SEC-08)

    public function test_that_a_manager_sees_every_row(): void
    {
        $this->deal('Alpha', $this->userWith(RoleName::IndoorSales)->id);
        $this->deal('Beta');

        $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('meta.pagination.total', 2);
    }

    public function test_that_indoor_sales_sees_only_their_own_rows(): void
    {
        $mine = $this->userWith(RoleName::IndoorSales)->id;
        $this->deal('Mine', $mine);
        $this->deal('Somebody Elses', $this->userWith(RoleName::OutdoorSales)->id);
        $this->deal('Nobodys');

        $body = $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::IndoorSales))
            ->assertStatus(200)
            ->assertJsonPath('meta.pagination.total', 1)
            ->json('data');

        self::assertIsArray($body);

        $first = $body[0];
        self::assertIsArray($first);
        self::assertSame('Mine', $first['title']);
    }

    /**
     * `Team`, `Out` and `Asgn` have no mechanism (Point 2.1) — three roles
     * resolve to nothing, and that is tested rather than left implicit.
     */
    public function test_that_a_role_whose_scope_has_no_mechanism_sees_nothing(): void
    {
        $this->deal('Alpha', $this->userWith(RoleName::IndoorSales)->id);

        foreach ([RoleName::TeamLeader, RoleName::OutdoorSupervisor, RoleName::Procurement] as $role) {
            $this->getJson(self::ENDPOINT, $this->bearerFor($role))
                ->assertStatus(200)
                ->assertJsonPath('meta.pagination.total', 0);
        }
    }

    /** §3.1 — the Super Admin is granted without consulting a cell. */
    public function test_that_the_super_admin_sees_every_row(): void
    {
        $this->deal('Alpha', $this->userWith(RoleName::IndoorSales)->id);

        $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::SuperAdmin))
            ->assertStatus(200)
            ->assertJsonPath('meta.pagination.total', 1);
    }

    /** `SEC-09` · §3.12 rule 1 — the negative authorisation case. */
    public function test_that_a_role_without_the_permission_is_refused(): void
    {
        $role = new Role;
        $role->fill(['slug' => 'auditor_without_grants', 'name' => 'Auditor', 'is_editable' => true]);
        $role->save();

        $user = new User;
        $user->fill([
            'name' => 'Grantless',
            'email' => 'grantless@example.test',
            'password' => self::PASSWORD,
            'role_id' => $role->id,
            'is_active' => true,
            'is_hidden' => false,
        ]);
        $user->save();

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertStatus(201)->json('data.token');

        self::assertIsString($token);

        $this->getJson(self::ENDPOINT, ['Authorization' => 'Bearer '.$token])->assertStatus(403);
    }

    // ─────────────────────────────── the detail route

    public function test_that_the_detail_route_answers_a_row_in_scope(): void
    {
        $id = $this->deal('Alpha', $this->userWith(RoleName::IndoorSales)->id);

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::IndoorSales))
            ->assertStatus(200)
            ->assertJsonPath('data.id', $id);
    }

    public function test_that_a_row_outside_the_callers_scope_is_404_and_not_403(): void
    {
        $id = $this->deal('Someone Elses', $this->userWith(RoleName::OutdoorSales)->id);

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::IndoorSales))
            ->assertStatus(404);
    }

    public function test_that_an_unknown_id_is_404(): void
    {
        $this->getJson(self::ENDPOINT.'/'.Str::uuid7(), $this->bearerFor(RoleName::Manager))
            ->assertStatus(404);
    }

    // ─────────────────────────────── the query contract (OpenAPI §6)

    public function test_that_an_unknown_filter_or_sort_is_400_and_never_ignored(): void
    {
        $bearer = $this->bearerFor(RoleName::Manager);

        $this->getJson(self::ENDPOINT.'?filter[colour]=red', $bearer)->assertStatus(400);
        $this->getJson(self::ENDPOINT.'?sort=colour', $bearer)->assertStatus(400);
        $this->getJson(self::ENDPOINT.'?per_page=101', $bearer)->assertStatus(400);
        $this->getJson(self::ENDPOINT.'?page=0', $bearer)->assertStatus(400);
    }

    public function test_that_the_documented_multi_field_sort_is_accepted(): void
    {
        $this->deal('Beta');
        $this->deal('Alpha');

        $this->getJson(self::ENDPOINT.'?sort=-created_at,code', $this->bearerFor(RoleName::Manager))
            ->assertStatus(200);
    }

    public function test_that_pagination_splits_the_result(): void
    {
        foreach (['A Co', 'B Co', 'C Co'] as $title) {
            $this->deal($title);
        }

        $this->getJson(self::ENDPOINT.'?per_page=2', $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('meta.pagination.total', 3)
            ->assertJsonPath('meta.pagination.total_pages', 2)
            ->assertJsonPath('meta.pagination.has_next_page', true)
            ->assertJsonCount(2, 'data');
    }

    // ─────────────────────────────── filters — §4.3's own closed vocabularies

    public function test_that_the_status_filter_narrows_the_list(): void
    {
        $this->deal('Won deal', overrides: ['status' => 'won']);
        $this->deal('Lead deal', overrides: ['status' => 'lead']);

        $this->getJson(self::ENDPOINT.'?filter[status]=won', $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.status', 'won');
    }

    public function test_that_the_approval_status_filter_narrows_the_list(): void
    {
        $this->deal('Pending request', overrides: ['approval_status' => 'pending']);
        $this->deal('Not applicable');

        $this->getJson(self::ENDPOINT.'?filter[approval_status]=pending', $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('meta.pagination.total', 1);
    }

    // ─────────────────────────────── q, through SearchService (D-48)

    public function test_that_q_matches_the_title(): void
    {
        $this->deal('Bulk cement order');
        $this->deal('Office chairs');

        $body = $this->getJson(self::ENDPOINT.'?q=cement', $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('meta.pagination.total', 1)
            ->json('data');

        self::assertIsArray($body);

        $first = $body[0];
        self::assertIsArray($first);
        self::assertSame('Bulk cement order', $first['title']);
    }

    /** `D-88`: `code` joins the deals index, so the list's own `q` finds a deal by its code. */
    public function test_that_q_finds_a_deal_by_its_code(): void
    {
        $this->deal('Bulk cement order', overrides: ['code' => 'DL-2026-0003']);
        $this->deal('Office chairs', overrides: ['code' => 'DL-2026-0104']);

        $body = $this->getJson(self::ENDPOINT.'?q=0003', $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('meta.pagination.total', 1)
            ->json('data');

        self::assertIsArray($body);

        $first = $body[0];
        self::assertIsArray($first);
        self::assertSame('DL-2026-0003', $first['code']);
    }

    /** `q` narrows within the caller's scope; it never widens past it. */
    public function test_that_q_cannot_reach_outside_the_row_scope(): void
    {
        $this->deal('Alpha Trading', $this->userWith(RoleName::OutdoorSales)->id);

        $this->getJson(self::ENDPOINT.'?q=Alpha', $this->bearerFor(RoleName::IndoorSales))
            ->assertStatus(200)
            ->assertJsonPath('meta.pagination.total', 0);
    }

    // ─────────────────────────────── the customer's name (F-07 · 1.5, D-83)

    /** `D-83`: the list row carries the name beside `customer_id`; the detail does not (the owner's list-only ruling). */
    public function test_that_each_row_carries_its_customers_name(): void
    {
        $customer = $this->customerId('Nile Trading');
        $id = $this->deal('Alpha', null, ['customer_id' => $customer]);
        $bearer = $this->bearerFor(RoleName::Manager);

        $this->getJson(self::ENDPOINT, $bearer)
            ->assertStatus(200)
            ->assertJsonPath('data.0.customer_id', $customer)
            ->assertJsonPath('data.0.customer_name', 'Nile Trading');

        $this->getJson(self::ENDPOINT.'/'.$id, $bearer)
            ->assertStatus(200)
            ->assertJsonMissingPath('data.customer_name');
    }

    /** `D-83` mechanism 3: archiving the customer (`DB-01`) does not take the name off the row. */
    public function test_that_an_archived_customers_name_still_shows(): void
    {
        $customer = $this->customerId('Nile Trading');
        $this->deal('Alpha', null, ['customer_id' => $customer]);
        DB::table('customers')->where('id', $customer)->update(['is_archived' => true]);

        $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.0.customer_name', 'Nile Trading');
    }

    /** `D-83` mechanism 4: an `own`-scoped caller's own deal names a customer somebody else owns — the port takes no scope. */
    public function test_that_a_customer_outside_the_callers_scope_is_still_named(): void
    {
        $customer = $this->customerId('Nile Trading');
        DB::table('customers')->where('id', $customer)->update(['sales_owner_id' => $this->userWith(RoleName::Manager)->id]);
        $this->deal('Mine', $this->userWith(RoleName::IndoorSales)->id, ['customer_id' => $customer]);

        $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::IndoorSales))
            ->assertStatus(200)
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.customer_name', 'Nile Trading');
    }

    /** `D-83`: "one query for N ids, never N" — the port is called once per page, with each distinct id once. */
    public function test_that_the_names_are_read_once_per_page(): void
    {
        $shared = $this->customerId('Nile Trading');
        $other = $this->customerId('Delta Steel');
        $this->deal('Alpha', null, ['customer_id' => $shared]);
        $this->deal('Beta', null, ['customer_id' => $shared]);
        $this->deal('Gamma', null, ['customer_id' => $other]);
        $fake = $this->fakeNames(static fn (array $ids): array => array_fill_keys($ids, 'Faked'));

        $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.0.customer_name', 'Faked');

        self::assertCount(1, $fake->calls);
        self::assertEqualsCanonicalizing([$shared, $other], $fake->calls[0]);
    }

    /** The owner's fallback ruling (1.3): an id the port does not name is sent as the id, never null. */
    public function test_that_an_unnamed_customer_is_sent_as_its_id(): void
    {
        $customer = $this->customerId();
        $this->deal('Alpha', null, ['customer_id' => $customer]);
        $this->fakeNames(static fn (array $ids): array => []);

        $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.0.customer_name', $customer);
    }

    /**
     * @param  \Closure(list<string>): array<string, string>  $answer
     * @return object{calls: list<list<string>>}
     */
    private function fakeNames(\Closure $answer): object
    {
        $fake = new class($answer) implements CustomerNamesInterface
        {
            /** @var list<list<string>> */
            public array $calls = [];

            /** @param  \Closure(list<string>): array<string, string>  $answer */
            public function __construct(private \Closure $answer) {}

            public function namesOf(array $customerIds): array
            {
                $this->calls[] = $customerIds;

                return ($this->answer)($customerIds);
            }
        };
        $this->app->instance(CustomerNamesInterface::class, $fake);

        return $fake;
    }
}
