<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Reports\Presentation\GenerateWeeklyReportsJob;
use App\Support\Queue\QueueName;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use stdClass;
use Tests\Feature\Pdf\InsertsQuotationRows;
use Tests\TestCase;

/**
 * Module 13, slice 6 · 6.1 — `D-136`: J-05 drafts the weekly report (RPT-WK)
 * early each Sunday for the Sunday → Thursday week that just ended (`D-125`),
 * one per active user holding `report.create`, filled per `D-126` and `D-134`.
 * Nothing is created before the go-live date or while it is empty (`D-127`).
 * Nobody creates a weekly report by hand.
 *
 * 2026-10-18 is a Sunday: the week that just ended is Sunday 2026-10-11 to
 * Thursday 2026-10-15. The job runs at 03:00 UTC, which is still Sunday in
 * company time.
 */
final class WeeklyReportsGenerationTest extends TestCase
{
    use InsertsQuotationRows;
    use RefreshDatabase;

    private const PASSWORD = 'Passw0rd123';

    private const ENDPOINT = '/api/v1/reports';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(now()->setDate(2026, 10, 18)->setTime(3, 0));
    }

    // ── what J-05 creates ───────────────────────────────────────────────────

    public function test_that_it_drafts_one_weekly_report_for_the_week_that_just_ended_audited_with_no_actor(): void
    {
        $this->goLive('2026-10-01');
        $author = $this->user(RoleName::IndoorSales);

        $this->runJob();

        $rows = DB::table('reports')->where('type', 'weekly')->get();
        self::assertCount(1, $rows);
        $report = $rows[0];
        self::assertSame($author->id, $report->author_id);
        self::assertSame('draft', $report->status);
        self::assertSame('2026-10-11', $report->period_start, 'D-125: the week starts on Sunday.');
        self::assertSame('2026-10-15', $report->period_end, 'D-125: the working week ends on Thursday.');
        self::assertStringStartsWith('RPT-WK-', $report->code);

        $audit = DB::table('audit_log')->where('event', 'REPORT_CREATED')->where('entity_type', 'report')->where('entity_id', $report->id)->first();
        self::assertInstanceOf(stdClass::class, $audit, 'AUD-01: drafting a report left no audit entry.');
        self::assertNull($audit->user_id, 'D-136: the system drafts it, not a person.');
    }

    public function test_that_a_second_run_creates_nothing_new(): void
    {
        $this->goLive('2026-10-01');
        $this->user(RoleName::IndoorSales);

        $this->runJob();
        $this->runJob();

        self::assertSame(1, DB::table('reports')->where('type', 'weekly')->count());
        self::assertSame(1, DB::table('audit_log')->where('event', 'REPORT_CREATED')->count());
    }

    public function test_that_nothing_is_drafted_while_the_go_live_date_is_empty(): void
    {
        $this->user(RoleName::IndoorSales);

        $this->runJob();

        self::assertSame(0, DB::table('reports')->where('type', 'weekly')->count(), 'D-127: while the go-live date is empty J-05 creates nothing.');
    }

    public function test_that_nothing_is_drafted_for_a_week_that_ends_before_the_go_live_date(): void
    {
        $this->goLive('2026-10-16');
        $this->user(RoleName::IndoorSales);

        $this->runJob();

        self::assertSame(0, DB::table('reports')->where('type', 'weekly')->count(), 'D-136: the week ended on Thursday 15 October, before the go-live date.');
    }

    public function test_that_the_catch_up_drafts_every_missed_week_since_go_live_once(): void
    {
        $this->goLive('2026-09-27');
        $this->user(RoleName::IndoorSales);

        $this->runJob();
        $this->runJob();

        $periods = DB::table('reports')->where('type', 'weekly')->orderBy('period_start')->get(['period_start', 'period_end'])
            ->map(static fn (object $r): array => [$r->period_start, $r->period_end])->all();
        self::assertSame([
            ['2026-09-27', '2026-10-01'],
            ['2026-10-04', '2026-10-08'],
            ['2026-10-11', '2026-10-15'],
        ], $periods, 'D-136: one draft per missed week since go-live, none twice.');
    }

    public function test_that_only_active_users_holding_report_create_get_a_draft(): void
    {
        $this->goLive('2026-10-01');
        $sales = $this->user(RoleName::IndoorSales);
        $this->user(RoleName::Ceo);                                         // holds no `report.create` (§3.10).
        $this->user(RoleName::IndoorSales, 'gone', active: false);
        $admin = $this->admin();
        self::assertTrue((bool) $admin->is_hidden, 'The fixture must build the Super Admin hidden, or this proves nothing.');

        $this->runJob();

        self::assertSame([$sales->id], DB::table('reports')->where('type', 'weekly')->pluck('author_id')->all());
    }

    // ── what a weekly report holds (`D-126`, `D-134`) ───────────────────────

    public function test_that_the_draft_holds_the_weeks_activity_daily_reports_purchase_orders_and_new_customers(): void
    {
        $this->goLive('2026-10-01');
        $author = $this->user(RoleName::IndoorSales);
        $other = $this->user(RoleName::IndoorSales, 'other');
        $this->seedTheWeek($author, $other);

        $this->runJob();
        $id = $this->weeklyOf($author);

        $data = $this->getJson(self::ENDPOINT.'/'.$id, $this->bearer($author))->assertStatus(200)->json('data');
        self::assertIsArray($data);

        // activity: the daily shape over Sunday → Thursday; a deal on Friday is outside.
        self::assertSame(1, $data['activity']['counts']['deals_created']);
        self::assertSame($data['activity']['sections']['deals_created'][0]['code'], DB::table('deals')->where('id', $this->inWeekDeal)->value('code'));

        // the author's own daily reports of that week, submitted or Missed, oldest first.
        self::assertEquals([
            ['code' => 'RPT-DY-2026-0001', 'period_start' => '2026-10-12', 'status' => 'submitted'],
            ['code' => 'RPT-DY-2026-0002', 'period_start' => '2026-10-13', 'status' => 'missed'],
        ], $data['daily_reports']);

        // `D-126`: its own section counting the POs received that week.
        self::assertSame(1, $data['purchase_orders_received']);

        // `D-134`: the customers the author added that week, counted and listed.
        self::assertSame(1, $data['new_customers']['count']);
        self::assertSame(['Nile New'], array_column($data['new_customers']['items'], 'name'));
    }

    /** The frozen copy carries the same four sections (`D-126`: "the frozen copy … carry the same content"). */
    public function test_that_submitting_the_weekly_draft_freezes_the_four_sections(): void
    {
        $this->goLive('2026-10-01');
        $author = $this->user(RoleName::IndoorSales);
        $other = $this->user(RoleName::IndoorSales, 'other');
        $manager = $this->user(RoleName::Manager);
        $this->seedTheWeek($author, $other);
        $this->runJob();
        $id = $this->weeklyOf($author);

        $this->patchJson(self::ENDPOINT.'/'.$id.'/submit', ['recipient_ids' => [$manager->id]], [...$this->bearer($author), 'If-Match' => $this->etag($id)])
            ->assertStatus(200);

        $raw = DB::table('report_versions')->where('report_id', $id)->value('snapshot');
        self::assertIsString($raw);
        $snapshot = json_decode($raw, true);
        self::assertIsArray($snapshot);
        self::assertSame(1, $snapshot['activity']['counts']['deals_created'] ?? null);
        self::assertCount(2, $snapshot['daily_reports'] ?? []);
        self::assertSame(1, $snapshot['purchase_orders_received'] ?? null);
        self::assertSame(1, $snapshot['new_customers']['count'] ?? null);
    }

    // ── nobody creates one by hand ──────────────────────────────────────────

    /** Guard that passes today: `CreateReportRequest` already accepts `daily` only. It must stay so (`D-136`). */
    public function test_that_a_weekly_report_cannot_be_created_by_hand(): void
    {
        $author = $this->user(RoleName::IndoorSales);

        $this->postJson(self::ENDPOINT, ['type' => 'weekly'], $this->bearer($author))
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'type');

        self::assertSame(0, DB::table('reports')->where('type', 'weekly')->count());
    }

    // ── the job and the schedule (J-04's shape) ─────────────────────────────

    public function test_that_the_job_is_scheduled_weekly_on_sunday_onto_maintenance(): void
    {
        Queue::fake();

        $scheduled = array_values(array_filter(
            app(Schedule::class)->events(),
            static fn (object $event): bool => str_contains((string) ($event->description ?? ''), GenerateWeeklyReportsJob::class),
        ));

        self::assertCount(1, $scheduled, 'Expected exactly one scheduled J-05 job.');
        self::assertMatchesRegularExpression('/^\d+ \d+ \* \* 0$/', $scheduled[0]->expression, 'D-136: early each Sunday.');

        $scheduled[0]->run($this->app);

        Queue::assertPushedOn(QueueName::Maintenance->value, GenerateWeeklyReportsJob::class);
    }

    /** `D-55` / `ST-05`: the `scheduler` service runs this once on start. */
    public function test_that_the_catch_up_command_dispatches_j05_onto_maintenance(): void
    {
        Queue::fake();

        self::assertSame(0, Artisan::call('reports:generate-weekly'));

        Queue::assertPushedOn(QueueName::Maintenance->value, GenerateWeeklyReportsJob::class);
    }

    public function test_that_the_catch_up_fails_loudly_when_j05_is_not_scheduled(): void
    {
        Queue::fake();
        $this->app->instance(Schedule::class, new Schedule);

        self::assertSame(1, Artisan::call('reports:generate-weekly'));
        self::assertStringContainsString('J-05', Artisan::output());

        Queue::assertNothingPushed();
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private string $inWeekDeal = '';

    /**
     * The author's week: one deal on Monday (and one on Friday, outside), a
     * submitted and a Missed daily report (and one the week before, and one
     * of $other's), one PO on Tuesday (and one the Friday after), one customer
     * added on Tuesday (one by $other, one the Friday after).
     */
    private function seedTheWeek(User $author, User $other): void
    {
        $this->inWeekDeal = $this->activity($author->id, 'DEAL_CREATED', '2026-10-12 08:00:00');
        $this->activity($author->id, 'DEAL_CREATED', '2026-10-16 10:00:00');

        $this->daily($author, 'RPT-DY-2026-0001', '2026-10-12', 'submitted');
        $this->daily($author, 'RPT-DY-2026-0002', '2026-10-13', 'missed');
        $this->daily($author, 'RPT-DY-2026-0003', '2026-10-08', 'submitted');
        $this->daily($other, 'RPT-DY-2026-0004', '2026-10-12', 'submitted');

        $this->purchaseOrder($author, 'PO-2026-0001', '2026-10-13');
        $this->purchaseOrder($author, 'PO-2026-0002', '2026-10-16');

        $this->customer('Nile New', $author, '2026-10-13 10:00:00');
        $this->customer('Delta By Someone Else', $other, '2026-10-13 10:00:00');
        $this->customer('Nile Too Late', $author, '2026-10-16 10:00:00');
    }

    private function runJob(): void
    {
        // As a worker runs it: with no request, so the audit names no user.
        $this->app->instance('request', Request::create('http://localhost'));
        $this->app->call([new GenerateWeeklyReportsJob, 'handle']);
    }

    private function weeklyOf(User $author): string
    {
        $id = DB::table('reports')->where('type', 'weekly')->where('author_id', $author->id)->value('id');
        self::assertIsString($id, 'J-05 drafted no weekly report for the author.');

        return $id;
    }

    private function etag(string $id): string
    {
        $token = DB::table('reports')->where('id', $id)->value('version_token');
        self::assertIsInt($token);

        return 'report:'.$id.':'.$token;
    }

    /** One audit index entry pointing at a real deal (Module 9's fixture); returns the deal's id. */
    private function activity(string $userId, string $event, string $at): string
    {
        $dealId = DB::table('quotations')->where('id', $this->insertQuotation())->value('deal_id');
        self::assertIsString($dealId);
        DB::table('audit_log')->insert([
            'id' => (string) Str::uuid7(), 'user_id' => $userId, 'event' => $event,
            'entity_type' => 'deal', 'entity_id' => $dealId, 'created_at' => $at,
        ]);

        return $dealId;
    }

    private function daily(User $author, string $code, string $day, string $status): void
    {
        DB::table('reports')->insert([
            'id' => (string) Str::uuid7(), 'code' => $code, 'type' => 'daily', 'status' => $status,
            'author_id' => $author->id, 'period_start' => $day, 'period_end' => $day,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A PO on one of the author's own deals, received on $day (its date and its row agree). */
    private function purchaseOrder(User $author, string $number, string $day): void
    {
        DB::table('purchase_orders')->insert([
            'id' => (string) Str::uuid7(), 'quotation_id' => $this->insertQuotation($author->id),
            'po_number' => $number, 'customer_po_reference' => 'REF-'.$number, 'po_date' => $day, 'created_by' => $author->id,
            'created_at' => $day.' 09:00:00', 'updated_at' => $day.' 09:00:00',
        ]);
    }

    private function customer(string $name, User $creator, string $at): void
    {
        DB::table('customers')->insert([
            'id' => (string) Str::uuid7(), 'name' => $name, 'customer_status' => 'prospect', 'sales_owner_id' => $creator->id,
            'is_archived' => false, 'is_incomplete' => false, 'created_by' => $creator->id,
            'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    private function goLive(string $date): void
    {
        $this->patchJson('/api/v1/settings', ['settings' => ['reports.go_live_date' => $date]], $this->bearer($this->admin()))
            ->assertStatus(200);
    }

    /** The one hidden Super Admin of the test, built the first time anything asks. */
    private function admin(): User
    {
        return User::query()->where('email', 'super.admin@example.test')->first() ?? $this->user(RoleName::SuperAdmin);
    }

    private function user(RoleName $role, string $tag = '', bool $active = true): User
    {
        $user = new User;
        $user->fill([
            'name' => 'Test '.$role->label().($tag !== '' ? ' '.$tag : ''),
            'email' => str_replace('_', '.', $role->value).($tag !== '' ? '.'.$tag : '').'@example.test',
            'password' => self::PASSWORD,
            'role_id' => Role::query()->where('slug', $role->value)->firstOrFail()->id,
            'is_active' => $active,
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

        return ['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => Uuid::uuid4()->toString()];
    }
}
