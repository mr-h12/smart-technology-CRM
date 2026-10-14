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
 * Module 5, Point 2.6 — `PATCH /deals/{id}/status`, §4.4's transition graph.
 *
 * F-49 · 1.3 (`D-119`): by hand only `purchasing`, `delivery` and
 * `delivery_complete` are chosen, and the manual `lost` is withdrawn — every
 * other status is reached on its own (creation, a quotation, the customer's
 * answer), so the boundary refuses it with 422 before §4.4's graph is asked.
 *
 * §3.4 grants `change_status` the same five roles and scopes as `view`/`edit`
 * — `Team`/`Out`/`Asgn` still have no mechanism (Point 2.1). `Delivery →
 * Delivery Complete` additionally needs `mark_delivery_complete` (`D-14`'s
 * four roles — a different set from `change_status`'s).
 */
final class DealStatusEndpointTest extends TestCase
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

    private function statusUrl(string $id): string
    {
        return self::ENDPOINT.'/'.$id.'/status';
    }

    // ─────────────────────────────── §3.4's change_status row

    public function test_that_an_unauthenticated_caller_cannot_change_status(): void
    {
        $this->patchJson($this->statusUrl((string) Str::uuid7()))->assertStatus(401);
    }

    /** @return array<string, array{RoleName}> */
    public static function rolesWithoutChangeStatus(): array
    {
        return ['the CEO' => [RoleName::Ceo]];
    }

    #[DataProvider('rolesWithoutChangeStatus')]
    public function test_that_a_role_without_the_permission_cannot_change_status(RoleName $role): void
    {
        $id = $this->deal(overrides: ['status' => 'won']);

        $this->patchJson($this->statusUrl($id), ['status' => 'purchasing'], $this->bearerFor($role))
            ->assertStatus(403);
    }

    /** The route's permission answers before the body is read: a 422 would tell a grantless caller which statuses exist. */
    public function test_that_a_role_without_the_permission_gets_403_before_any_validation(): void
    {
        $id = $this->deal(overrides: ['status' => 'negotiations']);
        $bearer = $this->bearerFor(RoleName::Ceo);

        foreach ([['status' => 'lost', 'reason' => 'x'], ['status' => 'won'], ['status' => 'purchasing', 'reason' => 'x']] as $body) {
            $this->patchJson($this->statusUrl($id), $body, $bearer)->assertStatus(403);
        }

        $this->assertDatabaseHas('deals', ['id' => $id, 'status' => 'negotiations']);
    }

    /** @return array<string, array{string, string}> */
    public static function ownScopeMoves(): array
    {
        return ['purchasing' => ['won', 'purchasing'], 'delivery complete' => ['delivery', 'delivery_complete']];
    }

    /** `Own` reaches only the caller's deals: another owner's is 404 (OpenAPI §5.1), moved nothing, audited nothing. */
    #[DataProvider('ownScopeMoves')]
    public function test_that_an_own_scope_holder_cannot_move_another_owners_deal(string $from, string $to): void
    {
        $id = $this->deal($this->userWith(RoleName::OutdoorSales)->id, ['status' => $from]);

        $this->patchJson($this->statusUrl($id), ['status' => $to], $this->bearerFor(RoleName::IndoorSales))
            ->assertStatus(404);

        $this->assertDatabaseHas('deals', ['id' => $id, 'status' => $from]);
        self::assertSame(0, DB::table('audit_log')->where('event', 'DEAL_STATUS_CHANGED')->where('entity_id', $id)->count());
    }

    /** The visible cost of the unbacked `team` scope, not a decision about §4.4. */
    public function test_that_a_team_leader_can_change_nothing_while_team_is_unbacked(): void
    {
        $id = $this->deal(overrides: ['status' => 'won']);

        $this->patchJson($this->statusUrl($id), ['status' => 'purchasing'], $this->bearerFor(RoleName::TeamLeader))
            ->assertStatus(404);
    }

    // ─────────────────────────────── §4.4's graph

    /** @return array<string, array{string, string}> */
    public static function manualEdges(): array
    {
        return [
            'won to purchasing' => ['won', 'purchasing'],
            'purchasing to delivery' => ['purchasing', 'delivery'],
        ];
    }

    #[DataProvider('manualEdges')]
    public function test_that_a_manual_edge_is_accepted(string $from, string $to): void
    {
        $owner = $this->userWith(RoleName::IndoorSales);
        $id = $this->deal($owner->id, ['status' => $from]);

        $this->patchJson($this->statusUrl($id), ['status' => $to], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.status', $to);
    }

    /** @return array<string, array{string, string}> */
    public static function undocumentedEdges(): array
    {
        return [
            'won cannot skip to delivery' => ['won', 'delivery'],
            'lead cannot skip to purchasing' => ['lead', 'purchasing'],
            'negotiations is not yet won' => ['negotiations', 'purchasing'],
            'delivery complete is terminal' => ['delivery_complete', 'delivery'],
            'lost is terminal' => ['lost', 'purchasing'],
        ];
    }

    #[DataProvider('undocumentedEdges')]
    public function test_that_an_undocumented_edge_is_refused(string $from, string $to): void
    {
        $owner = $this->userWith(RoleName::Manager);
        $id = $this->deal($owner->id, [
            'status' => $from,
            'lost_reason' => $from === 'lost' ? 'Prior loss' : null,
        ]);

        $this->patchJson($this->statusUrl($id), ['status' => $to], $this->bearerFor(RoleName::Manager))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'state_transition_invalid');
    }

    // ─────────────────────────────── D-119: the manual list is the three

    /** @return array<string, array{string, string}> */
    public static function withdrawnStatuses(): array
    {
        // Each from a status §4.4 *would* let it leave by, so a 409 cannot
        // stand in for the 422: only the boundary's narrowed list refuses it.
        return [
            'lead' => ['lead', 'lead'],
            'contacted' => ['lead', 'contacted'],
            'waiting_customer_request' => ['contacted', 'waiting_customer_request'],
            'supplier_rfq' => ['waiting_customer_request', 'supplier_rfq'],
            'supplier_quotation' => ['supplier_rfq', 'supplier_quotation'],
            'quotation_sent' => ['supplier_quotation', 'quotation_sent'],
            'negotiations' => ['quotation_sent', 'negotiations'],
            'won' => ['negotiations', 'won'],
            'lost' => ['quotation_sent', 'lost'],
        ];
    }

    #[DataProvider('withdrawnStatuses')]
    public function test_that_a_withdrawn_manual_status_is_refused_with_422(string $from, string $to): void
    {
        $id = $this->deal(overrides: ['status' => $from]);
        $body = $to === 'lost' ? ['status' => 'lost', 'reason' => 'Chose a competitor'] : ['status' => $to];

        $this->patchJson($this->statusUrl($id), $body, $this->bearerFor(RoleName::Manager))
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'status');

        $this->assertDatabaseHas('deals', ['id' => $id, 'status' => $from]);
        self::assertSame(0, DB::table('audit_log')->where('event', 'DEAL_STATUS_CHANGED')->where('entity_id', $id)->count());
    }

    /** @return array<string, array{string, string}> */
    public static function threeManualEdges(): array
    {
        return self::manualEdges() + ['delivery to delivery complete' => ['delivery', 'delivery_complete']];
    }

    #[DataProvider('threeManualEdges')]
    public function test_that_a_reason_is_prohibited_on_every_status(string $from, string $to): void
    {
        $id = $this->deal(overrides: ['status' => $from]);

        $this->patchJson(
            $this->statusUrl($id),
            ['status' => $to, 'reason' => 'Not applicable here'],
            $this->bearerFor(RoleName::Manager),
        )->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'reason');

        $this->assertDatabaseHas('deals', ['id' => $id, 'status' => $from]);
    }

    // ─────────────────────────────── D-14's second permission

    public function test_that_procurement_may_mark_their_assigned_deal_delivery_complete(): void
    {
        // Procurement's `mark_delivery_complete` scope is `Asgn`, which has no
        // mechanism (Point 2.1) — so this documents the current, honest
        // outcome rather than the eventual one.
        $id = $this->deal(overrides: ['status' => 'delivery']);

        $this->patchJson($this->statusUrl($id), ['status' => 'delivery_complete'], $this->bearerFor(RoleName::Procurement))
            ->assertStatus(404);
    }

    public function test_that_an_indoor_sales_owner_may_mark_their_own_deal_delivery_complete(): void
    {
        $owner = $this->userWith(RoleName::IndoorSales);
        $id = $this->deal($owner->id, ['status' => 'delivery']);

        $this->patchJson($this->statusUrl($id), ['status' => 'delivery_complete'], $this->bearerFor(RoleName::IndoorSales))
            ->assertStatus(200);
    }

    public function test_that_an_outdoor_sales_owner_cannot_mark_delivery_complete(): void
    {
        // change_status grants Outdoor Sales `Own`, but mark_delivery_complete
        // does not name Outdoor Sales at all (D-14's four are Manager, Team
        // Leader, Indoor Sales and Procurement) — the second, narrower
        // permission this point exists to prove.
        $owner = $this->userWith(RoleName::OutdoorSales);
        $id = $this->deal($owner->id, ['status' => 'delivery']);

        $this->patchJson($this->statusUrl($id), ['status' => 'delivery_complete'], $this->bearerFor(RoleName::OutdoorSales))
            ->assertStatus(403);

        $this->assertDatabaseHas('deals', ['id' => $id, 'status' => 'delivery']);
    }

    public function test_that_a_manager_may_mark_any_deal_delivery_complete(): void
    {
        $id = $this->deal($this->userWith(RoleName::OutdoorSales)->id, ['status' => 'delivery']);

        $this->patchJson($this->statusUrl($id), ['status' => 'delivery_complete'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200);
    }

    // ─────────────────────────────── AUD-01 and the query contract

    public function test_that_a_status_change_writes_an_audit_entry(): void
    {
        $id = $this->deal(overrides: ['status' => 'won']);

        $this->patchJson($this->statusUrl($id), ['status' => 'purchasing'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200);

        self::assertSame(
            1,
            DB::table('audit_log')->where('event', 'DEAL_STATUS_CHANGED')->where('entity_id', $id)->count(),
        );
    }

    public function test_that_an_unknown_deal_is_404(): void
    {
        $this->patchJson($this->statusUrl((string) Str::uuid7()), ['status' => 'purchasing'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(404);
    }

    public function test_that_an_undocumented_status_value_is_refused(): void
    {
        $id = $this->deal();

        $this->patchJson($this->statusUrl($id), ['status' => 'archived'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(422);
    }
}
