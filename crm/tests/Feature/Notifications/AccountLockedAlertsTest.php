<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Modules\Identity\Domain\Authentication\LockoutPolicy;
use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Notifications\Application\ClearResolvedAlerts;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Module 16, Point 3 — "account locked" (`D-153`, `SEC-03`).
 *
 * The moment an account locks (Identity's `AccountLocked` event — the one that
 * already sends `SEC-03`'s email), every active Super Admin gets an alert about
 * it, hidden or not (§3.12 rule 6: hidden is not absent). Nobody else does. The
 * same account locking again while its alert is open is a new occurrence: the
 * counter rises and the alert is unread again — a new lock is news, not a
 * reminder. The alert clears when the lock has ended, on the next run of the
 * hourly sweep (`J-17`), and the row stays.
 */
final class AccountLockedAlertsTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Passw0rd123';

    private const WRONG = 'Wr0ngPassword';

    private User $superAdmin;

    private User $target;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Config::set('identity.lockout_minutes', 30);
        Notification::fake();
        Carbon::setTestNow('2026-10-22 09:00:00');

        $this->superAdmin = $this->user(RoleName::SuperAdmin, 'super.admin@example.test');
        $this->target = $this->user(RoleName::IndoorSales, 'person@example.test');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_that_a_lockout_alerts_every_active_super_admin_and_nobody_else(): void
    {
        $second = $this->user(RoleName::SuperAdmin, 'second.admin@example.test');
        $inactive = $this->user(RoleName::SuperAdmin, 'old.admin@example.test', active: false);
        $manager = $this->user(RoleName::Manager, 'manager@example.test');

        $this->lockTarget();

        $rows = DB::table('alerts')->where('kind', 'account_locked')->get();
        self::assertEqualsCanonicalizing([$this->superAdmin->id, $second->id], $rows->pluck('user_id')->all());
        self::assertNotContains($inactive->id, $rows->pluck('user_id')->all());
        self::assertNotContains($manager->id, $rows->pluck('user_id')->all());
        self::assertNotContains($this->target->id, $rows->pluck('user_id')->all());

        /** @var object{subject_id: string, details: string, occurrences: int, read_at: string|null} $row */
        $row = $rows->firstOrFail(fn (object $r): bool => $r->user_id === $this->superAdmin->id);
        self::assertSame($this->target->id, $row->subject_id);
        /** @var array{name: string} $details */
        $details = json_decode($row->details, true);
        self::assertSame('Test Indoor Sales', $details['name']);
        self::assertSame(1, $row->occurrences);
        self::assertNull($row->read_at);
    }

    /** A second lock of the same account while its alert is open: the counter rises and it is unread again. */
    public function test_that_the_same_account_locking_again_is_a_new_occurrence(): void
    {
        $this->lockTarget();
        DB::table('alerts')->update(['read_at' => now()]);

        // The lock ends; the counter is still at five (it resets on a success), so the next wrong password locks again.
        Carbon::setTestNow('2026-10-22 09:40:00');
        $this->postJson('/api/v1/auth/login', ['email' => $this->target->email, 'password' => self::WRONG])->assertStatus(423);

        /** @var object{occurrences: int, read_at: string|null} $row */
        $row = DB::table('alerts')->where('kind', 'account_locked')->where('user_id', $this->superAdmin->id)->sole();
        self::assertSame(2, $row->occurrences);
        self::assertNull($row->read_at, 'A new lock is news, not a reminder.');
    }

    public function test_that_the_sweep_keeps_the_alert_while_locked_and_clears_it_after(): void
    {
        $this->lockTarget();

        $this->sweep();
        self::assertSame(1, DB::table('alerts')->where('kind', 'account_locked')->whereNull('cleared_at')->count());

        Carbon::setTestNow('2026-10-22 09:31:00'); // past the 30-minute lock
        $this->sweep();

        self::assertSame(1, DB::table('alerts')->where('kind', 'account_locked')->count(), 'A cleared alert stays; nothing is deleted.');
        self::assertNotNull(DB::table('alerts')->where('kind', 'account_locked')->value('cleared_at'));
    }

    /** The sweep answers for its own kinds only. */
    public function test_that_the_sweep_leaves_a_quotation_alert_alone(): void
    {
        DB::table('alerts')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $this->target->id, 'kind' => 'quotation_expiring', 'subject_id' => 'q-1', 'details' => '{}',
            'occurrences' => 1, 'first_raised_at' => now(), 'last_raised_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->sweep();

        self::assertNull(DB::table('alerts')->where('kind', 'quotation_expiring')->value('cleared_at'));
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function lockTarget(): void
    {
        for ($i = 1; $i < LockoutPolicy::MAX_ATTEMPTS; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => $this->target->email, 'password' => self::WRONG])->assertStatus(401);
        }
        $this->postJson('/api/v1/auth/login', ['email' => $this->target->email, 'password' => self::WRONG])->assertStatus(423);
    }

    private function sweep(): void
    {
        $this->app->make(ClearResolvedAlerts::class)->run();
    }

    private function user(RoleName $role, string $email, bool $active = true): User
    {
        $user = new User;
        $user->fill([
            'name' => 'Test '.$role->label(),
            'email' => $email,
            'password' => self::PASSWORD,
            'role_id' => Role::query()->where('slug', $role->value)->firstOrFail()->id,
            'is_active' => $active,
            'is_hidden' => $role->isHidden(),
        ]);
        $user->save();

        return $user;
    }
}
