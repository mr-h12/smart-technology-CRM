<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Reports\Presentation\ArchiveOldReportsJob;
use App\Support\Queue\QueueName;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use stdClass;
use Tests\TestCase;

/**
 * Module 13, slice 7 · 7.4 — `J-08` (§15 `archive_old_reports`, daily; §11.4 "No
 * deletion — automatic archiving after six months"; `D-127`: the archive age is
 * a Super Admin setting starting at six months). The owner's rulings of 2026-10-18:
 *
 * - J-08 archives every non-draft report (submitted, viewed, acknowledged, returned,
 *   missed) whose age passes `limits.report_archive_months` (6 while unset). The age
 *   counts from `submitted_at`; a Missed report, which has no submit, counts from its
 *   `period_end`. A draft is never archived.
 * - Idempotent (§15.1); each archived report is audited `REPORT_ARCHIVED` by the system
 *   (no actor, as J-04 records `REPORT_MISSED`); `maintenance` queue; in `schedule:catch-up`.
 *
 * The job runs on Monday 2026-10-19 at 00:30 UTC, so the default cut-off is 2026-04-19:
 * 2026-04-14 is six months and five days old, 2026-04-24 is five months and 25 days.
 */
final class ArchiveOldReportsTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Passw0rd123';

    private const MONTHS = 'limits.report_archive_months';

    private const PAST = '2026-04-14';

    private const UNDER = '2026-04-24';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(now()->setDate(2026, 10, 19)->setTime(0, 30));
    }

    // ── what J-08 archives ──────────────────────────────────────────────────

    /** @return array<string, array{string}> */
    public static function nonDraftStatuses(): array
    {
        return [
            'submitted' => ['submitted'],
            'viewed' => ['viewed'],
            'acknowledged' => ['acknowledged'],
            'returned' => ['returned'],
            'missed' => ['missed'],
        ];
    }

    #[DataProvider('nonDraftStatuses')]
    public function test_that_a_report_past_the_default_age_is_archived_and_audited_by_the_system(string $status): void
    {
        $id = $this->report($status, self::PAST);

        $this->runJob();

        self::assertSame('archived', $this->statusOf($id), "§11.4: a {$status} report past six months is archived.");
        $audits = DB::table('audit_log')->where('event', 'REPORT_ARCHIVED')->where('entity_type', 'report')->where('entity_id', $id)->get();
        self::assertCount(1, $audits, 'AUD-01: archiving a report left no single audit entry.');
        $audit = $audits->first();
        self::assertInstanceOf(stdClass::class, $audit);
        self::assertNull($audit->user_id, 'J-08: the system archives it, not a person.');
        self::assertIsString($audit->old_values);
        self::assertIsString($audit->new_values);
        self::assertSame(['status' => $status], json_decode($audit->old_values, true));
        self::assertSame(['status' => 'archived'], json_decode($audit->new_values, true));
    }

    /** The positive control is the past-age report: a job that never ran would leave the young ones alone too. */
    public function test_that_a_report_just_under_the_default_age_is_left_alone_and_the_setting_is_unset(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $old = $this->report('submitted', self::PAST, $author);
        $young = [];
        foreach (['submitted', 'viewed', 'acknowledged', 'returned', 'missed'] as $status) {
            $young[$status] = $this->report($status, self::UNDER, $author);
        }

        $this->runJob();

        self::assertSame(0, DB::table('system_limits')->where('key', self::MONTHS)->whereNull('deleted_at')->count(), 'The default is 6 while the limit is unset (D-127).');
        self::assertSame('archived', $this->statusOf($old), 'The control: the job ran and the cut-off is six months.');
        foreach ($young as $status => $id) {
            self::assertSame($status, $this->statusOf($id), "§11.4: a {$status} report under six months is not archived.");
        }
        self::assertSame(1, DB::table('audit_log')->where('event', 'REPORT_ARCHIVED')->count());
    }

    public function test_that_a_draft_is_never_archived_at_any_age(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $daily = $this->report('draft', '2025-01-02', $author);
        $weekly = $this->report('draft', '2025-01-02', $author, 'weekly');
        $control = $this->report('submitted', self::PAST, $author);

        $this->runJob();

        self::assertSame('archived', $this->statusOf($control), 'The control: the job ran.');
        self::assertSame('draft', $this->statusOf($daily));
        self::assertSame('draft', $this->statusOf($weekly));
        self::assertSame(0, DB::table('audit_log')->whereIn('entity_id', [$daily, $weekly])->where('event', 'REPORT_ARCHIVED')->count());
    }

    /** The owner's ruling: age counts from `submitted_at`, not from the report's day. */
    public function test_that_the_age_counts_from_submitted_at_and_not_from_the_period(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        // A report of an old day that was only just submitted: it is young.
        $lateSubmit = $this->report('submitted', '2026-10-01', $author, periodEnd: '2026-01-10');
        // A report of a recent day whose submit is old: it is old.
        $oldSubmit = $this->report('acknowledged', self::PAST, $author, periodEnd: '2026-10-01');

        $this->runJob();

        self::assertSame('submitted', $this->statusOf($lateSubmit), 'The owner, 2026-10-18: counted from submitted_at.');
        self::assertSame('archived', $this->statusOf($oldSubmit));
    }

    /** A Missed report has no submit: it counts from `period_end`. */
    public function test_that_a_missed_report_counts_from_its_period_end(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $past = $this->report('missed', self::PAST, $author);
        $under = $this->report('missed', self::UNDER, $author);

        $this->runJob();

        self::assertNull(DB::table('reports')->where('id', $past)->value('submitted_at'), 'Fixture: a Missed report has no submit.');
        self::assertSame('archived', $this->statusOf($past));
        self::assertSame('missed', $this->statusOf($under));
    }

    // ── the setting ─────────────────────────────────────────────────────────

    public function test_that_setting_three_months_moves_the_cut_off(): void
    {
        $id = $this->report('submitted', '2026-06-15');    // four months old
        $this->runJob();
        self::assertSame('submitted', $this->statusOf($id), 'Fixture: under the default six months.');

        $this->months(3);
        $this->runJob();

        self::assertSame('archived', $this->statusOf($id), 'D-127: the archive age is the Super Admin setting.');
        self::assertSame(1, DB::table('audit_log')->where('event', 'REPORT_ARCHIVED')->where('entity_id', $id)->count());
    }

    public function test_that_setting_twelve_months_spares_what_six_would_archive(): void
    {
        $this->months(12);
        $young = $this->report('submitted', '2026-02-15');    // eight months old
        $old = $this->report('submitted', '2025-09-15');    // thirteen months old

        $this->runJob();

        self::assertSame('submitted', $this->statusOf($young), 'D-127: twelve months, not six.');
        self::assertSame('archived', $this->statusOf($old), 'The control: the job ran with the setting.');
    }

    // ── idempotence ─────────────────────────────────────────────────────────

    public function test_that_a_second_run_changes_nothing_and_records_no_second_audit_entry(): void
    {
        $id = $this->report('acknowledged', self::PAST);

        $this->runJob();
        $first = DB::table('reports')->where('id', $id)->first();
        $this->travel(1)->hours();
        $this->runJob();

        self::assertSame('archived', $this->statusOf($id));
        self::assertEquals($first, DB::table('reports')->where('id', $id)->first(), '§15.1: an archived report is not touched again.');
        self::assertSame(1, DB::table('audit_log')->where('event', 'REPORT_ARCHIVED')->where('entity_id', $id)->count());
    }

    // ── the job and the schedule (J-04's shape) ─────────────────────────────

    public function test_that_the_job_is_scheduled_daily_onto_maintenance(): void
    {
        Queue::fake();

        $scheduled = array_values(array_filter(
            app(Schedule::class)->events(),
            static fn (object $event): bool => str_contains((string) ($event->description ?? ''), ArchiveOldReportsJob::class),
        ));

        self::assertCount(1, $scheduled, 'Expected exactly one scheduled J-08 job.');
        self::assertSame('0 0 * * *', $scheduled[0]->expression);

        $scheduled[0]->run($this->app);

        Queue::assertPushedOn(QueueName::Maintenance->value, ArchiveOldReportsJob::class);
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function runJob(): void
    {
        // As a worker runs it: with no request, so the audit names no user.
        $this->app->instance('request', Request::create('http://localhost'));
        $this->app->call([new ArchiveOldReportsJob, 'handle']);
    }

    private function statusOf(string $id): string
    {
        $status = DB::table('reports')->where('id', $id)->value('status');
        self::assertIsString($status);

        return $status;
    }

    /**
     * A report written straight to the table (the age is the point). $day is its
     * `submitted_at` — and, for a Missed report, its `period_end` (no submit).
     */
    private function report(string $status, string $day, ?User $author = null, string $type = 'daily', ?string $periodEnd = null): string
    {
        $author ??= $this->user(RoleName::IndoorSales);
        $id = (string) Str::uuid7();
        $end = $periodEnd ?? $day;
        DB::table('reports')->insert([
            'id' => $id, 'code' => ($type === 'weekly' ? 'RPT-WK-2026-' : 'RPT-DY-2026-').substr($id, -6), 'type' => $type, 'status' => $status,
            'author_id' => $author->id, 'period_start' => $type === 'weekly' ? '2025-01-02' : $end, 'period_end' => $end,
            'submitted_at' => in_array($status, ['draft', 'missed'], true) ? null : $day.' 09:00:00+00',
            'created_at' => $day.' 08:00:00+00', 'updated_at' => $day.' 08:00:00+00',
        ]);

        return $id;
    }

    private function months(int $months): void
    {
        $this->patchJson('/api/v1/system-limits', ['limits' => [self::MONTHS => (string) $months]], $this->bearer($this->admin()))
            ->assertStatus(200);
    }

    /** The one hidden Super Admin of the test, built the first time anything asks. */
    private function admin(): User
    {
        return User::query()->where('email', 'super.admin@example.test')->first() ?? $this->user(RoleName::SuperAdmin);
    }

    private function user(RoleName $role): User
    {
        $email = str_replace('_', '.', $role->value).'@example.test';

        $existing = User::query()->where('email', $email)->first();
        if ($existing instanceof User) {
            return $existing;
        }

        $user = new User;
        $user->fill([
            'name' => 'Test '.$role->label(),
            'email' => $email,
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
