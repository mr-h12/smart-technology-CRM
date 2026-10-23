<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Notifications\Application\ClearResolvedAlerts;
use App\Modules\Notifications\Presentation\ClearResolvedAlertsJob;
use App\Support\Queue\Jobs\InfrastructureProbeJob;
use App\Support\Queue\QueueName;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Module 16, Point 3 — "job failed" (`D-153`).
 *
 * When a queued job is marked failed — its retries spent, a row in
 * `failed_jobs` — every active Super Admin gets an alert. The alert is per job
 * class, not per run, so a job that keeps failing is one alert with its counter
 * (§18.3's aggregation), unread again on each failure. It clears on the hourly
 * sweep (`J-17`) once no row of that class is left in `failed_jobs` — retried
 * successfully, or removed — and the row stays.
 *
 * A real failure, `RealQueueExecutionTest`'s way: a probe pushed to Redis
 * database 15 and drained by a real `queue:work` pass, so the alert is proved
 * to come from Laravel's own failure path and not from a hand-fired event.
 */
final class JobFailedAlertsTest extends TestCase
{
    use RefreshDatabase;

    private const TEST_REDIS_DATABASE = '15';

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $database = config('database.redis.default.database');
        self::assertIsScalar($database);
        self::assertSame(self::TEST_REDIS_DATABASE, (string) $database, 'Refusing to use a Redis database the running workers drain.');
        Redis::connection()->flushdb();
        $this->seed(RolePermissionSeeder::class);

        $this->superAdmin = $this->user(RoleName::SuperAdmin, 'super.admin@example.test');
    }

    public function test_that_a_failed_job_alerts_every_active_super_admin_by_its_class(): void
    {
        $manager = $this->user(RoleName::Manager, 'manager@example.test');

        $this->failOnce();

        $rows = DB::table('alerts')->where('kind', 'job_failed')->get();
        self::assertSame([$this->superAdmin->id], $rows->pluck('user_id')->all());
        self::assertNotContains($manager->id, $rows->pluck('user_id')->all());

        /** @var object{subject_id: string, details: string, occurrences: int} $row */
        $row = $rows->sole();
        self::assertSame(InfrastructureProbeJob::class, $row->subject_id);
        /** @var array{job: string, queue: string} $details */
        $details = json_decode($row->details, true);
        self::assertSame('InfrastructureProbeJob', $details['job']);
        self::assertSame(QueueName::Maintenance->value, $details['queue']);
        self::assertSame(1, $row->occurrences);
    }

    /** The same class failing again: one alert, its counter, unread again. */
    public function test_that_a_class_failing_again_raises_the_counter(): void
    {
        $this->failOnce();
        DB::table('alerts')->update(['read_at' => now()]);

        $this->failOnce();

        /** @var object{occurrences: int, read_at: string|null} $row */
        $row = DB::table('alerts')->where('kind', 'job_failed')->sole();
        self::assertSame(2, $row->occurrences);
        self::assertNull($row->read_at);
    }

    /** While its class still has a row in `failed_jobs` the alert stays; once none is left, the sweep clears it. */
    public function test_that_the_sweep_keeps_the_alert_while_a_failure_is_recorded_and_clears_it_after(): void
    {
        $this->failOnce();

        $this->sweep();
        self::assertNull(DB::table('alerts')->where('kind', 'job_failed')->value('cleared_at'));

        // What `queue:retry` or `queue:forget` leaves behind: no row of that class.
        DB::table('failed_jobs')->delete();
        $this->sweep();

        self::assertSame(1, DB::table('alerts')->where('kind', 'job_failed')->count(), 'A cleared alert stays; nothing is deleted.');
        self::assertNotNull(DB::table('alerts')->where('kind', 'job_failed')->value('cleared_at'));
    }

    /**
     * Module 16 · 6, found preparing the browser check: `subject_id` was 64 characters
     * and `RaiseQuotationExpiryAlertsJob`'s class name is 68, so its failure would have
     * lost its alert (logged, not raised). Every queued job in the application must fit.
     */
    public function test_that_every_queued_job_class_in_the_application_fits_as_a_subject(): void
    {
        $classes = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Modules'), \FilesystemIterator::SKIP_DOTS)) as $file) {
            /** @var \SplFileInfo $file */
            $source = (string) file_get_contents($file->getPathname());
            if (str_contains($source, 'implements ShouldQueue') && preg_match('/^namespace ([^;]+);/m', $source, $ns) === 1) {
                $classes[] = $ns[1].'\\'.$file->getBasename('.php');
            }
        }
        self::assertGreaterThan(10, count($classes), 'The scan found no jobs, so it proved nothing.');

        foreach ($classes as $class) {
            $this->app->make(\App\Modules\Notifications\Application\RaiseSystemAlerts::class)->jobFailed($class, QueueName::Maintenance->value);
        }

        self::assertSame(count($classes), DB::table('alerts')->where('kind', 'job_failed')->distinct()->count('subject_id'));
    }

    /** `J-17`: the sweep, hourly onto `maintenance`. No catch-up: the next hour's run is the catch-up. */
    public function test_that_j17_is_scheduled_hourly_onto_maintenance(): void
    {
        Queue::fake();

        $scheduled = array_values(array_filter(
            app(Schedule::class)->events(),
            static fn (object $event): bool => str_contains((string) ($event->description ?? ''), ClearResolvedAlertsJob::class),
        ));

        self::assertCount(1, $scheduled, 'Expected exactly one scheduled J-17 job.');
        self::assertSame('0 * * * *', $scheduled[0]->expression);

        $scheduled[0]->run($this->app);

        Queue::assertPushedOn(QueueName::Maintenance->value, ClearResolvedAlertsJob::class);
    }

    public function test_that_the_sweep_job_runs_it_through_the_container(): void
    {
        $this->failOnce();
        DB::table('failed_jobs')->delete();

        $this->app->call([new ClearResolvedAlertsJob, 'handle']);

        self::assertNotNull(DB::table('alerts')->where('kind', 'job_failed')->value('cleared_at'));
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    /** One probe that throws, pushed to Redis and drained by one real worker pass with a single try. */
    private function failOnce(): void
    {
        InfrastructureProbeJob::dispatch(bin2hex(random_bytes(8)), true)->onConnection('redis')->onQueue(QueueName::Maintenance->value);

        self::assertSame(0, Artisan::call('queue:work', [
            'connection' => 'redis',
            '--queue' => QueueName::Maintenance->value,
            '--once' => true,
            '--tries' => 1,
            '--sleep' => 0,
        ]));
    }

    private function sweep(): void
    {
        $this->app->make(ClearResolvedAlerts::class)->run();
    }

    private function user(RoleName $role, string $email): User
    {
        $user = new User;
        $user->fill([
            'name' => 'Test '.$role->label(),
            'email' => $email,
            'password' => 'Passw0rd123',
            'role_id' => Role::query()->where('slug', $role->value)->firstOrFail()->id,
            'is_active' => true,
            'is_hidden' => $role->isHidden(),
        ]);
        $user->save();

        return $user;
    }
}
