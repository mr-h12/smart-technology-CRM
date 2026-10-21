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
 * Module 14, slice 2 · 2.2a — `GET /api/v1/dashboard/overview` (`D-144`, `D-145`).
 *
 * The Overview reads only J-09's tables (`D-145` "Freshness"), so the rows are
 * written here directly, as J-09 would have left them, and each figure is the
 * sum of those rows over the period:
 *
 * - customers, deals (total · open · closed), self-approvals: created or dated
 *   in `[from, to]`, both days included;
 * - closed = `won` or later, or `lost`; open = before `won` (`D-144`);
 * - "now" — pending approvals, returned quotations — whatever the period;
 * - `last_updated_at` — the newest run's `finished_at`, null before any run.
 *
 * Scope (`D-138`): All counts every row, unowned ones included; Team counts the
 * rows whose owner (or approver) is the leader or a member, never an unowned one.
 * No money here: that is slice 3.
 */
final class DashboardOverviewEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Passw0rd123';

    private const ENDPOINT = '/api/v1/dashboard/overview';

    private User $leader;

    private User $member;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(now()->setDate(2026, 10, 21)->setTime(9, 0));

        $this->leader = $this->user(RoleName::TeamLeader, 'leader');
        $this->member = $this->user(RoleName::IndoorSales, 'member', teamLeader: $this->leader);
        $this->outsider = $this->user(RoleName::IndoorSales, 'outsider');
    }

    // ── the figures ─────────────────────────────────────────────────────────

    public function test_that_the_manager_sees_every_row_in_the_period_unowned_ones_included(): void
    {
        $this->seedTheWeek();

        $this->getJson(self::ENDPOINT.'?from=2026-10-05&to=2026-10-07', $this->bearer($this->user(RoleName::Manager, 'manager')))
            ->assertOk()
            ->assertJsonPath('data.customers', 6)
            ->assertJsonPath('data.deals', ['total' => 9, 'open' => 5, 'closed' => 4])
            ->assertJsonPath('data.self_approvals', 3)
            ->assertJsonPath('data.now', ['pending_approvals' => 5, 'returned' => 3]);
    }

    public function test_that_the_team_leader_counts_only_the_teams_rows(): void
    {
        $this->seedTheWeek();

        $this->getJson(self::ENDPOINT.'?from=2026-10-05&to=2026-10-07', $this->bearer($this->leader))
            ->assertOk()
            ->assertJsonPath('data.customers', 3)
            ->assertJsonPath('data.deals', ['total' => 6, 'open' => 3, 'closed' => 3])
            ->assertJsonPath('data.self_approvals', 2)
            ->assertJsonPath('data.now', ['pending_approvals' => 3, 'returned' => 1]);
    }

    public function test_that_the_ceo_sees_what_the_manager_sees(): void
    {
        $this->seedTheWeek();

        $this->getJson(self::ENDPOINT.'?from=2026-10-05&to=2026-10-07', $this->bearer($this->user(RoleName::Ceo, 'ceo')))
            ->assertOk()
            ->assertJsonPath('data.customers', 6)
            ->assertJsonPath('data.deals', ['total' => 9, 'open' => 5, 'closed' => 4]);
    }

    public function test_that_both_days_of_the_period_count_and_no_day_outside_it(): void
    {
        $this->dealRow('2026-10-04', $this->member->id, 'contacted', 10);
        $this->dealRow('2026-10-05', $this->member->id, 'contacted', 1);
        $this->dealRow('2026-10-06', $this->member->id, 'contacted', 2);
        $this->dealRow('2026-10-07', $this->member->id, 'contacted', 100);

        $this->getJson(self::ENDPOINT.'?from=2026-10-05&to=2026-10-06', $this->bearer($this->user(RoleName::Manager, 'manager')))
            ->assertOk()
            ->assertJsonPath('data.deals', ['total' => 3, 'open' => 3, 'closed' => 0]);
    }

    public function test_that_the_now_figures_ignore_the_period(): void
    {
        $this->liveRow($this->member->id, 4, 2);

        $this->getJson(self::ENDPOINT.'?from=2020-01-01&to=2020-01-31', $this->bearer($this->user(RoleName::Manager, 'manager')))
            ->assertOk()
            ->assertJsonPath('data.customers', 0)
            ->assertJsonPath('data.deals', ['total' => 0, 'open' => 0, 'closed' => 0])
            ->assertJsonPath('data.now', ['pending_approvals' => 4, 'returned' => 2]);
    }

    public function test_that_last_updated_at_is_the_newest_runs_finish_and_null_before_any(): void
    {
        $manager = $this->bearer($this->user(RoleName::Manager, 'manager'));
        $this->getJson(self::ENDPOINT.'?from=2026-10-01&to=2026-10-31', $manager)
            ->assertOk()->assertJsonPath('data.last_updated_at', null);

        $this->refreshRow('2026-10-21 07:00:00');
        $this->refreshRow('2026-10-21 08:00:00');

        $this->getJson(self::ENDPOINT.'?from=2026-10-01&to=2026-10-31', $manager)
            ->assertOk()->assertJsonPath('data.last_updated_at', '2026-10-21T08:00:00+00:00');
    }

    // ── refusals ────────────────────────────────────────────────────────────

    public function test_that_an_anonymous_caller_is_refused(): void
    {
        $this->getJson(self::ENDPOINT.'?from=2026-10-01&to=2026-10-31')->assertStatus(401);
    }

    public function test_that_a_sales_role_is_refused(): void
    {
        $this->getJson(self::ENDPOINT.'?from=2026-10-01&to=2026-10-31', $this->bearer($this->outsider))->assertStatus(403);
    }

    /** @return iterable<string, array{string}> */
    public static function badPeriods(): iterable
    {
        yield 'from after to' => ['?from=2026-10-31&to=2026-10-01'];
        yield 'not a date' => ['?from=2026-13-01&to=2026-10-31'];
        yield 'missing to' => ['?from=2026-10-01'];
        yield 'missing both' => [''];
    }

    #[DataProvider('badPeriods')]
    public function test_that_a_bad_period_is_a_422(string $query): void
    {
        $this->getJson(self::ENDPOINT.$query, $this->bearer($this->user(RoleName::Manager, 'manager')))->assertStatus(422);
    }

    // ── fixtures: J-09's rows, as it would have left them ───────────────────

    /**
     * 2026-10-05 → 2026-10-07, and one day either side that must not count.
     *
     * | owner | deals (open / closed) | customers | self-approvals | now (pending / returned) |
     * |---|---|---|---|---|
     * | leader | 1 contacted · 1 won | 1 | 2 | 1 / 0 |
     * | member | 2 contacted · 1 lost · 1 delivery_complete | 2 | — | 2 / 1 |
     * | outsider | 1 lead · 1 purchasing | 2 | 1 | 2 / 2 |
     * | nobody | 1 lead | 1 | — | — |
     */
    private function seedTheWeek(): void
    {
        $this->dealRow('2026-10-05', $this->leader->id, 'contacted', 1);
        $this->dealRow('2026-10-06', $this->leader->id, 'won', 1);
        $this->dealRow('2026-10-05', $this->member->id, 'contacted', 2);
        $this->dealRow('2026-10-07', $this->member->id, 'lost', 1);
        $this->dealRow('2026-10-07', $this->member->id, 'delivery_complete', 1);
        $this->dealRow('2026-10-06', $this->outsider->id, 'lead', 1);
        $this->dealRow('2026-10-06', $this->outsider->id, 'purchasing', 1);
        $this->dealRow('2026-10-07', null, 'lead', 1);
        $this->dealRow('2026-10-04', $this->member->id, 'contacted', 50);
        $this->dealRow('2026-10-08', $this->member->id, 'won', 50);

        $this->customerRow('2026-10-05', $this->leader->id, 1);
        $this->customerRow('2026-10-06', $this->member->id, 2);
        $this->customerRow('2026-10-07', $this->outsider->id, 2);
        $this->customerRow('2026-10-07', null, 1);
        $this->customerRow('2026-10-08', $this->member->id, 50);

        $this->selfApprovalRow('2026-10-05', $this->leader->id, 2);
        $this->selfApprovalRow('2026-10-06', $this->outsider->id, 1);
        $this->selfApprovalRow('2026-10-04', $this->leader->id, 50);

        $this->liveRow($this->leader->id, 1, 0);
        $this->liveRow($this->member->id, 2, 1);
        $this->liveRow($this->outsider->id, 2, 2);
    }

    private function dealRow(string $day, ?string $ownerId, string $status, int $deals): void
    {
        DB::table('dashboard_daily_deals')->insert(['day' => $day, 'owner_id' => $ownerId, 'status' => $status, 'deals' => $deals]);
    }

    private function customerRow(string $day, ?string $ownerId, int $customers): void
    {
        DB::table('dashboard_daily_customers')->insert(['day' => $day, 'owner_id' => $ownerId, 'customers' => $customers]);
    }

    private function selfApprovalRow(string $day, string $approverId, int $approvals): void
    {
        DB::table('dashboard_daily_self_approvals')->insert(['day' => $day, 'approver_id' => $approverId, 'approvals' => $approvals]);
    }

    private function liveRow(string $ownerId, int $pending, int $returned): void
    {
        DB::table('dashboard_live_counts')->insert(['owner_id' => $ownerId, 'pending_approvals' => $pending, 'returned' => $returned]);
    }

    private function refreshRow(string $finishedAt): void
    {
        DB::table('dashboard_refreshes')->insert([
            'id' => (string) Str::uuid7(), 'kind' => 'hourly', 'started_at' => $finishedAt, 'finished_at' => $finishedAt,
        ]);
    }

    private function user(RoleName $role, string $tag, ?User $teamLeader = null): User
    {
        $user = new User;
        $user->fill([
            'name' => 'Test '.$role->label().' '.$tag,
            'email' => str_replace('_', '.', $role->value).'.'.$tag.'@example.test',
            'password' => self::PASSWORD,
            'role_id' => Role::query()->where('slug', $role->value)->firstOrFail()->id,
            'is_active' => true,
            'is_hidden' => $role->isHidden(),
        ]);
        $user->save();

        if ($teamLeader !== null) {
            DB::table('users')->where('id', $user->id)->update(['team_leader_id' => $teamLeader->id]);
        }

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
