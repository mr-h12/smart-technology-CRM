<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Reports\Domain\Contracts\ReportSubmitted;
use App\Modules\Reports\Presentation\SubmitUnreviewedWeeklyReportsJob;
use App\Support\Queue\QueueName;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use stdClass;
use Tests\TestCase;

/**
 * Module 13, slice 6 · 6.1 — `D-136`: J-06 submits a weekly draft still
 * unsubmitted after the review window (`limits.weekly_review_window_hours`,
 * 24 hours while unset, `D-127`) unchanged to the default recipient (`D-129`),
 * flagged `not_reviewed`. With no valid default recipient the report stays a
 * draft and J-06 tries again on its next run. Only weekly reports are touched.
 *
 * The jobs run on Monday 2026-10-19 at 09:00 UTC; a draft's age is counted
 * from its `created_at`.
 */
final class UnreviewedWeeklyReportsTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Passw0rd123';

    private const ENDPOINT = '/api/v1/reports';

    private const WINDOW = 'limits.weekly_review_window_hours';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(now()->setDate(2026, 10, 19)->setTime(9, 0));
    }

    // ── what J-06 submits ───────────────────────────────────────────────────

    public function test_that_a_weekly_draft_older_than_the_default_window_is_submitted_unreviewed_to_the_default_recipient(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $this->defaultRecipient($manager);
        $id = $this->weekly($author, hoursOld: 25);
        Event::fake([ReportSubmitted::class]);

        $this->runJob();

        self::assertSame('submitted', $this->statusOf($id));
        self::assertTrue((bool) DB::table('reports')->where('id', $id)->value('not_reviewed'), 'D-136: flagged Not reviewed.');
        self::assertSame(1, DB::table('report_versions')->where('report_id', $id)->count(), '§11.3: a frozen copy.');
        self::assertSame([$manager->id], DB::table('report_recipients')->where('report_id', $id)->pluck('user_id')->all());
        self::assertNotNull(DB::table('reports')->where('id', $id)->value('submitted_at'));
        self::assertSame(1, DB::table('audit_log')->where('event', 'REPORT_SUBMITTED')->where('entity_id', $id)->count(), 'AUD-01');
        $audit = DB::table('audit_log')->where('event', 'REPORT_SUBMITTED')->where('entity_id', $id)->first();
        self::assertInstanceOf(stdClass::class, $audit);
        self::assertNull($audit->user_id, 'D-136: the system submits it, not a person.');
        self::assertIsString($audit->new_values);
        $new = json_decode($audit->new_values, true);
        self::assertIsArray($new);
        self::assertTrue($new['not_reviewed'] ?? null, 'AUD-01: the audit row says it went unreviewed.');
        Event::assertDispatched(ReportSubmitted::class, static fn (ReportSubmitted $e): bool => $e->reportId === $id && $e->version === 1);
    }

    public function test_that_a_draft_still_inside_a_longer_window_is_left_alone(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $this->defaultRecipient($this->user(RoleName::Manager));
        $this->window(48);
        $id = $this->weekly($author, hoursOld: 30);

        $this->runJob();

        self::assertSame('draft', $this->statusOf($id), 'D-127: the window is the setting, not 24 hours.');
        self::assertFalse((bool) DB::table('reports')->where('id', $id)->value('not_reviewed'));
        self::assertSame(0, DB::table('report_versions')->where('report_id', $id)->count());
    }

    public function test_that_a_draft_inside_the_default_window_is_left_alone(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $this->defaultRecipient($this->user(RoleName::Manager));
        $id = $this->weekly($author, hoursOld: 23);

        $this->runJob();

        self::assertSame('draft', $this->statusOf($id));
    }

    public function test_that_with_no_valid_default_recipient_it_stays_a_draft_until_one_is_set(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $id = $this->weekly($author, hoursOld: 25);

        $this->runJob();

        self::assertSame('draft', $this->statusOf($id), 'D-136: no default recipient, no submit.');
        self::assertSame(0, DB::table('report_versions')->where('report_id', $id)->count());
        self::assertSame(0, DB::table('report_recipients')->where('report_id', $id)->count());
        self::assertFalse((bool) DB::table('reports')->where('id', $id)->value('not_reviewed'));

        $this->defaultRecipient($manager);
        $this->runJob();

        self::assertSame('submitted', $this->statusOf($id), 'D-136: J-06 tries again on its next run.');
        self::assertTrue((bool) DB::table('reports')->where('id', $id)->value('not_reviewed'));
    }

    /** `D-129`: a default who lost `report.view_received` is no candidate, so no default. */
    public function test_that_a_default_recipient_who_lost_report_view_received_leaves_it_a_draft(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $this->defaultRecipient($manager);
        DB::table('role_permissions')
            ->where('role_id', $manager->role_id)
            ->whereIn('permission_id', DB::table('permissions')->where('resource', 'report')->where('action', 'view_received')->pluck('id'))
            ->update(['deleted_at' => now()]);
        $id = $this->weekly($author, hoursOld: 25);

        $this->runJob();

        $this->assertStillAnUntouchedDraft($id);
    }

    /** `D-129`: never on a Manager's own report. */
    public function test_that_a_managers_own_weekly_draft_stays_a_draft(): void
    {
        $manager = $this->user(RoleName::Manager);
        $this->defaultRecipient($manager);
        $id = $this->weekly($manager, hoursOld: 25);

        $this->runJob();

        $this->assertStillAnUntouchedDraft($id);
    }

    /** `D-129`: a named user who is no longer an active Manager is no default. */
    public function test_that_a_default_recipient_who_is_no_longer_an_active_manager_leaves_it_a_draft(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $this->defaultRecipient($manager);
        DB::table('users')->where('id', $manager->id)->update(['is_active' => false]);
        $id = $this->weekly($author, hoursOld: 25);

        $this->runJob();

        self::assertSame('draft', $this->statusOf($id));
    }

    public function test_that_a_report_its_author_submits_within_the_window_is_not_flagged_and_stays_as_submitted(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $this->defaultRecipient($manager);
        $id = $this->weekly($author, hoursOld: 5);

        $this->patchJson(self::ENDPOINT.'/'.$id.'/submit', ['recipient_ids' => [$manager->id]], [...$this->bearer($author), 'If-Match' => $this->etag($id)])
            ->assertStatus(200);
        $this->travelTo(now()->addHours(30));
        $this->runJob();

        self::assertSame('submitted', $this->statusOf($id));
        self::assertFalse((bool) DB::table('reports')->where('id', $id)->value('not_reviewed'), 'D-136: submitted within the window is not flagged.');
        self::assertSame(1, DB::table('report_versions')->where('report_id', $id)->count(), 'J-06 froze nothing more.');
    }

    public function test_that_a_daily_draft_is_never_touched(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $this->defaultRecipient($this->user(RoleName::Manager));
        $id = $this->weekly($author, hoursOld: 72, type: 'daily');

        $this->runJob();

        self::assertSame('draft', $this->statusOf($id), 'D-136: J-06 is the weekly report only.');
        self::assertFalse((bool) DB::table('reports')->where('id', $id)->value('not_reviewed'));
        self::assertSame(0, DB::table('report_versions')->where('report_id', $id)->count());
    }

    public function test_that_a_second_run_changes_nothing(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $this->defaultRecipient($manager);
        $id = $this->weekly($author, hoursOld: 25);

        $this->runJob();
        $this->runJob();

        self::assertSame('submitted', $this->statusOf($id));
        self::assertSame(1, DB::table('report_versions')->where('report_id', $id)->count());
        self::assertSame(1, DB::table('report_recipients')->where('report_id', $id)->count());
        self::assertSame(1, DB::table('audit_log')->where('event', 'REPORT_SUBMITTED')->where('entity_id', $id)->count());
    }

    // ── shown prominently to its author and its recipients (§11.5) ──────────

    public function test_that_the_payloads_carry_not_reviewed(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $this->defaultRecipient($manager);
        $unreviewed = $this->weekly($author, hoursOld: 25);
        $this->runJob();

        $this->getJson(self::ENDPOINT.'/'.$unreviewed, $this->bearer($author))->assertStatus(200)->assertJsonPath('data.not_reviewed', true);
        $this->getJson(self::ENDPOINT.'/received/'.$unreviewed, $this->bearer($manager))->assertStatus(200)->assertJsonPath('data.not_reviewed', true);
        self::assertTrue($this->getJson(self::ENDPOINT, $this->bearer($author))->assertStatus(200)->json('data.0.not_reviewed'));
        self::assertTrue($this->getJson(self::ENDPOINT.'/received', $this->bearer($manager))->assertStatus(200)->json('data.0.not_reviewed'));
    }

    public function test_that_a_report_that_was_reviewed_carries_not_reviewed_false(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $id = $this->weekly($author, hoursOld: 5);
        $this->patchJson(self::ENDPOINT.'/'.$id.'/submit', ['recipient_ids' => [$manager->id]], [...$this->bearer($author), 'If-Match' => $this->etag($id)])
            ->assertStatus(200)
            ->assertJsonPath('data.not_reviewed', false);

        $this->getJson(self::ENDPOINT.'/received/'.$id, $this->bearer($manager))->assertStatus(200)->assertJsonPath('data.not_reviewed', false);
    }

    // ── the job and the schedule (J-04's shape) ─────────────────────────────

    public function test_that_the_job_is_scheduled_hourly_onto_maintenance(): void
    {
        Queue::fake();

        $scheduled = array_values(array_filter(
            app(Schedule::class)->events(),
            static fn (object $event): bool => str_contains((string) ($event->description ?? ''), SubmitUnreviewedWeeklyReportsJob::class),
        ));

        self::assertCount(1, $scheduled, 'Expected exactly one scheduled J-06 job.');
        self::assertSame('0 * * * *', $scheduled[0]->expression, 'D-137: hourly, not daily.');

        $scheduled[0]->run($this->app);

        Queue::assertPushedOn(QueueName::Maintenance->value, SubmitUnreviewedWeeklyReportsJob::class);
    }

    /** `D-137`: a draft whose window ended at 01:00 goes out with the 02:00 run, not the next midnight's. */
    public function test_that_a_draft_whose_window_ended_at_one_oclock_is_submitted_by_the_two_oclock_run(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $this->defaultRecipient($this->user(RoleName::Manager));
        $this->travelTo(now()->setTime(2, 0));
        $id = $this->weekly($author, hoursOld: 25);    // created 01:00 yesterday: the 24-hour window ended at 01:00 today.

        $scheduled = array_values(array_filter(
            app(Schedule::class)->events(),
            static fn (object $event): bool => str_contains((string) ($event->description ?? ''), SubmitUnreviewedWeeklyReportsJob::class),
        ));
        self::assertCount(1, $scheduled);
        self::assertTrue($scheduled[0]->isDue($this->app), 'D-137: the schedule fires at 02:00.');

        $this->runJob();

        self::assertSame('submitted', $this->statusOf($id));
    }

    /** `D-55` / `ST-05`: the `scheduler` service runs this once on start. */
    public function test_that_the_catch_up_command_dispatches_j06_onto_maintenance(): void
    {
        Queue::fake();

        self::assertSame(0, Artisan::call('reports:submit-unreviewed'));

        Queue::assertPushedOn(QueueName::Maintenance->value, SubmitUnreviewedWeeklyReportsJob::class);
    }

    public function test_that_the_catch_up_fails_loudly_when_j06_is_not_scheduled(): void
    {
        Queue::fake();
        $this->app->instance(Schedule::class, new Schedule);

        self::assertSame(1, Artisan::call('reports:submit-unreviewed'));
        self::assertStringContainsString('J-06', Artisan::output());

        Queue::assertNothingPushed();
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function runJob(): void
    {
        // As a worker runs it: with no request, so the audit names no user.
        $this->app->instance('request', Request::create('http://localhost'));
        $this->app->call([new SubmitUnreviewedWeeklyReportsJob, 'handle']);
    }

    private function assertStillAnUntouchedDraft(string $id): void
    {
        self::assertSame('draft', $this->statusOf($id));
        self::assertSame(0, DB::table('report_versions')->where('report_id', $id)->count());
        self::assertSame(0, DB::table('report_recipients')->where('report_id', $id)->count());
        self::assertFalse((bool) DB::table('reports')->where('id', $id)->value('not_reviewed'));
        self::assertSame(0, DB::table('audit_log')->where('event', 'REPORT_SUBMITTED')->where('entity_id', $id)->count());
    }

    private function statusOf(string $id): string
    {
        $status = DB::table('reports')->where('id', $id)->value('status');
        self::assertIsString($status);

        return $status;
    }

    private function etag(string $id): string
    {
        $token = DB::table('reports')->where('id', $id)->value('version_token');
        self::assertIsInt($token);

        return 'report:'.$id.':'.$token;
    }

    /** A weekly (or $type) draft of 2026-10-11…15 created $hoursOld hours before now, written straight to the table. */
    private function weekly(User $author, int $hoursOld, string $type = 'weekly'): string
    {
        $id = (string) Str::uuid7();
        $at = now()->subHours($hoursOld);
        DB::table('reports')->insert([
            'id' => $id, 'code' => ($type === 'weekly' ? 'RPT-WK-2026-' : 'RPT-DY-2026-').substr($id, -6), 'type' => $type, 'status' => 'draft',
            'author_id' => $author->id, 'period_start' => '2026-10-11', 'period_end' => $type === 'weekly' ? '2026-10-15' : '2026-10-11',
            'created_at' => $at, 'updated_at' => $at,
        ]);

        return $id;
    }

    private function defaultRecipient(User $manager): void
    {
        $this->patchJson('/api/v1/settings', ['settings' => ['reports.default_recipient' => $manager->id]], $this->bearer($this->admin()))
            ->assertStatus(200);
    }

    private function window(int $hours): void
    {
        $this->patchJson('/api/v1/system-limits', ['limits' => [self::WINDOW => (string) $hours]], $this->bearer($this->admin()))
            ->assertStatus(200);
    }

    /** The one hidden Super Admin of the test, built the first time anything asks. */
    private function admin(): User
    {
        return User::query()->where('email', 'super.admin@example.test')->first() ?? $this->user(RoleName::SuperAdmin);
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

        return ['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => Uuid::uuid4()->toString()];
    }
}
