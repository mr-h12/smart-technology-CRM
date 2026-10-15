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
 * `D-123` (F-50 · 4) — `PATCH /customers/{id}/status {status, reason}`.
 *
 * Under `customer.change_status` at the customer's own row scope (§3.3's new
 * row, seeded like `customer.edit`). The three statuses below may be set by
 * hand; «عميل» is never set and never left by hand (`D-49`).
 */
final class CustomerStatusEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Passw0rd123';

    /** @var array<string, User> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_that_a_status_is_set_by_hand_with_its_reason_and_audited(): void
    {
        $id = $this->customer('Alpha Trading');
        $manager = $this->userWith(RoleName::Manager);

        $this->patchJson($this->url($id), ['status' => 'no_response', 'reason' => '  Three calls unanswered.  '], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.customer_status', 'no_response')
            ->assertJsonPath('data.status_reason', 'Three calls unanswered.');

        $this->assertDatabaseHas('customers', ['id' => $id, 'customer_status' => 'no_response', 'status_reason' => 'Three calls unanswered.']);

        $audit = DB::table('audit_log')->where('event', 'CUSTOMER_STATUS_CHANGED')->where('entity_id', $id)->first();
        self::assertNotNull($audit);
        self::assertSame('customer', $audit->entity_type);
        self::assertSame($manager->id, $audit->user_id);
        self::assertIsString($audit->old_values);
        self::assertIsString($audit->new_values);
        self::assertSame(['customer_status' => 'prospect'], json_decode($audit->old_values, true));
        // `jsonb` keeps its own key order, so the comparison is by key, not position.
        self::assertEquals(['customer_status' => 'no_response', 'status_reason' => 'Three calls unanswered.'], json_decode($audit->new_values, true));
    }

    public function test_that_each_of_the_three_statuses_may_be_set(): void
    {
        $id = $this->customer('Alpha Trading');
        $bearer = $this->bearerFor(RoleName::Manager);

        foreach (['deal_not_completed', 'no_response', 'prospect'] as $status) {
            $this->patchJson($this->url($id), ['status' => $status, 'reason' => "Set to {$status}."], $bearer)
                ->assertStatus(200)
                ->assertJsonPath('data.customer_status', $status)
                ->assertJsonPath('data.status_reason', "Set to {$status}.");
        }

        self::assertSame(3, DB::table('audit_log')->where('event', 'CUSTOMER_STATUS_CHANGED')->where('entity_id', $id)->count());
    }

    public function test_that_a_missing_blank_or_long_reason_is_422(): void
    {
        $id = $this->customer('Alpha Trading');
        $bearer = $this->bearerFor(RoleName::Manager);

        foreach ([
            ['status' => 'no_response'],
            ['status' => 'no_response', 'reason' => '   '],
            ['status' => 'no_response', 'reason' => str_repeat('a', 1001)],
            ['status' => 'no_response', 'reason' => ['not', 'text']],
        ] as $payload) {
            $this->patchJson($this->url($id), $payload, $bearer)
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'validation_failed')
                ->assertJsonPath('error.details.0.field', 'reason');
        }

        $this->assertUnchanged($id, 'prospect');

        // The ceiling itself is allowed.
        $this->patchJson($this->url($id), ['status' => 'no_response', 'reason' => str_repeat('a', 1000)], $bearer)->assertStatus(200);
    }

    /** `D-49`: «عميل» is never the target, and an unknown code is no status at all. */
    public function test_that_customer_or_an_unknown_status_as_the_target_is_422(): void
    {
        $id = $this->customer('Alpha Trading');
        $bearer = $this->bearerFor(RoleName::Manager);

        foreach ([['reason' => 'x'], ['status' => 'customer', 'reason' => 'x'], ['status' => 'won', 'reason' => 'x'], ['status' => '', 'reason' => 'x']] as $payload) {
            $this->patchJson($this->url($id), $payload, $bearer)
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'validation_failed')
                ->assertJsonPath('error.details.0.field', 'status');
        }

        $this->assertUnchanged($id, 'prospect');
    }

    /** `D-49`: a customer who has reached «عميل» never leaves it by hand. */
    public function test_that_a_customer_already_customer_is_422(): void
    {
        $id = $this->customer('Alpha Trading', status: 'customer');

        $this->patchJson($this->url($id), ['status' => 'prospect', 'reason' => 'Try to undo.'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.details.0.field', 'status');

        $this->assertUnchanged($id, 'customer');
    }

    /** `D-123`: the new status must differ from the current one. */
    public function test_that_the_current_status_again_is_422(): void
    {
        $id = $this->customer('Alpha Trading', status: 'no_response');

        $this->patchJson($this->url($id), ['status' => 'no_response', 'reason' => 'Again.'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.details.0.field', 'status');

        $this->assertUnchanged($id, 'no_response');
    }

    /** §3.3's new row: Procurement and the CEO view customers and change no status. */
    public function test_that_procurement_and_the_ceo_are_403(): void
    {
        $id = $this->customer('Alpha Trading');

        foreach ([RoleName::Procurement, RoleName::Ceo] as $role) {
            $this->patchJson($this->url($id), ['status' => 'no_response', 'reason' => 'No.'], $this->bearerFor($role))
                ->assertStatus(403);
        }

        $this->assertUnchanged($id, 'prospect');
    }

    public function test_that_an_out_of_scope_or_unknown_customer_is_404(): void
    {
        $id = $this->customer('Somebody Elses', $this->userWith(RoleName::OutdoorSales)->id);

        $this->patchJson($this->url($id), ['status' => 'no_response', 'reason' => 'Mine now.'], $this->bearerFor(RoleName::IndoorSales))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found');

        $this->patchJson($this->url((string) Str::uuid7()), ['status' => 'no_response', 'reason' => 'Nobody.'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(404);

        $this->assertUnchanged($id, 'prospect');
    }

    /** `Own`: a salesperson sets the status of their own customer. */
    public function test_that_own_scope_sets_its_own_customer(): void
    {
        $id = $this->customer('Mine', $this->userWith(RoleName::IndoorSales)->id);

        $this->patchJson($this->url($id), ['status' => 'deal_not_completed', 'reason' => 'Chose a competitor by phone.'], $this->bearerFor(RoleName::IndoorSales))
            ->assertStatus(200)
            ->assertJsonPath('data.customer_status', 'deal_not_completed');
    }

    /** §3.12: the Super Admin's unconditional access reaches the route at `All`. */
    public function test_that_the_super_admin_sets_any_customer(): void
    {
        $id = $this->customer('Somebody Elses', $this->userWith(RoleName::OutdoorSales)->id);

        $this->patchJson($this->url($id), ['status' => 'no_response', 'reason' => 'Checked by the admin.'], $this->bearerFor(RoleName::SuperAdmin))
            ->assertStatus(200)
            ->assertJsonPath('data.customer_status', 'no_response');
    }

    /** `D-123` (d): as `customer.edit` does, an archived customer's status may be set. */
    public function test_that_an_archived_customer_may_be_set(): void
    {
        $id = $this->customer('Archived Co', archived: true);

        $this->patchJson($this->url($id), ['status' => 'no_response', 'reason' => 'Closed shop.'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.customer_status', 'no_response');
    }

    /** A derived status carries no reason. */
    public function test_that_the_customer_payload_carries_a_null_reason_while_derived(): void
    {
        $id = $this->customer('Alpha Trading');

        $this->getJson('/api/v1/customers/'.$id, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonStructure(['data' => ['status_reason']])
            ->assertJsonPath('data.status_reason', null);
    }

    /** §3.3's new row is seeded exactly as `customer.edit` is. */
    public function test_that_the_permission_is_seeded_like_customer_edit(): void
    {
        self::assertSame($this->grants('edit'), $this->grants('change_status'));
        self::assertSame(
            ['indoor_sales' => 'own', 'manager' => 'all', 'outdoor_sales' => 'own', 'outdoor_supervisor' => 'out', 'team_leader' => 'team'],
            $this->grants('change_status'),
        );
    }

    // ─────────────────────────────── helpers

    private function url(string $customerId): string
    {
        return '/api/v1/customers/'.$customerId.'/status';
    }

    private function assertUnchanged(string $id, string $status): void
    {
        $this->assertDatabaseHas('customers', ['id' => $id, 'customer_status' => $status, 'status_reason' => null]);
        self::assertSame(0, DB::table('audit_log')->where('event', 'CUSTOMER_STATUS_CHANGED')->where('entity_id', $id)->count());
    }

    /** @return array<string, string> role slug ⇒ scope, for one customer action */
    private function grants(string $action): array
    {
        /** @var array<string, string> $rows */
        $rows = DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->where('permissions.resource', 'customer')
            ->where('permissions.action', $action)
            ->whereNull('role_permissions.deleted_at')
            ->whereNull('permissions.deleted_at')
            ->where('roles.slug', '!=', 'super_admin')
            ->pluck('permissions.scope', 'roles.slug')
            ->all();
        ksort($rows);

        return $rows;
    }

    private function customer(string $name, ?string $ownerId = null, string $status = 'prospect', bool $archived = false): string
    {
        $id = (string) Str::uuid7();

        DB::table('customers')->insert([
            'id' => $id,
            'name' => $name,
            'customer_status' => $status,
            'sales_owner_id' => $ownerId,
            'is_archived' => $archived,
            'is_incomplete' => false,
            'created_by' => $ownerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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
