<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Reports\Presentation\MarkMissedDailyReportsJob;
use App\Support\Queue\QueueName;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Ramsey\Uuid\Uuid;
use stdClass;
use Tests\TestCase;

/**
 * Module 13, slice 5 · 5.1 — `D-135`: J-04 marks an unsubmitted daily draft
 * Missed after midnight; a Missed report can still be submitted, stored and
 * shown as late (`submitted_late`). The working days are `D-125`'s (Sunday to
 * Thursday); nothing is marked while the go-live date is empty (`D-127`).
 *
 * 2026-10-15 is a Thursday: the 16th is Friday, the 17th Saturday, the 18th
 * Sunday, the 19th Monday. The jobs run on Monday the 19th.
 */
final class MissedDailyReportsTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Passw0rd123';

    private const MONDAY = '2026-10-19';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(now()->setDate(2026, 10, 19)->setTime(0, 30));
    }

    // ── what J-04 marks ─────────────────────────────────────────────────────

    public function test_that_a_daily_draft_of_a_past_working_day_becomes_missed(): void
    {
        $this->goLive('2026-10-01');
        $id = $this->draft($this->user(RoleName::IndoorSales), '2026-10-18');

        $this->runJob();

        self::assertSame('missed', $this->statusOf($id));
    }

    public function test_that_todays_draft_is_left_alone(): void
    {
        $this->goLive('2026-10-01');
        $id = $this->draft($this->user(RoleName::IndoorSales), self::MONDAY);

        $this->runJob();

        self::assertSame('draft', $this->statusOf($id));
    }

    public function test_that_a_friday_or_a_saturday_draft_is_left_alone(): void
    {
        $this->goLive('2026-10-01');
        $author = $this->user(RoleName::IndoorSales);
        $friday = $this->draft($author, '2026-10-16');
        $saturday = $this->draft($author, '2026-10-17');

        $this->runJob();

        self::assertSame('draft', $this->statusOf($friday), 'D-125: Friday is not a working day.');
        self::assertSame('draft', $this->statusOf($saturday), 'D-125: Saturday is not a working day.');
    }

    public function test_that_nothing_is_marked_while_the_go_live_date_is_empty(): void
    {
        $id = $this->draft($this->user(RoleName::IndoorSales), '2026-10-18');

        $this->runJob();

        self::assertSame('draft', $this->statusOf($id), 'D-127: while the go-live date is empty J-04 marks nothing.');
        self::assertSame(0, DB::table('audit_log')->where('event', 'REPORT_MISSED')->count());
    }

    public function test_that_a_draft_dated_before_the_go_live_date_is_left_alone(): void
    {
        $this->goLive('2026-10-18');
        $author = $this->user(RoleName::IndoorSales);
        $before = $this->draft($author, '2026-10-15');
        $onTheDay = $this->draft($author, '2026-10-18');

        $this->runJob();

        self::assertSame('draft', $this->statusOf($before), 'D-135: only days on or after the go-live date.');
        self::assertSame('missed', $this->statusOf($onTheDay), 'D-135: the go-live day itself counts.');
    }

    public function test_that_submitted_returned_and_acknowledged_reports_are_never_touched(): void
    {
        $this->goLive('2026-10-01');
        $author = $this->user(RoleName::IndoorSales);
        $ids = [];
        foreach (['submitted', 'viewed', 'returned', 'acknowledged'] as $i => $status) {
            $ids[$status] = $this->draft($author, '2026-10-18', $status, 'RPT-DY-2026-90'.$i);
        }

        $this->runJob();

        foreach ($ids as $status => $id) {
            self::assertSame($status, $this->statusOf($id), "D-135: a {$status} report is not an unsubmitted draft.");
        }
    }

    public function test_that_a_second_run_changes_nothing_and_records_no_second_audit_entry(): void
    {
        $this->goLive('2026-10-01');
        $id = $this->draft($this->user(RoleName::IndoorSales), '2026-10-18');

        $this->runJob();
        $this->runJob();

        self::assertSame('missed', $this->statusOf($id));
        self::assertSame(1, DB::table('audit_log')->where('event', 'REPORT_MISSED')->where('entity_id', $id)->count());
    }

    public function test_that_each_missed_report_records_report_missed_by_the_system(): void
    {
        $this->goLive('2026-10-01');
        $id = $this->draft($this->user(RoleName::IndoorSales), '2026-10-18');

        $this->runJob();

        $audit = DB::table('audit_log')->where('event', 'REPORT_MISSED')->where('entity_type', 'report')->where('entity_id', $id)->first();
        self::assertInstanceOf(stdClass::class, $audit, 'AUD-01: marking a report Missed left no audit entry.');
        self::assertNull($audit->user_id, 'D-135: the system marks it, not a person.');
        self::assertIsString($audit->old_values);
        self::assertIsString($audit->new_values);
        /** @var array{status?: string} $old */
        $old = json_decode($audit->old_values, true);
        /** @var array{status?: string} $new */
        $new = json_decode($audit->new_values, true);
        self::assertSame('draft', $old['status'] ?? null);
        self::assertSame('missed', $new['status'] ?? null);
    }

    // ── the job and the schedule (J-01's shape) ─────────────────────────────

    public function test_that_the_job_is_scheduled_daily_onto_maintenance(): void
    {
        Queue::fake();

        $scheduled = array_values(array_filter(
            app(Schedule::class)->events(),
            static fn (object $event): bool => str_contains((string) ($event->description ?? ''), MarkMissedDailyReportsJob::class),
        ));

        self::assertCount(1, $scheduled, 'Expected exactly one scheduled J-04 job.');
        self::assertSame('0 0 * * *', $scheduled[0]->expression);

        $scheduled[0]->run($this->app);

        Queue::assertPushedOn(QueueName::Maintenance->value, MarkMissedDailyReportsJob::class);
    }

    // ── late ────────────────────────────────────────────────────────────────

    public function test_that_the_author_can_edit_and_submit_a_missed_report_and_it_is_stored_late(): void
    {
        $this->goLive('2026-10-01');
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $id = $this->draft($author, '2026-10-18');
        $this->runJob();

        $this->postJson('/api/v1/reports/'.$id.'/notes', ['body' => 'written late'], $this->bearer($author))->assertStatus(201);
        $this->submit($author, $id, [$manager->id])->assertStatus(200);

        self::assertSame('submitted', $this->statusOf($id));
        self::assertTrue((bool) DB::table('reports')->where('id', $id)->value('submitted_late'), 'D-135: stored as late.');
    }

    public function test_that_a_report_submitted_before_it_was_missed_is_on_time(): void
    {
        $this->goLive('2026-10-01');
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $id = $this->draft($author, '2026-10-18');

        $this->travelTo(now()->setDate(2026, 10, 18)->setTime(23, 59));
        $this->submit($author, $id, [$manager->id])->assertStatus(200);
        $this->travelTo(now()->setDate(2026, 10, 19)->setTime(0, 30));
        $this->runJob();

        self::assertSame('submitted', $this->statusOf($id), 'D-135: on time, whatever the hour.');
        self::assertFalse((bool) DB::table('reports')->where('id', $id)->value('submitted_late'));
    }

    public function test_that_a_recipients_payload_carries_submitted_late_for_a_late_report(): void
    {
        $this->goLive('2026-10-01');
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $id = $this->draft($author, '2026-10-18');
        $this->runJob();
        $this->submit($author, $id, [$manager->id])->assertStatus(200);

        $this->getJson('/api/v1/reports/received/'.$id, $this->bearer($manager))
            ->assertStatus(200)
            ->assertJsonPath('data.submitted_late', true);
        $listed = $this->getJson('/api/v1/reports/received', $this->bearer($manager))->assertStatus(200)->json('data.0.submitted_late');
        self::assertTrue($listed);
    }

    /** `SEC-07`: the row-level rule holds for a Missed report as for a draft — only its author reaches it. */
    public function test_that_only_its_author_reaches_a_missed_report(): void
    {
        $this->goLive('2026-10-01');
        $author = $this->user(RoleName::IndoorSales);
        $other = $this->user(RoleName::IndoorSales, 'other.sales@example.test');
        $manager = $this->user(RoleName::Manager);
        $id = $this->draft($author, '2026-10-18');
        $note = $this->postJson('/api/v1/reports/'.$id.'/notes', ['body' => 'mine'], $this->bearer($author))->assertStatus(201)->json('data.id');
        self::assertIsString($note);
        $this->runJob();
        self::assertSame('missed', $this->statusOf($id));

        foreach ([$other, $manager] as $caller) {
            $this->postJson('/api/v1/reports/'.$id.'/notes', ['body' => 'theirs'], $this->bearer($caller))->assertStatus(404);
            $this->patchJson('/api/v1/reports/'.$id.'/notes/'.$note, ['body' => 'theirs'], $this->bearer($caller))->assertStatus(404);
            $this->patchJson('/api/v1/reports/'.$id.'/submit', ['recipient_ids' => [$manager->id]], [...$this->bearer($caller), 'If-Match' => $this->etag($id)])->assertStatus(404);
        }

        // A salesperson holds no `report.view_received`; a Manager does, and was not sent this one.
        $this->getJson('/api/v1/reports/received/'.$id, $this->bearer($other))->assertStatus(403);
        $this->getJson('/api/v1/reports/received/'.$id, $this->bearer($manager))->assertStatus(404);
        self::assertSame([], $this->getJson('/api/v1/reports/received', $this->bearer($manager))->assertStatus(200)->json('data'));

        self::assertSame('missed', $this->statusOf($id));
        self::assertFalse((bool) DB::table('reports')->where('id', $id)->value('submitted_late'));
        self::assertSame('mine', DB::table('report_notes')->where('id', $note)->value('body'));
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function runJob(): void
    {
        // As a worker runs it: with no request, so the audit names no user.
        $this->app->instance('request', Request::create('http://localhost'));
        $this->app->call([new MarkMissedDailyReportsJob, 'handle']);
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

    /** A daily report of $day for $author, written straight to the table (the day is the point). */
    private function draft(User $author, string $day, string $status = 'draft', ?string $code = null): string
    {
        $id = (string) Str::uuid7();
        DB::table('reports')->insert([
            'id' => $id, 'code' => $code ?? 'RPT-DY-2026-'.substr($id, -6), 'type' => 'daily', 'status' => $status,
            'author_id' => $author->id, 'period_start' => $day, 'period_end' => $day,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function goLive(string $date): void
    {
        $this->patchJson('/api/v1/settings', ['settings' => ['reports.go_live_date' => $date]], $this->bearer($this->user(RoleName::SuperAdmin)))
            ->assertStatus(200);
    }

    /**
     * @param  list<string>  $recipients
     * @return TestResponse<\Illuminate\Http\JsonResponse>
     */
    private function submit(User $author, string $id, array $recipients): TestResponse
    {
        return $this->patchJson(
            '/api/v1/reports/'.$id.'/submit',
            ['recipient_ids' => $recipients],
            [...$this->bearer($author), 'If-Match' => $this->etag($id)],
        );
    }

    private function user(RoleName $role, ?string $email = null): User
    {
        $user = new User;
        $user->fill([
            'name' => 'Test '.$role->label(),
            'email' => $email ?? str_replace('_', '.', $role->value).'@example.test',
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
