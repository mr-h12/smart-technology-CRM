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
 * Module 5, Point 2.5 — `PATCH /deals/{id}/approve` and `/reject`.
 *
 * §3.4 seeds one permission, `deal.approve`, for both directions — no
 * `deal.reject` row exists, on `customer.archive`'s precedent for
 * `archive`/`restore` sharing one row. Both routes are granted to Manager
 * (`All`) and Team Leader (`Team`) only; `Team` still has no mechanism
 * (Point 2.1).
 *
 * Unlike archive/restore, deciding a deal that is not currently `pending` is
 * refused with `409 state_transition_invalid` rather than treated as an
 * idempotent repeat — see `DealApprovalRefused`'s own docblock for why.
 */
final class DealApprovalEndpointTest extends TestCase
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

    /** @param  array<string, mixed>  $overrides */
    private function deal(?string $ownerId = null, array $overrides = []): string
    {
        $id = (string) Str::uuid7();

        DB::table('customers')->insert([
            'id' => $customerId = (string) Str::uuid7(),
            'name' => 'Test Customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('deals')->insert(array_merge([
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
        ], $overrides));

        return $id;
    }

    private function approveUrl(string $id): string
    {
        return self::ENDPOINT.'/'.$id.'/approve';
    }

    private function rejectUrl(string $id): string
    {
        return self::ENDPOINT.'/'.$id.'/reject';
    }

    private function auditCount(string $event, string $id): int
    {
        return DB::table('audit_log')->where('event', $event)->where('entity_id', $id)->count();
    }

    // ─────────────────────────────── §3.4's approve row

    public function test_that_an_unauthenticated_caller_cannot_approve(): void
    {
        $this->patchJson($this->approveUrl((string) Str::uuid7()))->assertStatus(401);
    }

    /** @return array<string, array{RoleName}> */
    public static function rolesWithoutApprove(): array
    {
        return [
            'indoor sales' => [RoleName::IndoorSales],
            'outdoor sales' => [RoleName::OutdoorSales],
            'outdoor supervisor' => [RoleName::OutdoorSupervisor],
            'procurement' => [RoleName::Procurement],
            'the CEO' => [RoleName::Ceo],
        ];
    }

    #[DataProvider('rolesWithoutApprove')]
    public function test_that_a_role_without_the_permission_cannot_approve(RoleName $role): void
    {
        $id = $this->deal(overrides: ['approval_status' => 'pending']);

        $this->patchJson($this->approveUrl($id), [], $this->bearerFor($role))->assertStatus(403);

        $this->assertDatabaseHas('deals', ['id' => $id, 'approval_status' => 'pending']);
    }

    /** The visible cost of the unbacked `team` scope, not a decision about Flow 3. */
    public function test_that_a_team_leader_can_decide_nothing_while_team_is_unbacked(): void
    {
        $id = $this->deal(overrides: ['approval_status' => 'pending']);

        $this->patchJson($this->approveUrl($id), [], $this->bearerFor(RoleName::TeamLeader))
            ->assertStatus(404);
    }

    // ─────────────────────────────── the happy paths

    public function test_that_a_manager_approves_a_pending_deal(): void
    {
        $id = $this->deal(overrides: ['approval_status' => 'pending']);

        $this->patchJson($this->approveUrl($id), [], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.approval_status', 'approved');

        $this->assertDatabaseHas('deals', ['id' => $id, 'approval_status' => 'approved']);
    }

    public function test_that_a_manager_rejects_a_pending_deal_with_a_reason(): void
    {
        $id = $this->deal(overrides: ['approval_status' => 'pending']);

        $this->patchJson($this->rejectUrl($id), ['reason' => 'Customer withdrew'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.approval_status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'Customer withdrew');

        $this->assertDatabaseHas('deals', [
            'id' => $id,
            'approval_status' => 'rejected',
            'rejection_reason' => 'Customer withdrew',
        ]);
    }

    public function test_that_approving_writes_an_audit_entry(): void
    {
        $id = $this->deal(overrides: ['approval_status' => 'pending']);

        $this->patchJson($this->approveUrl($id), [], $this->bearerFor(RoleName::Manager))->assertStatus(200);

        self::assertSame(1, $this->auditCount('DEAL_APPROVED', $id));
    }

    public function test_that_rejecting_writes_an_audit_entry(): void
    {
        $id = $this->deal(overrides: ['approval_status' => 'pending']);

        $this->patchJson($this->rejectUrl($id), ['reason' => 'Customer withdrew'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200);

        self::assertSame(1, $this->auditCount('DEAL_REJECTED', $id));
    }

    // ─────────────────────────────── the state machine (§5.1's 409)

    public function test_that_a_deal_never_submitted_for_approval_cannot_be_decided(): void
    {
        $id = $this->deal(overrides: ['approval_status' => null]);

        $this->patchJson($this->approveUrl($id), [], $this->bearerFor(RoleName::Manager))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'state_transition_invalid');
    }

    public function test_that_an_already_approved_deal_cannot_be_approved_again(): void
    {
        $id = $this->deal(overrides: ['approval_status' => 'approved']);

        $this->patchJson($this->approveUrl($id), [], $this->bearerFor(RoleName::Manager))
            ->assertStatus(409);
    }

    public function test_that_an_already_rejected_deal_cannot_be_approved(): void
    {
        $id = $this->deal(overrides: ['approval_status' => 'rejected', 'rejection_reason' => 'Original reason']);

        $this->patchJson($this->approveUrl($id), [], $this->bearerFor(RoleName::Manager))
            ->assertStatus(409);
    }

    public function test_that_an_already_approved_deal_cannot_be_rejected(): void
    {
        $id = $this->deal(overrides: ['approval_status' => 'approved']);

        $this->patchJson($this->rejectUrl($id), ['reason' => 'Changed my mind'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(409);
    }

    // ─────────────────────────────── the query contract

    public function test_that_an_unknown_deal_is_404(): void
    {
        $this->patchJson($this->approveUrl((string) Str::uuid7()), [], $this->bearerFor(RoleName::Manager))
            ->assertStatus(404);
    }

    public function test_that_a_missing_reason_is_refused(): void
    {
        $id = $this->deal(overrides: ['approval_status' => 'pending']);

        $this->patchJson($this->rejectUrl($id), [], $this->bearerFor(RoleName::Manager))
            ->assertStatus(422);
    }

    public function test_that_a_blank_reason_is_refused(): void
    {
        $id = $this->deal(overrides: ['approval_status' => 'pending']);

        $this->patchJson($this->rejectUrl($id), ['reason' => '   '], $this->bearerFor(RoleName::Manager))
            ->assertStatus(422);
    }
}
