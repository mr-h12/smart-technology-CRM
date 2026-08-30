<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Module 3, Point 3.2 — `GET /customers` and `GET /customers/{id}`.
 *
 * ── What the row scope actually produces today ─────────────────────────────
 *
 * §3.3's view row reads `All · Team · Out · Own · Own · Asgn · All`, and
 * `CustomerRowScope` (Point 3.1) backs only `all` and `own`; `team`, `out` and
 * `asgn` fail closed by the owner's deferral of 2026-08-29. So this endpoint
 * answers:
 *
 * | role | sees |
 * |---|---|
 * | Super Admin | everything, on §3.1's unconditional access |
 * | Manager, CEO | everything (`all`) |
 * | Outdoor Sales, Indoor Sales | their own rows (`own`) |
 * | Team Leader, Outdoor Supervisor, Procurement | **nothing**, until the debt closes |
 *
 * That last row is tested rather than left implicit: it is the visible cost of
 * the deferral, and a test is how it stops being a surprise.
 *
 * ── An out-of-scope row is 404, not 403 ────────────────────────────────────
 *
 * `OpenAPI §5.1`: 404 covers "does not exist **or** is not visible to the
 * caller. Do not reveal which case applies." A 403 on the detail route would
 * confirm the customer exists to somebody forbidden from seeing it.
 */
final class CustomerListEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/customers';

    private const PASSWORD = 'Passw0rd123';

    /** @var array<string, User> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();

        // §3.12 rule 5 puts the matrix in the database, so the seeded §3.3 rows
        // are the authority this endpoint is checked against — not a fixture
        // written here, which would only agree with itself.
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

    /** Inserted directly: Point 3.2 is the read side, and there is no create endpoint until 3.3. */
    private function customer(string $name, ?string $ownerId = null, bool $archived = false, bool $incomplete = false): string
    {
        $id = (string) Str::uuid7();

        DB::table('customers')->insert([
            'id' => $id,
            'name' => $name,
            'customer_status' => 'prospect',
            'sales_owner_id' => $ownerId,
            'is_archived' => $archived,
            'is_incomplete' => $incomplete,
            'created_by' => $ownerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    // ─────────────────────────────── the envelope and the contract

    public function test_that_an_unauthenticated_caller_is_refused(): void
    {
        $this->getJson(self::ENDPOINT)->assertStatus(401);
    }

    public function test_that_the_payload_is_the_documented_collection_envelope(): void
    {
        $this->customer('Alpha Trading');

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

    /** §3.12 rule 2's spirit: a customer payload is not the place for anything a supplier owns. */
    public function test_that_the_payload_carries_no_cost_or_margin_field(): void
    {
        $this->customer('Alpha Trading');

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
        $this->customer('Alpha Trading', $this->userWith(RoleName::IndoorSales)->id);
        $this->customer('Beta Supplies');

        $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('meta.pagination.total', 2);
    }

    public function test_that_indoor_sales_sees_only_their_own_rows(): void
    {
        $mine = $this->userWith(RoleName::IndoorSales)->id;
        $this->customer('Mine', $mine);
        $this->customer('Somebody Elses', $this->userWith(RoleName::OutdoorSales)->id);
        $this->customer('Nobodys');

        $body = $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::IndoorSales))
            ->assertStatus(200)
            ->assertJsonPath('meta.pagination.total', 1)
            ->json('data');

        self::assertIsArray($body);

        $first = $body[0];
        self::assertIsArray($first);
        self::assertSame('Mine', $first['name']);
    }

    /**
     * The visible cost of the 2026-08-29 deferral: three scopes have no
     * mechanism, so three roles resolve to nothing.
     */
    public function test_that_a_role_whose_scope_has_no_mechanism_sees_nothing(): void
    {
        $this->customer('Alpha Trading', $this->userWith(RoleName::IndoorSales)->id);

        foreach ([RoleName::TeamLeader, RoleName::OutdoorSupervisor, RoleName::Procurement] as $role) {
            $this->getJson(self::ENDPOINT, $this->bearerFor($role))
                ->assertStatus(200)
                ->assertJsonPath('meta.pagination.total', 0);
        }
    }

    /** §3.1 — the Super Admin is granted without consulting a cell. */
    public function test_that_the_super_admin_sees_every_row(): void
    {
        $this->customer('Alpha Trading', $this->userWith(RoleName::IndoorSales)->id);

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
        $id = $this->customer('Alpha Trading', $this->userWith(RoleName::IndoorSales)->id);

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::IndoorSales))
            ->assertStatus(200)
            ->assertJsonPath('data.id', $id);
    }

    public function test_that_a_row_outside_the_callers_scope_is_404_and_not_403(): void
    {
        $id = $this->customer('Someone Elses', $this->userWith(RoleName::OutdoorSales)->id);

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

    /** §6.2's own example for this resource is `sort=-created_at,name` — two fields. */
    public function test_that_the_documented_multi_field_sort_is_accepted(): void
    {
        $this->customer('Beta Supplies');
        $this->customer('Alpha Trading');

        $this->getJson(self::ENDPOINT.'?sort=-created_at,name', $this->bearerFor(RoleName::Manager))
            ->assertStatus(200);
    }

    public function test_that_pagination_splits_the_result(): void
    {
        foreach (['A Co', 'B Co', 'C Co'] as $name) {
            $this->customer($name);
        }

        $this->getJson(self::ENDPOINT.'?per_page=2', $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('meta.pagination.total', 3)
            ->assertJsonPath('meta.pagination.total_pages', 2)
            ->assertJsonPath('meta.pagination.has_next_page', true)
            ->assertJsonCount(2, 'data');
    }

    // ─────────────────────────────── q, through SearchService (D-48)

    /** §10.2's normalisation, proved end to end: the query is unnormalised Arabic. */
    public function test_that_q_matches_across_arabic_hamza_and_yaa_forms(): void
    {
        $this->customer('أحمد للتجارة');
        $this->customer('Beta Supplies');

        $body = $this->getJson(self::ENDPOINT.'?q=احمد', $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('meta.pagination.total', 1)
            ->json('data');

        self::assertIsArray($body);

        $first = $body[0];
        self::assertIsArray($first);
        self::assertSame('أحمد للتجارة', $first['name']);
    }

    /** `q` narrows within the caller's scope; it never widens past it. */
    public function test_that_q_cannot_reach_outside_the_row_scope(): void
    {
        $this->customer('Alpha Trading', $this->userWith(RoleName::OutdoorSales)->id);

        $this->getJson(self::ENDPOINT.'?q=Alpha', $this->bearerFor(RoleName::IndoorSales))
            ->assertStatus(200)
            ->assertJsonPath('meta.pagination.total', 0);
    }

    // ─────────────────────────────── §10.1's filter

    public function test_that_the_deactivated_employee_filter_selects_their_customers(): void
    {
        $leaver = $this->userWith(RoleName::OutdoorSales);
        $this->customer('Left Behind', $leaver->id);
        $this->customer('Still Active', $this->userWith(RoleName::IndoorSales)->id);

        $leaver->is_active = false;
        $leaver->save();

        $body = $this->getJson(self::ENDPOINT.'?filter[owner_inactive]=true', $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('meta.pagination.total', 1)
            ->json('data');

        self::assertIsArray($body);

        $first = $body[0];
        self::assertIsArray($first);
        self::assertSame('Left Behind', $first['name']);
    }

    /** `D-34` — the customer stays attached to the deactivated employee, never reassigned automatically. */
    public function test_that_a_deactivated_owner_keeps_their_customers(): void
    {
        $leaver = $this->userWith(RoleName::OutdoorSales);
        $id = $this->customer('Left Behind', $leaver->id);

        $leaver->is_active = false;
        $leaver->save();

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.sales_owner_id', $leaver->id);
    }

    // ─────────────────────────────── archive

    public function test_that_an_archived_customer_is_absent_unless_asked_for(): void
    {
        $this->customer('Live One');
        $this->customer('Archived One', null, true);

        $bearer = $this->bearerFor(RoleName::Manager);

        $this->getJson(self::ENDPOINT, $bearer)
            ->assertStatus(200)
            ->assertJsonPath('meta.pagination.total', 1);

        $this->getJson(self::ENDPOINT.'?filter[is_archived]=true', $bearer)
            ->assertStatus(200)
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.name', 'Archived One');
    }

    /**
     * §10's "dedicated filter", proved on the server rather than on the wire.
     *
     * The Module 3 acceptance criterion is *"import with missing fields →
     * record saves flagged **incomplete**, in a dedicated filter"*. Its first
     * two clauses are `CustomerImportEndpointTest`'s. The third was covered
     * only by frontend specs asserting that `filter[is_incomplete]=true`
     * **reaches** the URL — which proves the request is built, not that the
     * server narrows anything. A filter that is accepted and ignored answers
     * 200 with every row and passes every test that only reads the query
     * string.
     *
     * Three assertions, because the interesting half is the third: unfiltered
     * shows both, `true` shows only the flagged one, and **absence is not
     * `false`** — `is_incomplete` is the one filter `CustomerListCriteria`
     * leaves null rather than defaulting, so omitting it must not silently
     * mean "complete records only".
     */
    public function test_that_the_incomplete_filter_selects_only_flagged_records(): void
    {
        $this->customer('Complete One');
        $this->customer('Flagged One', null, false, true);

        $bearer = $this->bearerFor(RoleName::Manager);

        $this->getJson(self::ENDPOINT, $bearer)
            ->assertStatus(200)
            ->assertJsonPath('meta.pagination.total', 2);

        $this->getJson(self::ENDPOINT.'?filter[is_incomplete]=true', $bearer)
            ->assertStatus(200)
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.name', 'Flagged One')
            ->assertJsonPath('data.0.is_incomplete', true);

        // The other direction, so a filter stuck on one answer cannot pass.
        $this->getJson(self::ENDPOINT.'?filter[is_incomplete]=false', $bearer)
            ->assertStatus(200)
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.name', 'Complete One');
    }
}
