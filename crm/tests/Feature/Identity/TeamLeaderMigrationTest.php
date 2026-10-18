<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `D-138` (Module 13 · 7.5a): a user has at most one Team Leader —
 * `users.team_leader_id`, a nullable reference to another user.
 */
final class TeamLeaderMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_21_000000_add_team_leader_id_to_users.php';

    /** Nullable, so every existing account stays valid (no Team Leader is the default). */
    public function test_that_users_gains_a_nullable_uuid_team_leader_id(): void
    {
        $column = (array) DB::selectOne(
            "select data_type, is_nullable from information_schema.columns
             where table_name = 'users' and column_name = 'team_leader_id'",
        );

        self::assertSame(['data_type' => 'uuid', 'is_nullable' => 'YES'], $column);
    }

    /** DB-xx: a foreign key to users.id whose rule is SET NULL (`pg_constraint.confdeltype = 'n'`). */
    public function test_that_team_leader_id_is_a_foreign_key_to_users_that_sets_null(): void
    {
        $fk = (array) DB::selectOne(
            "select c.confdeltype as on_delete, t.relname as referenced
             from pg_constraint c
             join pg_class s on s.oid = c.conrelid
             join pg_class t on t.oid = c.confrelid
             join pg_attribute a on a.attrelid = c.conrelid and a.attnum = any(c.conkey)
             where c.contype = 'f' and s.relname = 'users' and a.attname = 'team_leader_id'",
        );

        self::assertSame(['on_delete' => 'n', 'referenced' => 'users'], $fk);
    }

    /** The rule, by behaviour: removing the leader's row leaves the member with no leader. */
    public function test_that_removing_the_leader_row_nulls_the_members_column(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $leader = $this->plainUser('leader@example.test');
        $member = $this->plainUser('member@example.test');
        DB::table('users')->where('id', $member->id)->update(['team_leader_id' => $leader->id]);

        DB::table('users')->where('id', $leader->id)->delete();

        self::assertNull(DB::table('users')->where('id', $member->id)->value('team_leader_id'));
    }

    /** The Team scope reads "everyone whose leader is X" (D-138), so the column is indexed. */
    public function test_that_team_leader_id_is_indexed(): void
    {
        self::assertTrue($this->hasTeamLeaderIndex(), 'No index covers users.team_leader_id.');
    }

    // ────────────────────────────────────────────────────────────── DEV-03

    public function test_the_migration_rolls_back_and_forward(): void
    {
        self::assertFileExists(base_path(self::MIGRATION));
        self::assertTrue(Schema::hasColumn('users', 'team_leader_id'), 'The column is not there to roll back.');

        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATION]));

        self::assertFalse(Schema::hasColumn('users', 'team_leader_id'), 'down() left the column behind (DEV-03).');
        self::assertFalse($this->hasTeamLeaderIndex(), 'down() left the index behind (DEV-03).');
        self::assertNull(DB::scalar(
            "select c.conname from pg_constraint c join pg_class s on s.oid = c.conrelid
             where c.contype = 'f' and s.relname = 'users' and c.conname like '%team_leader%'",
        ), 'down() left the foreign key behind (DEV-03).');

        self::assertSame(0, Artisan::call('migrate'));

        self::assertTrue(Schema::hasColumn('users', 'team_leader_id'), 'The column did not come back.');
        self::assertTrue($this->hasTeamLeaderIndex(), 'The index did not come back.');
    }

    private function hasTeamLeaderIndex(): bool
    {
        return DB::selectOne(
            "select 1 as present from pg_indexes
             where tablename = 'users' and indexdef ilike '%(team_leader_id)%'",
        ) !== null;
    }

    private function plainUser(string $email): User
    {
        $user = new User;
        $user->fill(['name' => 'Test', 'email' => $email, 'password' => 'Passw0rd123', 'is_active' => true,
            'role_id' => Role::query()->where('slug', 'team_leader')->firstOrFail()->id]);
        $user->save();

        return $user;
    }
}
