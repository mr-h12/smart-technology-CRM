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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Module 5, Point 2.4 — `PATCH /deals/{id}/assign` —
 * `CustomerAssignEndpointTest`'s shape (Module 3 Point 3.5), on the same
 * reasoning throughout.
 *
 * §3.4 grants `assign_owner` to Manager (`All`) and Team Leader (`Team`)
 * only. `Team` has no mechanism (Point 2.1), so a Team Leader reaches this
 * endpoint for every deal in the company and finds none of them — the same
 * half-unreachable row `customer.assign` already carries.
 */
final class DealAssignEndpointTest extends TestCase
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

    private function deal(?string $ownerId = null): string
    {
        $id = (string) Str::uuid7();

        DB::table('customers')->insert([
            'id' => $customerId = (string) Str::uuid7(),
            'name' => 'Test Customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('deals')->insert([
            'id' => $id,
            'code' => 'DL-2026-'.substr(str_replace('-', '', $id), -4),
            'customer_id' => $customerId,
            'title' => 'Existing deal',
            'owner_id' => $ownerId,
            'status' => 'lead',
            'last_activity_at' => now(),
            'created_by' => $ownerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function assignUrl(string $id): string
    {
        return self::ENDPOINT.'/'.$id.'/assign';
    }

    private function auditCount(string $event, string $id): int
    {
        return DB::table('audit_log')->where('event', $event)->where('entity_id', $id)->count();
    }

    // ─────────────────────────────── §3.4's assign_owner row

    public function test_that_an_unauthenticated_caller_cannot_assign(): void
    {
        $this->patchJson($this->assignUrl((string) Str::uuid7()))->assertStatus(401);
    }

    /** @return array<string, array{RoleName}> */
    public static function rolesWithoutAssign(): array
    {
        return [
            'indoor sales' => [RoleName::IndoorSales],
            'outdoor sales' => [RoleName::OutdoorSales],
            'outdoor supervisor' => [RoleName::OutdoorSupervisor],
            'procurement' => [RoleName::Procurement],
            'the CEO' => [RoleName::Ceo],
        ];
    }

    #[DataProvider('rolesWithoutAssign')]
    public function test_that_a_role_without_the_permission_cannot_assign(RoleName $role): void
    {
        $owner = $this->userWith($role);
        $id = $this->deal($owner->id);
        $target = $this->userWith(RoleName::Manager);

        $this->patchJson($this->assignUrl($id), ['owner_id' => $target->id], $this->bearerFor($role))
            ->assertStatus(403);

        $this->assertDatabaseHas('deals', ['id' => $id, 'owner_id' => $owner->id]);
    }

    /** The visible cost of the unbacked `team` scope, not a decision about Flow 10. */
    public function test_that_a_team_leader_can_assign_nothing_while_team_is_unbacked(): void
    {
        $id = $this->deal();
        $target = $this->userWith(RoleName::IndoorSales);

        $this->patchJson($this->assignUrl($id), ['owner_id' => $target->id], $this->bearerFor(RoleName::TeamLeader))
            ->assertStatus(404);

        $this->assertDatabaseHas('deals', ['id' => $id, 'owner_id' => null]);
    }

    // ─────────────────────────────── the happy path

    public function test_that_a_manager_assigns_a_deal_to_another_employee(): void
    {
        $id = $this->deal($this->userWith(RoleName::IndoorSales)->id);
        $target = $this->userWith(RoleName::OutdoorSales);

        $this->patchJson($this->assignUrl($id), ['owner_id' => $target->id], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.owner_id', $target->id);

        $this->assertDatabaseHas('deals', ['id' => $id, 'owner_id' => $target->id]);
    }

    public function test_that_a_deal_with_no_owner_can_be_assigned_one(): void
    {
        $id = $this->deal();
        $target = $this->userWith(RoleName::IndoorSales);

        $this->patchJson($this->assignUrl($id), ['owner_id' => $target->id], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200);

        $this->assertDatabaseHas('deals', ['id' => $id, 'owner_id' => $target->id]);
    }

    public function test_that_assigning_writes_the_reassignment_audit_entry(): void
    {
        $id = $this->deal($this->userWith(RoleName::IndoorSales)->id);
        $target = $this->userWith(RoleName::OutdoorSales);

        $this->patchJson($this->assignUrl($id), ['owner_id' => $target->id], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200);

        self::assertSame(1, $this->auditCount('DEAL_REASSIGNED', $id));
    }

    public function test_that_assigning_to_the_current_owner_changes_nothing_and_records_nothing(): void
    {
        $owner = $this->userWith(RoleName::IndoorSales);
        $id = $this->deal($owner->id);

        $this->patchJson($this->assignUrl($id), ['owner_id' => $owner->id], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200);

        self::assertSame(0, $this->auditCount('DEAL_REASSIGNED', $id));
    }

    // ─────────────────────────────── the query contract

    public function test_that_an_unknown_deal_is_404(): void
    {
        $target = $this->userWith(RoleName::IndoorSales);

        $this->patchJson($this->assignUrl((string) Str::uuid7()), ['owner_id' => $target->id], $this->bearerFor(RoleName::Manager))
            ->assertStatus(404);
    }

    public function test_that_a_missing_owner_is_refused(): void
    {
        $id = $this->deal();

        $this->patchJson($this->assignUrl($id), [], $this->bearerFor(RoleName::Manager))
            ->assertStatus(422);
    }

    public function test_that_a_malformed_owner_id_is_refused(): void
    {
        $id = $this->deal();

        $this->patchJson($this->assignUrl($id), ['owner_id' => 'not-a-uuid'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(422);
    }

    public function test_that_an_owner_who_is_not_a_user_is_refused(): void
    {
        $id = $this->deal();

        $this->patchJson($this->assignUrl($id), ['owner_id' => (string) Str::uuid7()], $this->bearerFor(RoleName::Manager))
            ->assertStatus(422);

        $this->assertDatabaseHas('deals', ['id' => $id, 'owner_id' => null]);
    }
}
