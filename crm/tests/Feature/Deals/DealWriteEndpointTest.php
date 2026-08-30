<?php

declare(strict_types=1);

namespace Tests\Feature\Deals;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Module 5, Point 2.3 — `POST /deals` and `PATCH /deals/{id}` —
 * `CustomerWriteEndpointTest`'s shape (Module 3 Point 3.3), on the same
 * reasoning throughout.
 *
 * §3.4's create row grants `All` to Manager and Team Leader, `Out` (unbacked)
 * to the Outdoor Supervisor, and `Own` to Outdoor and Indoor Sales — Procurement
 * and the CEO hold no `deal.create` grant at all. `edit` grants the same five
 * roles §3.4's view row does, `Team`/`Out`/`Asgn` unbacked exactly as they are
 * on read.
 */
final class DealWriteEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/deals';

    private const PASSWORD = 'Passw0rd123';

    /** @var array<string, User> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();

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

    private function customerId(): string
    {
        $id = (string) Str::uuid7();

        DB::table('customers')->insert([
            'id' => $id,
            'name' => 'Test Customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** @param  array<string, mixed>  $overrides */
    private function deal(?string $ownerId = null, array $overrides = []): string
    {
        $id = (string) Str::uuid7();

        DB::table('deals')->insert(array_merge([
            'id' => $id,
            'code' => 'DL-2026-'.substr(str_replace('-', '', $id), -4),
            'customer_id' => $this->customerId(),
            'title' => 'Existing deal',
            'owner_id' => $ownerId,
            'status' => 'lead',
            'last_activity_at' => now(),
            'created_by' => $ownerId,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $id;
    }

    // ─────────────────────────────── POST

    public function test_that_an_unauthenticated_caller_cannot_create(): void
    {
        $this->postJson(self::ENDPOINT, ['customer_id' => $this->customerId()])->assertStatus(401);
    }

    public function test_that_a_created_deal_returns_201_and_the_single_envelope(): void
    {
        $customerId = $this->customerId();

        $body = $this->postJson(self::ENDPOINT, [
            'customer_id' => $customerId,
            'title' => 'Bulk cement order',
            'source' => 'employee_entry',
            'service_type' => 'product',
        ], $this->bearerFor(RoleName::Manager))->assertStatus(201)->json();

        self::assertIsArray($body);
        self::assertArrayHasKey('data', $body);
        self::assertArrayHasKey('meta', $body);

        $data = $body['data'];
        self::assertIsArray($data);
        self::assertSame('Bulk cement order', $data['title']);
        self::assertSame($customerId, $data['customer_id']);
        self::assertSame('lead', $data['status']);
        self::assertIsString($data['code']);
        self::assertStringStartsWith('DL-', $data['code']);

        self::assertIsString($data['id']);
        self::assertDatabaseHas('deals', ['id' => $data['id'], 'customer_id' => $customerId]);
    }

    public function test_that_a_customer_id_is_required(): void
    {
        $this->postJson(self::ENDPOINT, ['title' => 'No customer'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    /** §4.7: the code is generated, never sent. */
    public function test_that_the_caller_cannot_set_the_code(): void
    {
        $this->postJson(self::ENDPOINT, [
            'customer_id' => $this->customerId(),
            'code' => 'DL-2026-9999',
        ], $this->bearerFor(RoleName::Manager))->assertStatus(422);
    }

    /** §4.4: status is a state machine, never typed directly. */
    public function test_that_the_caller_cannot_set_the_status(): void
    {
        $this->postJson(self::ENDPOINT, [
            'customer_id' => $this->customerId(),
            'status' => 'won',
        ], $this->bearerFor(RoleName::Manager))->assertStatus(422);
    }

    public function test_that_two_created_deals_receive_different_codes(): void
    {
        $bearer = $this->bearerFor(RoleName::Manager);
        $customerId = $this->customerId();

        $first = $this->postJson(self::ENDPOINT, ['customer_id' => $customerId], $bearer)
            ->assertStatus(201)->json('data.code');
        $second = $this->postJson(self::ENDPOINT, ['customer_id' => $customerId], $bearer)
            ->assertStatus(201)->json('data.code');

        self::assertIsString($first);
        self::assertIsString($second);
        self::assertNotSame($first, $second);
    }

    // ─────────────────────────────── POST: §3.4's create scope

    public function test_that_procurement_may_not_create_a_deal(): void
    {
        $this->postJson(self::ENDPOINT, ['customer_id' => $this->customerId()], $this->bearerFor(RoleName::Procurement))
            ->assertStatus(403);
    }

    public function test_that_the_ceo_may_not_create_a_deal(): void
    {
        $this->postJson(self::ENDPOINT, ['customer_id' => $this->customerId()], $this->bearerFor(RoleName::Ceo))
            ->assertStatus(403);
    }

    /** The visible cost of Point 2.1's deferral: `Out` has no mechanism, so it permits nothing. */
    public function test_that_an_outdoor_supervisor_cannot_create_while_out_is_unbacked(): void
    {
        $this->postJson(self::ENDPOINT, ['customer_id' => $this->customerId()], $this->bearerFor(RoleName::OutdoorSupervisor))
            ->assertStatus(403);
    }

    public function test_that_indoor_sales_becomes_the_owner_of_what_they_create(): void
    {
        $id = $this->postJson(self::ENDPOINT, ['customer_id' => $this->customerId()], $this->bearerFor(RoleName::IndoorSales))
            ->assertStatus(201)
            ->json('data.id');

        self::assertIsString($id);
        self::assertDatabaseHas('deals', ['id' => $id, 'owner_id' => $this->userWith(RoleName::IndoorSales)->id]);
    }

    public function test_that_indoor_sales_cannot_file_a_deal_under_somebody_else(): void
    {
        $this->postJson(self::ENDPOINT, [
            'customer_id' => $this->customerId(),
            'owner_id' => $this->userWith(RoleName::OutdoorSales)->id,
        ], $this->bearerFor(RoleName::IndoorSales))->assertStatus(403);
    }

    public function test_that_a_manager_may_file_a_deal_under_anybody(): void
    {
        $owner = $this->userWith(RoleName::IndoorSales)->id;

        $id = $this->postJson(self::ENDPOINT, [
            'customer_id' => $this->customerId(),
            'owner_id' => $owner,
        ], $this->bearerFor(RoleName::Manager))->assertStatus(201)->json('data.id');

        self::assertIsString($id);
        self::assertDatabaseHas('deals', ['id' => $id, 'owner_id' => $owner]);
    }

    // ─────────────────────────────── POST: approval_status, derived by who creates

    /** Flow 1: a Team-Leader-entered deal needs no approval. */
    public function test_that_a_team_leader_created_deal_needs_no_approval(): void
    {
        $id = $this->postJson(self::ENDPOINT, ['customer_id' => $this->customerId()], $this->bearerFor(RoleName::TeamLeader))
            ->assertStatus(201)
            ->json('data.id');

        self::assertIsString($id);
        self::assertDatabaseHas('deals', ['id' => $id, 'approval_status' => null]);
    }

    /** Flow 3: an employee-entered request needs the Team Leader's approval. */
    public function test_that_an_indoor_sales_created_deal_is_pending_approval(): void
    {
        $id = $this->postJson(self::ENDPOINT, ['customer_id' => $this->customerId()], $this->bearerFor(RoleName::IndoorSales))
            ->assertStatus(201)
            ->json('data.id');

        self::assertIsString($id);
        self::assertDatabaseHas('deals', ['id' => $id, 'approval_status' => 'pending']);
    }

    // ─────────────────────────────── PATCH

    public function test_that_a_manager_updates_a_deal(): void
    {
        $id = $this->deal();

        $this->patchJson(self::ENDPOINT.'/'.$id, ['title' => 'Renamed'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.title', 'Renamed');

        self::assertDatabaseHas('deals', ['id' => $id, 'title' => 'Renamed']);
    }

    public function test_that_a_row_outside_the_callers_scope_is_404_on_update(): void
    {
        $id = $this->deal($this->userWith(RoleName::OutdoorSales)->id);

        $this->patchJson(self::ENDPOINT.'/'.$id, ['title' => 'Renamed'], $this->bearerFor(RoleName::IndoorSales))
            ->assertStatus(404);
    }

    public function test_that_an_unknown_id_is_404_on_update(): void
    {
        $this->patchJson(self::ENDPOINT.'/'.Str::uuid7(), ['title' => 'X'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(404);
    }

    /** The visible cost of Point 2.1's deferral, on write as well as read. */
    public function test_that_a_role_whose_scope_has_no_mechanism_cannot_update(): void
    {
        $id = $this->deal($this->userWith(RoleName::IndoorSales)->id);

        foreach ([RoleName::TeamLeader, RoleName::OutdoorSupervisor, RoleName::Procurement] as $role) {
            $this->patchJson(self::ENDPOINT.'/'.$id, ['title' => 'Renamed'], $this->bearerFor($role))
                ->assertStatus(404);
        }
    }

    public function test_that_the_customer_cannot_be_transferred_through_a_generic_update(): void
    {
        $id = $this->deal();

        $this->patchJson(self::ENDPOINT.'/'.$id, ['customer_id' => $this->customerId()], $this->bearerFor(RoleName::Manager))
            ->assertStatus(422);
    }

    public function test_that_the_owner_cannot_be_transferred_through_a_generic_update(): void
    {
        $id = $this->deal();

        $this->patchJson(self::ENDPOINT.'/'.$id, [
            'owner_id' => $this->userWith(RoleName::IndoorSales)->id,
        ], $this->bearerFor(RoleName::Manager))->assertStatus(422);
    }

    public function test_that_the_status_cannot_be_edited(): void
    {
        $id = $this->deal();

        $this->patchJson(self::ENDPOINT.'/'.$id, ['status' => 'won'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(422);
    }

    // ─────────────────────────────── AUD-01

    public function test_that_creating_a_deal_writes_an_audit_entry(): void
    {
        $id = $this->postJson(self::ENDPOINT, [
            'customer_id' => $this->customerId(),
            'title' => 'Alpha',
        ], $this->bearerFor(RoleName::Manager))->assertStatus(201)->json('data.id');

        self::assertDatabaseHas('audit_log', [
            'event' => 'DEAL_CREATED',
            'entity_type' => 'deal',
            'entity_id' => $id,
        ]);
    }

    public function test_that_updating_a_deal_writes_an_audit_entry_with_the_old_and_new_value(): void
    {
        $id = $this->deal(overrides: ['title' => 'Before']);

        $this->patchJson(self::ENDPOINT.'/'.$id, ['title' => 'After'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200);

        $row = DB::table('audit_log')->where('entity_id', $id)->where('event', 'DEAL_UPDATED')->first();

        self::assertIsObject($row);
        self::assertObjectHasProperty('old_values', $row);
        self::assertObjectHasProperty('new_values', $row);

        self::assertSame(
            ['title' => 'Before'],
            json_decode((string) $row->old_values, true),   // @phpstan-ignore-line cast.string
        );
        self::assertSame(
            ['title' => 'After'],
            json_decode((string) $row->new_values, true),   // @phpstan-ignore-line cast.string
        );
    }
}
