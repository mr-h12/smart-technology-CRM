<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Notifications\Application\CheckStorageThreshold;
use App\Modules\Notifications\Presentation\CheckStorageThresholdJob;
use App\Modules\Storage\Domain\Contracts\StorageUsageInterface;
use App\Support\Queue\QueueName;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Module 16, Point 4 — `J-14` storage_threshold_check, "storage over limit" (`D-153`).
 *
 * The storage is the volume that holds the uploaded files (the `secure_uploads`
 * disk), measured by the Storage module. When the share of it in use reaches
 * `limits.storage_alert_percent` (80 while unset), every active Super Admin
 * (hidden included) gets one alert. It has no record of its own. It is raised
 * once (§18.3, escalate once): a later run keeps its counter and read state and
 * only refreshes the figures. When usage is back under the limit it clears, the
 * row kept. A volume that cannot be measured changes nothing — no alert is
 * raised on a guess, and none is cleared on one.
 */
final class StorageThresholdAlertsTest extends TestCase
{
    use RefreshDatabase;

    private const GB = 1_073_741_824;

    /** @var array{used_bytes: int, total_bytes: int}|null */
    private ?array $usage = null;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->app->instance(StorageUsageInterface::class, new class($this) implements StorageUsageInterface
        {
            public function __construct(private StorageThresholdAlertsTest $test) {}

            /** @return array{used_bytes: int, total_bytes: int}|null */
            public function usage(): ?array
            {
                return $this->test->usage();
            }
        });

        $this->superAdmin = $this->user(RoleName::SuperAdmin);
    }

    /** @return array{used_bytes: int, total_bytes: int}|null */
    public function usage(): ?array
    {
        return $this->usage;
    }

    public function test_that_usage_under_the_limit_raises_nothing(): void
    {
        $this->usage = ['used_bytes' => 79 * self::GB, 'total_bytes' => 100 * self::GB];

        $this->check();

        self::assertSame(0, DB::table('alerts')->count());
    }

    /** The limit is reached at the limit itself: 80 of 100 is 80 %. */
    public function test_that_usage_at_the_limit_alerts_every_active_super_admin_and_nobody_else(): void
    {
        $second = $this->user(RoleName::SuperAdmin);
        $manager = $this->user(RoleName::Manager);
        $this->usage = ['used_bytes' => 80 * self::GB, 'total_bytes' => 100 * self::GB];

        $this->check();

        $rows = DB::table('alerts')->where('kind', 'storage_over_limit')->get();
        self::assertEqualsCanonicalizing([$this->superAdmin->id, $second->id], $rows->pluck('user_id')->all());
        self::assertNotContains($manager->id, $rows->pluck('user_id')->all());

        /** @var object{subject_id: string|null, details: string, occurrences: int} $row */
        $row = $rows->firstOrFail(fn (object $r): bool => $r->user_id === $this->superAdmin->id);
        self::assertNull($row->subject_id, 'Storage has no record of its own.');
        self::assertSame(['used_percent' => '80', 'limit_percent' => '80'], json_decode($row->details, true));
        self::assertSame(1, $row->occurrences);
    }

    public function test_that_the_limit_follows_the_setting(): void
    {
        DB::table('system_limits')->where('key', 'limits.storage_alert_percent')->delete();
        DB::table('system_limits')->insert(['id' => (string) Str::uuid(), 'key' => 'limits.storage_alert_percent', 'value' => '70', 'unit' => 'percent', 'value_type' => 'integer', 'created_at' => now(), 'updated_at' => now()]);
        $this->usage = ['used_bytes' => 72 * self::GB, 'total_bytes' => 100 * self::GB];

        $this->check();

        /** @var object{details: string} $row */
        $row = DB::table('alerts')->where('kind', 'storage_over_limit')->sole();
        self::assertSame(['used_percent' => '72', 'limit_percent' => '70'], json_decode($row->details, true));
    }

    /** §18.3: escalate once. A later run over the limit keeps the counter and the read state, and refreshes the figures. */
    public function test_that_a_later_run_still_over_the_limit_only_refreshes_the_figures(): void
    {
        $this->usage = ['used_bytes' => 85 * self::GB, 'total_bytes' => 100 * self::GB];
        $this->check();
        DB::table('alerts')->update(['read_at' => now()]);

        $this->usage = ['used_bytes' => 91 * self::GB, 'total_bytes' => 100 * self::GB];
        $this->check();

        /** @var object{details: string, occurrences: int, read_at: string|null} $row */
        $row = DB::table('alerts')->where('kind', 'storage_over_limit')->sole();
        self::assertSame(1, $row->occurrences);
        self::assertNotNull($row->read_at);
        /** @var array{used_percent: string} $details */
        $details = json_decode($row->details, true);
        self::assertSame('91', $details['used_percent']);
    }

    public function test_that_usage_back_under_the_limit_clears_the_alert_and_keeps_the_row(): void
    {
        $this->usage = ['used_bytes' => 85 * self::GB, 'total_bytes' => 100 * self::GB];
        $this->check();

        $this->usage = ['used_bytes' => 60 * self::GB, 'total_bytes' => 100 * self::GB];
        $this->check();

        self::assertSame(1, DB::table('alerts')->where('kind', 'storage_over_limit')->count());
        self::assertNotNull(DB::table('alerts')->where('kind', 'storage_over_limit')->value('cleared_at'));

        // Over again: a new alert; the old one stays cleared.
        $this->usage = ['used_bytes' => 82 * self::GB, 'total_bytes' => 100 * self::GB];
        $this->check();
        self::assertSame(2, DB::table('alerts')->where('kind', 'storage_over_limit')->count());
        self::assertSame(1, DB::table('alerts')->where('kind', 'storage_over_limit')->whereNull('cleared_at')->count());
    }

    /** A volume that cannot be measured: no alert raised on a guess, none cleared on one. */
    public function test_that_an_unmeasurable_volume_changes_nothing(): void
    {
        $this->usage = ['used_bytes' => 85 * self::GB, 'total_bytes' => 100 * self::GB];
        $this->check();

        $this->usage = null;
        $this->check();

        self::assertSame(1, DB::table('alerts')->where('kind', 'storage_over_limit')->whereNull('cleared_at')->count());
    }

    public function test_that_the_job_runs_it_through_the_container(): void
    {
        $this->usage = ['used_bytes' => 85 * self::GB, 'total_bytes' => 100 * self::GB];

        $this->app->call([new CheckStorageThresholdJob, 'handle']);

        self::assertSame(1, DB::table('alerts')->where('kind', 'storage_over_limit')->count());
    }

    /** §15: J-14 daily onto `maintenance`, catch-up ❌ — the next day's run is it. */
    public function test_that_j14_is_scheduled_daily_onto_maintenance_without_a_catch_up(): void
    {
        Queue::fake();

        $scheduled = array_values(array_filter(
            app(Schedule::class)->events(),
            static fn (object $event): bool => str_contains((string) ($event->description ?? ''), CheckStorageThresholdJob::class),
        ));

        self::assertCount(1, $scheduled, 'Expected exactly one scheduled J-14 job.');
        self::assertSame('0 0 * * *', $scheduled[0]->expression);

        $scheduled[0]->run($this->app);
        Queue::assertPushedOn(QueueName::Maintenance->value, CheckStorageThresholdJob::class);

        Queue::fake();
        Artisan::call('schedule:catch-up');
        Queue::assertNotPushed(CheckStorageThresholdJob::class);
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function check(): void
    {
        $this->app->make(CheckStorageThreshold::class)->run();
    }

    private function user(RoleName $role): User
    {
        $user = new User;
        $user->fill([
            'name' => 'Test '.$role->label(),
            'email' => Str::random(8).'@example.test',
            'password' => 'Passw0rd123',
            'role_id' => Role::query()->where('slug', $role->value)->firstOrFail()->id,
            'is_active' => true,
            'is_hidden' => $role->isHidden(),
        ]);
        $user->save();

        return $user;
    }
}
