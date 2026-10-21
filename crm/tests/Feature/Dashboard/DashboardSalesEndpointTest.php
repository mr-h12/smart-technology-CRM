<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Module 14, slice 4 · 4.1 — `GET /api/v1/dashboard/sales` (`D-144`, `D-145`, `D-150`).
 *
 * - funnel: deals created in the period by current status, every §4.4 status in order
 * - conversion: of the deals created in the period, those now `won` or later
 * - win/loss: deals that moved into `won` vs `lost` in the period (their first move)
 * - average sales cycle: days from creation to `won`, over the deals won in the period
 * - average deal size (money only — `D-145`): `D-141`'s won value ÷ won chains, per currency
 * - workload ("now", per employee — `dashboard.drill_down` only, `D-150`): open deals per owner
 *
 * Rates are percentages to two places, null when there is nothing to divide by.
 */
final class DashboardSalesEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Passw0rd123';

    private const ENDPOINT = '/api/v1/dashboard/sales?from=2026-10-01&to=2026-10-31';

    private User $leader;

    private User $member;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(now()->setDate(2026, 10, 21)->setTime(9, 0));
        $this->leader = $this->user(RoleName::TeamLeader, 'leader');
        $this->member = $this->user(RoleName::IndoorSales, 'member', $this->leader);
        $this->outsider = $this->user(RoleName::IndoorSales, 'outsider');
    }

    public function test_that_the_manager_sees_the_funnel_rates_cycle_deal_size_and_workload(): void
    {
        $this->seedTheMonth();

        $data = $this->getJson(self::ENDPOINT, $this->bearer(RoleName::Manager))->assertOk()->json('data');
        self::assertIsArray($data);
        self::assertIsArray($data['funnel']);

        self::assertSame(['lead' => 1, 'contacted' => 3, 'won' => 1, 'purchasing' => 1, 'lost' => 1], array_filter(array_column($data['funnel'], 'deals', 'status')));
        self::assertSame(array_column($data['funnel'], 'status')[0], 'lead', 'Every status, in §4.4 order.');
        self::assertSame(['created' => 7, 'reached_won' => 2, 'rate_percent' => '28.57'], $data['conversion']);
        self::assertSame(['won' => 3, 'lost' => 1, 'win_rate_percent' => '75.00'], $data['win_loss']);
        self::assertSame('10.00', $data['average_sales_cycle_days']);
        self::assertSame([['currency' => 'EGP', 'value' => '800.000000']], $data['average_deal_size']);
        self::assertSame([
            ['owner_id' => $this->member->id, 'name' => $this->member->name, 'open_deals' => 4],
            ['owner_id' => $this->outsider->id, 'name' => $this->outsider->name, 'open_deals' => 2],
        ], $data['workload']);
    }

    public function test_that_the_team_leader_sees_the_team_without_money(): void
    {
        $this->seedTheMonth();

        $data = $this->getJson(self::ENDPOINT, $this->bearer($this->leader))->assertOk()->json('data');
        self::assertIsArray($data);

        self::assertSame(['created' => 5, 'reached_won' => 2, 'rate_percent' => '40.00'], $data['conversion']);
        self::assertSame(['won' => 2, 'lost' => 1, 'win_rate_percent' => '66.67'], $data['win_loss']);
        self::assertSame('9.00', $data['average_sales_cycle_days']);
        self::assertArrayNotHasKey('average_deal_size', $data);
        self::assertSame([['owner_id' => $this->member->id, 'name' => $this->member->name, 'open_deals' => 4]], $data['workload']);
    }

    public function test_that_the_ceo_sees_deal_size_but_no_per_employee_workload(): void
    {
        $this->seedTheMonth();

        $data = $this->getJson(self::ENDPOINT, $this->bearer(RoleName::Ceo))->assertOk()->json('data');
        self::assertIsArray($data);

        self::assertArrayHasKey('average_deal_size', $data);
        self::assertArrayNotHasKey('workload', $data);
    }

    public function test_that_rates_are_null_with_nothing_to_divide(): void
    {
        $data = $this->getJson(self::ENDPOINT, $this->bearer(RoleName::Manager))->assertOk()->json('data');
        self::assertIsArray($data);

        self::assertSame(['created' => 0, 'reached_won' => 0, 'rate_percent' => null], $data['conversion']);
        self::assertSame(['won' => 0, 'lost' => 0, 'win_rate_percent' => null], $data['win_loss']);
        self::assertNull($data['average_sales_cycle_days']);
    }

    public function test_that_a_sales_role_is_refused_and_a_bad_period_is_a_422(): void
    {
        $this->getJson(self::ENDPOINT, $this->bearer($this->outsider))->assertStatus(403);
        $this->getJson('/api/v1/dashboard/sales?from=2026-10-31&to=2026-10-01', $this->bearer(RoleName::Manager))->assertStatus(422);
    }

    /**
     * Created in October: member 1 lead · 2 contacted · 1 won · 1 purchasing (team);
     * outsider 1 contacted · 1 lost. September: one member deal, outside.
     * Outcomes in October: member won 2 (cycle 18), lost 1; outsider won 1 (cycle 12).
     * Workload now: member 4, outsider 2, unowned 9 (left out).
     * Money: one EGP version, two chains won, 1600.
     */
    private function seedTheMonth(): void
    {
        $this->deals('2026-10-02', $this->member->id, 'lead', 1);
        $this->deals('2026-10-02', $this->member->id, 'contacted', 2);
        $this->deals('2026-10-03', $this->member->id, 'won', 1);
        $this->deals('2026-10-03', $this->member->id, 'purchasing', 1);
        $this->deals('2026-10-04', $this->outsider->id, 'contacted', 1);
        $this->deals('2026-10-04', $this->outsider->id, 'lost', 1);
        $this->deals('2026-09-20', $this->member->id, 'contacted', 50);

        $this->outcomes('2026-10-10', $this->member->id, 'won', 2, 18);
        $this->outcomes('2026-10-11', $this->member->id, 'lost', 1, 0);
        $this->outcomes('2026-10-12', $this->outsider->id, 'won', 1, 12);
        $this->outcomes('2026-09-12', $this->outsider->id, 'won', 9, 900);

        DB::table('dashboard_live_workload')->insert([
            ['owner_id' => $this->member->id, 'open_deals' => 4],
            ['owner_id' => $this->outsider->id, 'open_deals' => 2],
        ]);

        foreach (['A' => '1100', 'B' => '500'] as $chain => $net) {
            $id = (string) Str::uuid7();
            DB::table('dashboard_sent_versions')->insert([
                'quotation_id' => $id, 'chain_id' => $id, 'version' => 1, 'sent_day' => '2026-10-05', 'owner_id' => $this->member->id,
                'currency' => 'EGP', 'status' => 'accepted', 'net_amount' => $net, 'additional_total' => '0', 'cost' => '0',
                'base_net_amount' => $net, 'base_additional_total' => '0', 'base_cost' => '0',
            ]);
        }
    }

    private function deals(string $day, ?string $ownerId, string $status, int $deals): void
    {
        DB::table('dashboard_daily_deals')->insert(['day' => $day, 'owner_id' => $ownerId, 'status' => $status, 'deals' => $deals]);
    }

    private function outcomes(string $day, ?string $ownerId, string $outcome, int $deals, int $cycleDays): void
    {
        DB::table('dashboard_daily_outcomes')->insert(['day' => $day, 'owner_id' => $ownerId, 'outcome' => $outcome, 'deals' => $deals, 'cycle_days' => $cycleDays]);
    }

    private function user(RoleName $role, string $tag, ?User $leader = null): User
    {
        $user = new User;
        $user->fill([
            'name' => 'Test '.$role->label().' '.$tag, 'email' => str_replace('_', '.', $role->value).'.'.$tag.'@example.test', 'password' => self::PASSWORD,
            'role_id' => Role::query()->where('slug', $role->value)->firstOrFail()->id, 'is_active' => true, 'is_hidden' => $role->isHidden(),
        ]);
        $user->save();
        if ($leader !== null) {
            DB::table('users')->where('id', $user->id)->update(['team_leader_id' => $leader->id]);
        }

        return $user;
    }

    /** @return array<string, string> */
    private function bearer(RoleName|User $who): array
    {
        $user = $who instanceof User ? $who : $this->user($who, 'x');
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertStatus(201)->json('data.token');
        self::assertIsString($token);

        return ['Authorization' => 'Bearer '.$token];
    }
}
