<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Contracts\UserFactsInterface;
use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Module 16, Point 3 (`D-153`) — the two facts Identity gives the system alerts.
 *
 * `superAdminIds()`: every active Super Admin, hidden included (§3.12 rule 6:
 * hidden is not absent), never a deleted one — `SEC-03`'s recipients, now for
 * the bell too. `lockedUserIds()`: the accounts whose lock is still in force
 * now (`LockoutPolicy::isLocked`: an expired `locked_until` is not a lock).
 */
final class SystemAlertFactsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_that_every_active_super_admin_is_named_hidden_or_not(): void
    {
        $role = Role::firstOrCreate(['slug' => RoleName::SuperAdmin->value], ['name' => 'Super Admin', 'is_system' => true])->id;
        $hidden = User::factory()->hidden()->create(['role_id' => $role]);
        $visible = User::factory()->create(['role_id' => $role]);
        User::factory()->inactive()->create(['role_id' => $role]);
        User::factory()->create(['role_id' => $role])->delete();
        User::factory()->create();

        self::assertEqualsCanonicalizing([$hidden->id, $visible->id], $this->facts()->superAdminIds());
    }

    public function test_that_only_a_lock_still_in_force_is_named(): void
    {
        Carbon::setTestNow('2026-10-22 09:00:00');
        $locked = User::factory()->create(['locked_until' => '2026-10-22 09:30:00']);
        User::factory()->create(['locked_until' => '2026-10-22 08:59:59']);
        User::factory()->create(['locked_until' => '2026-10-22 09:00:00']);
        User::factory()->create(['locked_until' => null]);

        self::assertSame([$locked->id], $this->facts()->lockedUserIds());
    }

    private function facts(): UserFactsInterface
    {
        return $this->app->make(UserFactsInterface::class);
    }
}
