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
        $id = $this->deal();

        $this->patchJson($this->statusUrl($id), ['status' => 'contacted'], $this->bearerFor($role))
            ->assertStatus(403);
    }

    /** The visible cost of the unbacked `team` scope, not a decision about §4.4. */
    public function test_that_a_team_leader_can_change_nothing_while_team_is_unbacked(): void
    {
        $id = $this->deal();

        $this->patchJson($this->statusUrl($id), ['status' => 'contacted'], $this->bearerFor(RoleName::TeamLeader))
            ->assertStatus(404);
    }

    // ─────────────────────────────── §4.4's graph

    public function test_that_a_documented_edge_is_accepted(): void
    {
        $owner = $this->userWith(RoleName::IndoorSales);
        $id = $this->deal($owner->id, ['status' => 'lead']);

        $this->patchJson($this->statusUrl($id), ['status' => 'contacted'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'contacted');
    }

    /** @return array<string, array{string, string}> */
    public static function undocumentedEdges(): array
    {
        return [
            'lead cannot skip to won' => ['lead', 'won'],
            'lead cannot skip straight to negotiations' => ['lead', 'negotiations'],
            'won cannot return to lead' => ['won', 'lead'],
            'delivery complete is terminal' => ['delivery_complete', 'delivery'],
            'lost is terminal' => ['lost', 'lead'],
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

    public function test_that_quotation_sent_may_go_to_won_negotiations_or_lost(): void
    {
        $bearer = $this->bearerFor(RoleName::Manager);

        foreach (['won', 'negotiations'] as $target) {
            $id = $this->deal(overrides: ['status' => 'quotation_sent']);

            $this->patchJson($this->statusUrl($id), ['status' => $target], $bearer)
                ->assertStatus(200);
        }

        $id = $this->deal(overrides: ['status' => 'quotation_sent']);

        $this->patchJson($this->statusUrl($id), ['status' => 'lost', 'reason' => 'Chose a competitor'], $bearer)
            ->assertStatus(200)
            ->assertJsonPath('data.lost_reason', 'Chose a competitor');
    }

    // ─────────────────────────────── §4.4's mandatory Lost reason

    public function test_that_moving_to_lost_without_a_reason_is_refused(): void
    {
        $id = $this->deal(overrides: ['status' => 'negotiations']);

        $this->patchJson($this->statusUrl($id), ['status' => 'lost'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(422);
    }

    public function test_that_a_reason_on_a_non_lost_transition_is_refused(): void
    {
        $id = $this->deal(overrides: ['status' => 'lead']);

        $this->patchJson(
            $this->statusUrl($id),
            ['status' => 'contacted', 'reason' => 'Not applicable here'],
            $this->bearerFor(RoleName::Manager),
        )->assertStatus(422);
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
        $id = $this->deal(overrides: ['status' => 'lead']);

        $this->patchJson($this->statusUrl($id), ['status' => 'contacted'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200);

        self::assertSame(
            1,
            DB::table('audit_log')->where('event', 'DEAL_STATUS_CHANGED')->where('entity_id', $id)->count(),
        );
    }

    public function test_that_an_unknown_deal_is_404(): void
    {
        $this->patchJson($this->statusUrl((string) Str::uuid7()), ['status' => 'contacted'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(404);
    }

    public function test_that_an_undocumented_status_value_is_refused(): void
    {
        $id = $this->deal();

        $this->patchJson($this->statusUrl($id), ['status' => 'archived'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(422);
    }
}
