<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Modules\Admin\Domain\Contracts\SettingsRepositoryInterface;
use App\Modules\Admin\Domain\Settings\SystemSetting;
use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Notifications\Application\RaiseQuotationExpiryAlerts;
use App\Modules\Notifications\Presentation\RaiseQuotationExpiryAlertsJob;
use App\Modules\Quotations\Domain\Contracts\ExpiringQuotationsInterface;
use App\Support\Queue\QueueName;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Module 16, Point 2 — J-16, "quotation expiring soon" (`D-153`).
 *
 * A `sent` quotation whose `valid_until` falls between the company's today and
 * today + `quotations.expiry_alert_days` (3 while unset), both included, raises
 * one alert for the deal's owner and one for the owner's Team Leader (`D-138`);
 * an inactive person gets none. The alert is raised **once** (§18.3, "escalate
 * once — no reminder ladders"): a later run neither adds a row, nor raises the
 * counter, nor makes it unread again. It clears itself on the run after its
 * quotation leaves the window — answered, expired, replaced, extended, or moved
 * to another owner — and the cleared row stays (nothing is deleted).
 *
 * Which quotations are expiring is Quotations' answer, through its contract;
 * here it is a stand-in, so these tests pin the alert rules alone.
 */
final class RaiseQuotationExpiryAlertsTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{quotation_id: string, number: string, valid_until: string, owner_id: string|null}> */
    private array $expiring = [];

    /** @var list<array{string, string}> */
    private array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Carbon::setTestNow('2026-10-22 09:00:00');

        $this->app->instance(ExpiringQuotationsInterface::class, new class($this) implements ExpiringQuotationsInterface
        {
            public function __construct(private RaiseQuotationExpiryAlertsTest $test) {}

            public function sentExpiringBetween(string $from, string $to): array
            {
                return $this->test->answer($from, $to);
            }
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * The stand-in's answer: what was set, inside the window it was asked for.
     *
     * @return list<array{quotation_id: string, number: string, valid_until: string, owner_id: string|null}>
     */
    public function answer(string $from, string $to): array
    {
        $this->asked[] = [$from, $to];

        return array_values(array_filter($this->expiring, static fn (array $q): bool => $q['valid_until'] >= $from && $q['valid_until'] <= $to));
    }

    public function test_that_the_window_is_today_to_today_plus_three_while_the_setting_is_unset(): void
    {
        $this->run16();

        self::assertSame([['2026-10-22', '2026-10-25']], $this->asked);
    }

    public function test_that_the_window_follows_the_setting(): void
    {
        DB::table('system_limits')->where('key', 'quotations.expiry_alert_days')->delete();
        DB::table('system_limits')->insert(['id' => (string) Str::uuid(), 'key' => 'quotations.expiry_alert_days', 'value' => '7', 'unit' => 'days', 'value_type' => 'integer', 'updated_at' => now(), 'created_at' => now()]);

        $this->run16();

        self::assertSame([['2026-10-22', '2026-10-29']], $this->asked);
    }

    /** `D-145`'s rule for every job: today is the company's day, not UTC's. */
    public function test_that_today_is_the_companys_day(): void
    {
        $this->app->make(SettingsRepositoryInterface::class)->put(SystemSetting::Timezone, 'Africa/Cairo');
        Carbon::setTestNow('2026-10-22 22:30:00'); // already the 23rd in Cairo (UTC+3)

        $this->run16();

        self::assertSame([['2026-10-23', '2026-10-26']], $this->asked);
    }

    public function test_that_an_expiring_quotation_alerts_its_owner_and_the_owners_team_leader(): void
    {
        $leader = $this->user(RoleName::TeamLeader);
        $owner = $this->user(RoleName::IndoorSales, $leader);
        $this->expiring = [$this->quotation('q-1', 'QT-2026-0018', '2026-10-24', $owner)];

        $this->run16();

        $rows = DB::table('alerts')->orderBy('user_id')->get();
        self::assertCount(2, $rows);
        self::assertEqualsCanonicalizing([$owner->id, $leader->id], $rows->pluck('user_id')->all());
        /** @var object{kind: string, subject_id: string, details: string, occurrences: int, read_at: string|null, cleared_at: string|null} $row */
        foreach ($rows as $row) {
            self::assertSame('quotation_expiring', $row->kind);
            self::assertSame('q-1', $row->subject_id);
            self::assertSame(['number' => 'QT-2026-0018', 'valid_until' => '2026-10-24'], json_decode($row->details, true));
            self::assertSame(1, $row->occurrences);
            self::assertNull($row->read_at);
            self::assertNull($row->cleared_at);
        }
    }

    public function test_that_an_owner_without_a_team_leader_alone_is_alerted(): void
    {
        $owner = $this->user(RoleName::IndoorSales);
        $this->expiring = [$this->quotation('q-1', 'QT-2026-0018', '2026-10-24', $owner)];

        $this->run16();

        self::assertSame([$owner->id], DB::table('alerts')->pluck('user_id')->all());
    }

    public function test_that_an_inactive_person_is_not_alerted_and_the_other_still_is(): void
    {
        $leader = $this->user(RoleName::TeamLeader);
        $owner = $this->user(RoleName::IndoorSales, $leader, active: false);
        $this->expiring = [$this->quotation('q-1', 'QT-2026-0018', '2026-10-24', $owner)];

        $this->run16();

        self::assertSame([$leader->id], DB::table('alerts')->pluck('user_id')->all());
    }

    public function test_that_a_quotation_whose_deal_has_no_owner_alerts_nobody(): void
    {
        $this->expiring = [$this->quotation('q-1', 'QT-2026-0018', '2026-10-24', null)];

        $this->run16();

        self::assertSame(0, DB::table('alerts')->count());
    }

    /** §18.3: escalate once. A second run — the same day or the next — adds nothing and changes nothing the reader sees. */
    public function test_that_a_later_run_neither_adds_nor_repeats_nor_unreads(): void
    {
        $owner = $this->user(RoleName::IndoorSales);
        $this->expiring = [$this->quotation('q-1', 'QT-2026-0018', '2026-10-24', $owner)];
        $this->run16();
        DB::table('alerts')->update(['read_at' => now()]);

        $this->run16();
        Carbon::setTestNow('2026-10-23 09:00:00');
        $this->run16();

        /** @var object{occurrences: int, read_at: string|null, last_raised_at: string} $row */
        $row = DB::table('alerts')->sole();
        self::assertSame(1, $row->occurrences);
        self::assertNotNull($row->read_at, 'A reminder ladder would make it unread again (§18.3).');
        self::assertSame('2026-10-22 09:00:00', Carbon::parse($row->last_raised_at)->utc()->format('Y-m-d H:i:s'));
    }

    public function test_that_a_changed_date_or_number_refreshes_the_open_alert(): void
    {
        $owner = $this->user(RoleName::IndoorSales);
        $this->expiring = [$this->quotation('q-1', 'QT-2026-0018', '2026-10-24', $owner)];
        $this->run16();

        $this->expiring = [$this->quotation('q-1', 'QT-2026-0018', '2026-10-25', $owner)];
        $this->run16();

        /** @var object{details: string} $row */
        $row = DB::table('alerts')->sole();
        /** @var array{valid_until: string} $details */
        $details = json_decode($row->details, true);
        self::assertSame('2026-10-25', $details['valid_until']);
    }

    /** Answered, expired, replaced by a new version, or extended out of the window — each leaves Quotations' answer. */
    public function test_that_the_alert_clears_when_its_quotation_leaves_the_window_and_stays_as_history(): void
    {
        $leader = $this->user(RoleName::TeamLeader);
        $owner = $this->user(RoleName::IndoorSales, $leader);
        $this->expiring = [$this->quotation('q-1', 'QT-2026-0018', '2026-10-24', $owner)];
        $this->run16();

        $this->expiring = [];
        $result = $this->run16();

        self::assertSame(2, DB::table('alerts')->count(), 'A cleared alert is history; nothing is deleted.');
        self::assertSame(0, DB::table('alerts')->whereNull('cleared_at')->count());
        self::assertSame(['raised' => 0, 'cleared' => 2], $result);
    }

    /** A cleared quotation that comes back into the window is a new alert; the old one stays cleared. */
    public function test_that_a_quotation_back_in_the_window_raises_a_new_alert(): void
    {
        $owner = $this->user(RoleName::IndoorSales);
        $q = $this->quotation('q-1', 'QT-2026-0018', '2026-10-24', $owner);
        $this->expiring = [$q];
        $this->run16();
        $this->expiring = [];
        $this->run16();

        $this->expiring = [$q];
        $this->run16();

        self::assertSame(2, DB::table('alerts')->count());
        self::assertSame(1, DB::table('alerts')->whereNull('cleared_at')->count());
    }

    /** The deal moved to another owner: the old owner's alert clears, the new owner's is raised. */
    public function test_that_a_new_owner_takes_the_alert_over(): void
    {
        $before = $this->user(RoleName::IndoorSales);
        $after = $this->user(RoleName::IndoorSales);
        $this->expiring = [$this->quotation('q-1', 'QT-2026-0018', '2026-10-24', $before)];
        $this->run16();

        $this->expiring = [$this->quotation('q-1', 'QT-2026-0018', '2026-10-24', $after)];
        $this->run16();

        self::assertSame([$after->id], DB::table('alerts')->whereNull('cleared_at')->pluck('user_id')->all());
        self::assertNotNull(DB::table('alerts')->where('user_id', $before->id)->value('cleared_at'));
    }

    /** Only J-16's own kind is touched: another kind's open alert on the same person stays open. */
    public function test_that_clearing_leaves_other_kinds_alone(): void
    {
        $owner = $this->user(RoleName::SuperAdmin);
        DB::table('alerts')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $owner->id, 'kind' => 'job_failed', 'subject_id' => 'job-1', 'details' => '{}',
            'occurrences' => 1, 'first_raised_at' => now(), 'last_raised_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->run16();

        self::assertNull(DB::table('alerts')->where('kind', 'job_failed')->value('cleared_at'));
    }

    public function test_that_the_job_runs_it_through_the_container(): void
    {
        $owner = $this->user(RoleName::IndoorSales);
        $this->expiring = [$this->quotation('q-1', 'QT-2026-0018', '2026-10-24', $owner)];

        $this->app->call([new RaiseQuotationExpiryAlertsJob, 'handle']);

        self::assertSame(1, DB::table('alerts')->count());
    }

    /** `D-153`: daily, at `J-01`'s time, onto `maintenance`. */
    public function test_that_j16_is_scheduled_daily_at_j01s_time_onto_maintenance(): void
    {
        Queue::fake();

        $scheduled = array_values(array_filter(
            app(Schedule::class)->events(),
            static fn (object $event): bool => str_contains((string) ($event->description ?? ''), RaiseQuotationExpiryAlertsJob::class),
        ));

        self::assertCount(1, $scheduled, 'Expected exactly one scheduled J-16 job.');
        self::assertSame('0 0 * * *', $scheduled[0]->expression);

        $scheduled[0]->run($this->app);

        Queue::assertPushedOn(QueueName::Maintenance->value, RaiseQuotationExpiryAlertsJob::class);
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    /** @return array{raised: int, cleared: int} */
    private function run16(): array
    {
        return $this->app->make(RaiseQuotationExpiryAlerts::class)->run();
    }

    /** @return array{quotation_id: string, number: string, valid_until: string, owner_id: string|null} */
    private function quotation(string $id, string $number, string $validUntil, ?User $owner): array
    {
        return ['quotation_id' => $id, 'number' => $number, 'valid_until' => $validUntil, 'owner_id' => $owner?->id];
    }

    private function user(RoleName $role, ?User $leader = null, bool $active = true): User
    {
        $user = new User;
        $user->fill([
            'name' => 'Test '.$role->label(),
            'email' => Str::random(8).'@example.test',
            'password' => 'Passw0rd123',
            'role_id' => Role::query()->where('slug', $role->value)->firstOrFail()->id,
            'is_active' => $active,
            'is_hidden' => $role->isHidden(),
        ]);
        $user->save();

        if ($leader !== null) {
            DB::table('users')->where('id', $user->id)->update(['team_leader_id' => $leader->id]);
        }

        return $user;
    }
}
