<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Dashboard\Domain\DealProgress;
use App\Modules\Dashboard\Presentation\RefreshDashboardAggregatesJob;
use App\Modules\Deals\Domain\Access\DealStatusTransition;
use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Module 14, slice 4 · 4.1 — J-09's pipeline figures (`D-144`, `D-151`).
 *
 * - `dashboard_daily_outcomes`: deals by the company day they first moved into
 *   `won` or `lost` (their `DEAL_STATUS_CHANGED` audit entries), their current
 *   owner, and — for won — the days from creation to that move, summed.
 * - `dashboard_live_workload`: "now", open deals (before `won`, not `lost`) per owner.
 *
 * Both are rebuilt whole on every run.
 */
final class RefreshDashboardSalesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(now()->setDate(2026, 10, 21)->setTime(9, 0));
    }

    public function test_that_won_and_lost_are_dated_by_their_first_status_change_with_the_won_cycle_in_days(): void
    {
        $sara = $this->user('sara');
        $won = $this->deal('purchasing', '2026-10-01 08:00:00', $sara->id);
        $this->moved($won, 'negotiations', 'won', '2026-10-11 15:00:00');
        $this->moved($won, 'won', 'purchasing', '2026-10-12 09:00:00');
        $lost = $this->deal('lost', '2026-10-02 08:00:00', $sara->id);
        $this->moved($lost, 'quotation_sent', 'lost', '2026-10-04 12:00:00');
        $other = $this->deal('won', '2026-10-03 08:00:00', $sara->id);
        $this->moved($other, 'negotiations', 'won', '2026-10-11 10:00:00');
        // Still open: no outcome row.
        $this->deal('contacted', '2026-10-05 08:00:00', $sara->id);

        $this->refresh();

        self::assertSame([
            ['2026-10-04', $sara->id, 'lost', 1, 0],
            ['2026-10-11', $sara->id, 'won', 2, 18],
        ], $this->rows('dashboard_daily_outcomes', ['day', 'owner_id', 'outcome', 'deals', 'cycle_days']));
    }

    public function test_that_open_deals_are_counted_now_per_owner(): void
    {
        $sara = $this->user('sara');
        $omar = $this->user('omar');
        $this->deal('lead', '2026-10-01 08:00:00', $sara->id);
        $this->deal('negotiations', '2026-10-01 08:00:00', $sara->id);
        $this->deal('won', '2026-10-01 08:00:00', $sara->id);
        $this->deal('lost', '2026-10-01 08:00:00', $sara->id);
        $this->deal('delivery', '2026-10-01 08:00:00', $omar->id);
        $this->deal('contacted', '2026-10-01 08:00:00', null);

        $this->refresh();

        self::assertSame([[$sara->id, 2]], $this->rows('dashboard_live_workload', ['owner_id', 'open_deals']));
    }

    public function test_that_the_dashboards_pipeline_is_the_deals_modules_own_order(): void
    {
        self::assertSame(DealStatusTransition::statuses(), DealProgress::PIPELINE,
            'The funnel draws §4.4 in order; Dashboard keeps its own copy because it may not read Deals\' domain.');
    }

    private function refresh(): void
    {
        $this->app->instance('request', Request::create('http://localhost'));
        $this->app->call([new RefreshDashboardAggregatesJob(full: false), 'handle']);
    }

    /**
     * @param  list<string>  $columns
     * @return list<list<mixed>>
     */
    private function rows(string $table, array $columns): array
    {
        $query = DB::table($table);
        foreach ($columns as $column) {
            $query->orderBy($column);
        }

        return array_values(array_map(
            static fn (object $row): array => array_map(
                static fn (mixed $v): mixed => is_numeric($v) && ! str_contains((string) $v, '-') ? (int) $v : $v,
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

    private function moved(string $dealId, string $from, string $to, string $at): void
    {
        DB::table('audit_log')->insert([
            'id' => (string) Str::uuid7(), 'user_id' => null, 'impersonated_user_id' => null,
            'event' => 'DEAL_STATUS_CHANGED', 'entity_type' => 'deal', 'entity_id' => $dealId,
            'old_values' => json_encode(['status' => $from], JSON_THROW_ON_ERROR), 'new_values' => json_encode(['status' => $to], JSON_THROW_ON_ERROR),
            'ip_address' => null, 'user_agent' => null, 'request_id' => null, 'correlation_id' => null, 'created_at' => $at,
        ]);
    }

    private function user(string $tag): User
    {
        $user = new User;
        $user->fill([
            'name' => 'Test '.$tag, 'email' => $tag.'@example.test', 'password' => 'Passw0rd123',
            'role_id' => Role::query()->where('slug', RoleName::IndoorSales->value)->firstOrFail()->id,
            'is_active' => true, 'is_hidden' => false,
        ]);
        $user->save();

        return $user;
    }
}
