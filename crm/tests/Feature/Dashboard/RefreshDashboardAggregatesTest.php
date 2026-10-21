<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Dashboard\Presentation\RefreshDashboardAggregatesJob;
use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Support\Queue\QueueName;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Pdf\InsertsQuotationRows;
use Tests\TestCase;

/**
 * Module 14, slice 2 · 2.1a — J-09 `refresh_dashboard_aggregates` (§15, `D-144`,
 * `D-145`, `D-146`; split by mohanadmm, 2026-10-21).
 *
 * The dashboard reads only these tables (`D-145` "Freshness"), so each row is
 * proved against the records it summarises:
 *
 * - `dashboard_daily_deals` — deals by creation day, current owner, current status
 * - `dashboard_daily_customers` — customers by creation day and current sales owner
 * - `dashboard_daily_self_approvals` — `SELF_APPROVAL` audit entries by day and approver
 *   (no `approved_at` column exists; the audit entry is the record of when)
 * - `dashboard_live_counts` — "now": pending and returned quotations by owner
 * - `dashboard_refreshes` — one row per run, the "Last updated" time
 *
 * Hourly, J-09 rebuilds only the days whose records changed since its last run
 * began; nightly, it rebuilds everything. No catch-up (§15: "❌ recomputes").
 * The company's zone is unset here, so a day is UTC's (`CompanyToday`).
 */
final class RefreshDashboardAggregatesTest extends TestCase
{
    use InsertsQuotationRows;
    use RefreshDatabase;

    private const PASSWORD = 'Passw0rd123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(now()->setDate(2026, 10, 21)->setTime(9, 0));
    }

    // ── what the tables hold ────────────────────────────────────────────────

    public function test_that_deals_are_counted_by_creation_day_current_owner_and_current_status(): void
    {
        $sara = $this->user(RoleName::IndoorSales, 'sara');
        $omar = $this->user(RoleName::IndoorSales, 'omar');
        $this->deal('contacted', '2026-10-05 10:00:00', $sara->id);
        $this->deal('contacted', '2026-10-05 15:00:00', $sara->id);
        $this->deal('won', '2026-10-05 16:00:00', $sara->id);
        $this->deal('lost', '2026-10-07 09:00:00', $omar->id);
        $this->deal('lead', '2026-10-07 09:30:00', null);

        $this->refresh(full: true);

        self::assertSame([
            ['2026-10-05', $sara->id, 'contacted', 2],
            ['2026-10-05', $sara->id, 'won', 1],
            ['2026-10-07', null, 'lead', 1],
            ['2026-10-07', $omar->id, 'lost', 1],
        ], $this->rows('dashboard_daily_deals', ['day', 'owner_id', 'status', 'deals']));
    }

    public function test_that_customers_are_counted_by_creation_day_and_current_sales_owner(): void
    {
        $sara = $this->user(RoleName::IndoorSales, 'sara');
        $this->customer('2026-10-03 08:00:00', $sara->id);
        $this->customer('2026-10-03 18:00:00', $sara->id);
        $this->customer('2026-10-04 08:00:00', null);

        $this->refresh(full: true);

        self::assertSame([
            ['2026-10-03', $sara->id, 2],
            ['2026-10-04', null, 1],
        ], $this->rows('dashboard_daily_customers', ['day', 'owner_id', 'customers']));
    }

    public function test_that_self_approvals_are_counted_by_their_audit_entrys_day_and_approver(): void
    {
        $leader = $this->user(RoleName::TeamLeader, 'leader');
        $this->selfApproval($leader->id, '2026-10-06 11:00:00');
        $this->selfApproval($leader->id, '2026-10-06 12:00:00');
        $this->selfApproval($leader->id, '2026-10-08 12:00:00');
        // Another event on the same quotation is not a self-approval.
        $this->auditEntry('QUOTATION_APPROVED', $leader->id, '2026-10-06 13:00:00');

        $this->refresh(full: true);

        self::assertSame([
            ['2026-10-06', $leader->id, 2],
            ['2026-10-08', $leader->id, 1],
        ], $this->rows('dashboard_daily_self_approvals', ['day', 'approver_id', 'approvals']));
    }

    public function test_that_pending_and_returned_quotations_are_counted_now_by_owner(): void
    {
        $sara = $this->user(RoleName::IndoorSales, 'sara');
        $omar = $this->user(RoleName::IndoorSales, 'omar');
        $this->quotationOf($sara->id, 'pending');
        $this->quotationOf($sara->id, 'pending');
        $this->quotationOf($sara->id, 'draft', returned: true);
        $this->quotationOf($omar->id, 'draft', returned: true);
        // A plain draft and a sent quotation are neither.
        $this->quotationOf($omar->id, 'draft');
        $this->quotationOf($omar->id, 'sent');

        $this->refresh(full: false);

        self::assertSame([
            [$omar->id, 0, 1],
            [$sara->id, 2, 1],
        ], $this->rows('dashboard_live_counts', ['owner_id', 'pending_approvals', 'returned'], orderBy: 'owner_id'));
    }

    public function test_that_nothing_before_the_go_live_date_is_counted(): void
    {
        $sara = $this->user(RoleName::IndoorSales, 'sara');
        $this->goLive('2026-10-10');
        $this->deal('contacted', '2026-10-09 23:59:00', $sara->id);
        $this->deal('contacted', '2026-10-10 00:00:00', $sara->id);
        $this->customer('2026-10-09 12:00:00', $sara->id);

        $this->refresh(full: true);

        self::assertSame([['2026-10-10', $sara->id, 'contacted', 1]], $this->rows('dashboard_daily_deals', ['day', 'owner_id', 'status', 'deals']));
        self::assertSame([], $this->rows('dashboard_daily_customers', ['day', 'owner_id', 'customers']));
    }

    // ── how the job behaves ─────────────────────────────────────────────────

    public function test_that_running_twice_changes_nothing(): void
    {
        $sara = $this->user(RoleName::IndoorSales, 'sara');
        $this->deal('contacted', '2026-10-05 10:00:00', $sara->id);
        $this->customer('2026-10-05 10:00:00', $sara->id);

        $this->refresh(full: true);
        $first = [$this->rows('dashboard_daily_deals', ['day', 'owner_id', 'status', 'deals']), $this->rows('dashboard_daily_customers', ['day', 'owner_id', 'customers'])];
        $this->refresh(full: true);
        $this->refresh(full: false);

        self::assertSame($first, [$this->rows('dashboard_daily_deals', ['day', 'owner_id', 'status', 'deals']), $this->rows('dashboard_daily_customers', ['day', 'owner_id', 'customers'])]);
    }

    public function test_that_the_hourly_run_rebuilds_only_the_days_whose_records_changed(): void
    {
        $sara = $this->user(RoleName::IndoorSales, 'sara');
        $september = $this->deal('contacted', '2026-09-15 10:00:00', $sara->id);
        $this->deal('contacted', '2026-10-05 10:00:00', $sara->id);
        $this->refresh(full: true);

        // A day J-09 has no reason to touch, damaged on purpose: the hourly
        // run must leave it alone, which is what proves it did not rebuild everything.
        DB::table('dashboard_daily_deals')->where('day', '2026-10-05')->update(['deals' => 99]);

        // An old deal moves on today: its creation day (September) is the day to rebuild.
        $this->travel(1)->hours();
        DB::table('deals')->where('id', $september)->update(['status' => 'won', 'updated_at' => now()]);
        $this->refresh(full: false);

        self::assertSame([
            ['2026-09-15', $sara->id, 'won', 1],
            ['2026-10-05', $sara->id, 'contacted', 99],
        ], $this->rows('dashboard_daily_deals', ['day', 'owner_id', 'status', 'deals']));
    }

    public function test_that_the_nightly_run_rebuilds_every_day_and_repairs_damage(): void
    {
        $sara = $this->user(RoleName::IndoorSales, 'sara');
        $this->deal('contacted', '2026-10-05 10:00:00', $sara->id);
        $this->refresh(full: true);
        DB::table('dashboard_daily_deals')->update(['deals' => 99]);
        DB::table('dashboard_daily_deals')->insert(['day' => '2026-10-01', 'owner_id' => null, 'status' => 'lead', 'deals' => 7]);

        $this->refresh(full: true);

        self::assertSame([['2026-10-05', $sara->id, 'contacted', 1]], $this->rows('dashboard_daily_deals', ['day', 'owner_id', 'status', 'deals']));
    }

    public function test_that_each_run_records_when_it_finished(): void
    {
        $this->refresh(full: false);
        $this->travel(1)->hours();
        $this->refresh(full: true);

        self::assertSame([
            ['hourly', '2026-10-21 09:00:00'],
            ['nightly', '2026-10-21 10:00:00'],
        ], array_map(
            static fn (object $row): array => [$row->kind, is_string($row->finished_at) ? substr($row->finished_at, 0, 19) : null],
            DB::table('dashboard_refreshes')->orderBy('finished_at')->get(['kind', 'finished_at'])->all(),
        ));
    }

    // ── the schedule ────────────────────────────────────────────────────────

    public function test_that_j09_is_scheduled_hourly_and_nightly_on_maintenance(): void
    {
        $events = array_values(array_filter(
            app(Schedule::class)->events(),
            static fn (Event $event): bool => $event->description === RefreshDashboardAggregatesJob::class,
        ));

        self::assertCount(2, $events, 'J-09 runs twice over: hourly, and in full nightly (§15).');
        $expressions = array_map(static fn (Event $event): string => $event->expression, $events);
        self::assertContains('0 * * * *', $expressions);
        self::assertCount(1, preg_grep('/^\d+ \d+ \* \* \*$/', $expressions) ?: [], 'One run is daily.');

        Queue::fake();
        foreach ($events as $event) {
            $event->run($this->app);
        }
        Queue::assertPushedOn(QueueName::Maintenance->value, RefreshDashboardAggregatesJob::class);
        Queue::assertPushed(RefreshDashboardAggregatesJob::class, 2);
    }

    public function test_that_the_startup_catch_up_leaves_j09_alone(): void
    {
        Queue::fake();

        Artisan::call('schedule:catch-up');

        Queue::assertNotPushed(RefreshDashboardAggregatesJob::class);
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function refresh(bool $full): void
    {
        // As a worker runs it: with no request, so the audit names no user.
        $this->app->instance('request', Request::create('http://localhost'));
        $this->app->call([new RefreshDashboardAggregatesJob(full: $full), 'handle']);
    }

    /**
     * @param  list<string>  $columns
     * @return list<list<mixed>>
     */
    private function rows(string $table, array $columns, ?string $orderBy = null): array
    {
        $query = DB::table($table);
        foreach ($orderBy === null ? $columns : [$orderBy] as $column) {
            $query->orderBy($column);
        }

        return array_values(array_map(
            static fn (object $row): array => array_map(
                static fn (mixed $value): mixed => is_numeric($value) && ! str_contains((string) $value, '-') ? (int) $value : $value,
                array_values((array) $row),
            ),
            $query->get($columns)->all(),
        ));
    }

    private function deal(string $status, string $createdAt, ?string $ownerId): string
    {
        $customer = (string) Str::uuid7();
        DB::table('customers')->insert(['id' => $customer, 'name' => 'Nile Trading', 'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-01 00:00:00']);
        $id = (string) Str::uuid7();
        DB::table('deals')->insert([
            'id' => $id, 'code' => 'DL-2026-'.substr(str_replace('-', '', $id), -6), 'customer_id' => $customer,
            'owner_id' => $ownerId, 'status' => $status, 'lost_reason' => $status === 'lost' ? 'Price.' : null,
            'last_activity_at' => $createdAt, 'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);

        return $id;
    }

    private function customer(string $createdAt, ?string $ownerId): void
    {
        DB::table('customers')->insert([
            'id' => (string) Str::uuid7(), 'name' => 'Delta Medical', 'sales_owner_id' => $ownerId,
            'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);
    }

    private function quotationOf(string $ownerId, string $status, bool $returned = false): void
    {
        $id = $this->insertQuotation($ownerId);
        DB::table('quotations')->where('id', $id)->update([
            'status' => $status,
            'returned_at' => $returned ? now() : null,
            'return_note' => $returned ? 'Check the margin.' : null,
        ]);
        // `insertQuotation` makes its customer and deal now; keep them out of the day counts.
        $dealId = DB::table('quotations')->where('id', $id)->value('deal_id');
        DB::table('deals')->where('id', $dealId)->update(['created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-01 00:00:00']);
    }

    private function selfApproval(string $approverId, string $at): void
    {
        $this->auditEntry('SELF_APPROVAL', $approverId, $at);
    }

    private function auditEntry(string $event, string $actorId, string $at): void
    {
        DB::table('audit_log')->insert([
            'id' => (string) Str::uuid7(), 'user_id' => $actorId, 'impersonated_user_id' => null,
            'event' => $event, 'entity_type' => 'quotation', 'entity_id' => (string) Str::uuid7(),
            'old_values' => null, 'new_values' => null, 'ip_address' => null, 'user_agent' => null,
            'request_id' => null, 'correlation_id' => null, 'created_at' => $at,
        ]);
    }

    private function goLive(string $date): void
    {
        $this->patchJson('/api/v1/settings', ['settings' => ['reports.go_live_date' => $date]], $this->bearer($this->user(RoleName::SuperAdmin, 'admin')))
            ->assertStatus(200);
    }

    private function user(RoleName $role, string $tag): User
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
