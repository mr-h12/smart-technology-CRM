<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Modules\Admin\Domain\Backups\BackupFailed;
use App\Modules\Admin\Domain\Backups\BackupSucceeded;
use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Module 16, Point 5 — "backup failed" (`D-153`, `BK-05`).
 *
 * Admin announces each backup's outcome (`BackupFailed`, `BackupSucceeded`;
 * `RunBackupTest` proves they are announced). A failure alerts every active
 * Super Admin, hidden included, with no record of its own; another failure
 * before a success is one more occurrence, unread again — each night that fails
 * is news. The next success clears it, the row kept.
 */
final class BackupFailedAlertsTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->superAdmin = $this->user(RoleName::SuperAdmin);
    }

    public function test_that_a_failed_backup_alerts_every_active_super_admin_and_nobody_else(): void
    {
        $manager = $this->user(RoleName::Manager);

        event(new BackupFailed('run-1', 'pg_dump: connection refused', new \DateTimeImmutable('2026-10-22 02:00:00')));

        $rows = DB::table('alerts')->where('kind', 'backup_failed')->get();
        self::assertSame([$this->superAdmin->id], $rows->pluck('user_id')->all());
        self::assertNotContains($manager->id, $rows->pluck('user_id')->all());

        /** @var object{subject_id: string|null, occurrences: int} $row */
        $row = $rows->sole();
        self::assertNull($row->subject_id, 'A backup alert has no record of its own.');
        self::assertSame(1, $row->occurrences);
    }

    public function test_that_another_failure_before_a_success_is_one_more_occurrence(): void
    {
        event(new BackupFailed('run-1', 'pg_dump: connection refused', new \DateTimeImmutable('2026-10-22 02:00:00')));
        DB::table('alerts')->update(['read_at' => now()]);

        event(new BackupFailed('run-2', 'pg_dump: connection refused', new \DateTimeImmutable('2026-10-23 02:00:00')));

        /** @var object{occurrences: int, read_at: string|null} $row */
        $row = DB::table('alerts')->where('kind', 'backup_failed')->sole();
        self::assertSame(2, $row->occurrences);
        self::assertNull($row->read_at);
    }

    public function test_that_the_next_success_clears_it_and_keeps_the_row(): void
    {
        event(new BackupFailed('run-1', 'pg_dump: connection refused', new \DateTimeImmutable('2026-10-22 02:00:00')));

        event(new BackupSucceeded('run-2'));

        self::assertSame(1, DB::table('alerts')->where('kind', 'backup_failed')->count());
        self::assertNotNull(DB::table('alerts')->where('kind', 'backup_failed')->value('cleared_at'));
    }

    /** A success clears only its own kind. */
    public function test_that_a_success_leaves_other_alerts_alone(): void
    {
        DB::table('alerts')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $this->superAdmin->id, 'kind' => 'storage_over_limit', 'subject_id' => null, 'details' => '{}',
            'occurrences' => 1, 'first_raised_at' => now(), 'last_raised_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        event(new BackupSucceeded('run-2'));

        self::assertNull(DB::table('alerts')->where('kind', 'storage_over_limit')->value('cleared_at'));
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
