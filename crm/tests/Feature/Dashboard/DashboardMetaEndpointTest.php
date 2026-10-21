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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Module 14, slice 1 · 1.1 — the dashboard opens per role, no figures yet
 * (`D-145`, §3.13, §12.1–§12.4).
 *
 * The tabs follow from permissions, never from a role name (CLAUDE.md: the
 * matrix is not embedded in code). The owner's reading of `D-145`, 2026-10-21:
 *
 * | tab | rule |
 * |---|---|
 * | overview · sales · outdoor | `dashboard.view` |
 * | finance, and money anywhere | `quotation.view_cost_and_margin` and `dashboard.view` at `all` |
 * | procurement | `dashboard.view` at `all` and `dashboard.drill_down` |
 *
 * "Money only at `all`" is what keeps the Team Leader's dashboard to counts
 * (§12.3) although §3.5 grants them `view_cost_and_margin`.
 */
final class DashboardMetaEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Passw0rd123';

    private const ENDPOINT = '/api/v1/dashboard/meta';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        // The company's day comes from the server (`CompanyToday`), never the browser.
        $this->travelTo(now()->setDate(2026, 10, 21)->setTime(9, 0));
    }

    public function test_that_an_anonymous_caller_is_refused(): void
    {
        $this->getJson(self::ENDPOINT)->assertStatus(401);
    }

    /** @return iterable<string, array{RoleName}> */
    public static function rolesWithoutADashboard(): iterable
    {
        yield 'indoor sales' => [RoleName::IndoorSales];
        yield 'outdoor sales' => [RoleName::OutdoorSales];
        yield 'outdoor supervisor' => [RoleName::OutdoorSupervisor];
        yield 'procurement' => [RoleName::Procurement];
    }

    #[DataProvider('rolesWithoutADashboard')]
    public function test_that_a_role_without_dashboard_view_is_refused(RoleName $role): void
    {
        $this->getJson(self::ENDPOINT, $this->bearer($this->user($role)))->assertStatus(403);
    }

    public function test_that_the_manager_sees_every_tab_with_money_and_drill_down(): void
    {
        $this->getJson(self::ENDPOINT, $this->bearer($this->user(RoleName::Manager)))
            ->assertOk()
            ->assertJsonPath('data', [
                'scope' => 'all',
                'tabs' => ['overview', 'sales', 'finance', 'procurement', 'outdoor'],
                'drill_down' => true,
                'money' => true,
                'today' => '2026-10-21',
                'last_updated_at' => null,
            ]);
    }

    public function test_that_the_team_leader_sees_counts_for_the_team_only(): void
    {
        $this->getJson(self::ENDPOINT, $this->bearer($this->user(RoleName::TeamLeader)))
            ->assertOk()
            ->assertJsonPath('data', [
                'scope' => 'team',
                'tabs' => ['overview', 'sales', 'outdoor'],
                'drill_down' => true,
                'money' => false,
                'today' => '2026-10-21',
                'last_updated_at' => null,
            ]);
    }

    public function test_that_the_ceo_sees_money_without_drill_down_or_procurement(): void
    {
        $this->getJson(self::ENDPOINT, $this->bearer($this->user(RoleName::Ceo)))
            ->assertOk()
            ->assertJsonPath('data', [
                'scope' => 'all',
                'tabs' => ['overview', 'sales', 'finance', 'outdoor'],
                'drill_down' => false,
                'money' => true,
                'today' => '2026-10-21',
                'last_updated_at' => null,
            ]);
    }

    public function test_that_removing_dashboard_view_from_a_role_refuses_its_next_request(): void
    {
        $headers = $this->bearer($this->user(RoleName::Manager));
        $this->getJson(self::ENDPOINT, $headers)->assertOk();

        $this->revoke(RoleName::Manager, 'dashboard', 'view');

        $this->getJson(self::ENDPOINT, $headers)->assertStatus(403);
    }

    public function test_that_a_manager_without_cost_and_margin_loses_money_and_finance(): void
    {
        $this->revoke(RoleName::Manager, 'quotation', 'view_cost_and_margin');

        $this->getJson(self::ENDPOINT, $this->bearer($this->user(RoleName::Manager)))
            ->assertOk()
            ->assertJsonPath('data.money', false)
            ->assertJsonPath('data.tabs', ['overview', 'sales', 'procurement', 'outdoor']);
    }

    /** 2.2a: "Last updated" (`D-145`) is J-09's newest finished run, as the Overview reports it. */
    public function test_that_last_updated_at_is_j09s_newest_finished_run(): void
    {
        foreach (['2026-10-21 06:00:00', '2026-10-21 08:30:00'] as $finishedAt) {
            DB::table('dashboard_refreshes')->insert([
                'id' => (string) Str::uuid7(), 'kind' => 'hourly', 'started_at' => $finishedAt, 'finished_at' => $finishedAt,
            ]);
        }

        $this->getJson(self::ENDPOINT, $this->bearer($this->user(RoleName::Manager)))
            ->assertOk()
            ->assertJsonPath('data.last_updated_at', '2026-10-21T08:30:00+00:00');
    }

    private function revoke(RoleName $role, string $resource, string $action): void
    {
        $removed = DB::table('role_permissions')
            ->where('role_id', Role::query()->where('slug', $role->value)->firstOrFail()->id)
            ->whereIn('permission_id', DB::table('permissions')->where(['resource' => $resource, 'action' => $action])->pluck('id'))
            ->delete();
        self::assertGreaterThan(0, $removed, "The {$role->value} role held no {$resource}.{$action} to revoke.");
    }

    private function user(RoleName $role): User
    {
        $user = new User;
        $user->fill([
            'name' => 'Test '.$role->label(),
            'email' => str_replace('_', '.', $role->value).'@example.test',
            'password' => self::PASSWORD,
            'role_id' => Role::query()->where('slug', $role->value)->firstOrFail()->id,
            'is_active' => true,
            'is_hidden' => $role->isHidden(),
        ]);
        $user->save();

        return $user;
    }

    /** @return array<string, string> */
    private function bearer(User $user): array
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertStatus(201)->json('data.token');
        self::assertIsString($token);

        return ['Authorization' => 'Bearer '.$token];
    }
}
