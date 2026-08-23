<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Point 1.2 — `users` replaced, and `user_sessions` created.
 *
 * Same discipline as Point 1.1: the constraints are the feature, so most of
 * what follows offers the database a wrong row and requires a refusal. The
 * audit block is read out of the `DB-02` row in §4.8 rather than restated, so
 * this file cannot drift into agreeing with the migration that it checks.
 *
 * Two absences are pinned deliberately, because an absence is exactly what a
 * later reader adds back without knowing why it was left out: `deleted_by`,
 * which `DB-02` does not name, and `payload`, which belongs to Redis.
 */
final class UserSchemaMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = ['users', 'user_sessions'];

    /** Mounted read-only by compose and by the CI test container — Point 8.4. */
    private const MASTER_DOCUMENTATION = '/opt/crm/docs/CRM_Documentation_EN.md';

    // ───────────────────────────────────────────── the standard column block

    #[DataProvider('tables')]
    public function test_that_both_tables_carry_the_block_section_4_8_requires(string $table): void
    {
        self::assertFileExists(self::MASTER_DOCUMENTATION,
            'The master documentation is not mounted; without it DB-02 below is only agreeing with the migration.');

        $documentation = file_get_contents(self::MASTER_DOCUMENTATION);
        self::assertIsString($documentation);

        // /u — the file is bilingual, and a byte-mode pattern can shift on the
        // trailing byte of an Arabic character.
        $matched = preg_match(
            '/^\|\s*DB-02\s*\|\s*Mandatory audit columns:\s*(.+?)\s*\|/mu',
            $documentation,
            $row,
        );
        self::assertSame(1, $matched, 'The DB-02 row in §4.8 no longer lists the audit columns.');

        foreach (array_map(trim(...), explode('·', $row[1])) as $column) {
            self::assertTrue(Schema::hasColumn($table, $column), "{$table} is missing {$column} (DB-02).");
        }

        self::assertTrue(Schema::hasColumn($table, 'deleted_at'), "{$table} must be soft-deletable (DB-01).");
        self::assertSame('uuid', self::type($table, 'id'), 'D-61 fixes a UUID key.');
    }

    #[DataProvider('tables')]
    public function test_that_deleted_by_is_absent_as_the_rule_specifies(string $table): void
    {
        self::assertFalse(Schema::hasColumn($table, 'deleted_by'),
            "{$table} has deleted_by. DB-02 does not name it and no other table in this system carries it.");
    }

    // ────────────────────────────────────────────────────── the users columns

    public function test_that_users_holds_exactly_what_module_1_needs(): void
    {
        self::assertSame(
            ['id', 'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at',
                'name', 'email', 'password', 'role_id', 'is_active', 'is_hidden',
                'failed_login_attempts', 'locked_until'],
            self::columns('users'),
        );

        foreach (['name' => 255, 'email' => 255, 'password' => 255] as $column => $length) {
            self::assertSame('character varying', self::type('users', $column));
            self::assertSame($length, self::length('users', $column));
            self::assertSame('NO', self::nullability('users', $column));
        }

        self::assertSame('uuid', self::type('users', 'role_id'));
        self::assertSame('NO', self::nullability('users', 'role_id'), '§3.1 gives every user a role.');
        self::assertSame('smallint', self::type('users', 'failed_login_attempts'));
        self::assertSame('timestamp with time zone', self::type('users', 'locked_until'));
        self::assertSame('YES', self::nullability('users', 'locked_until'));
    }

    /**
     * `D-34` deactivates rather than deletes, and `§3.12` rule 6 hides the
     * Super Admin. Both are defaults a new row must get without being told, or
     * the first user created by a seeder is inactive or visible by accident.
     */
    public function test_that_the_account_flags_default_the_safe_way(): void
    {
        self::assertSame('true', self::default('users', 'is_active'), 'D-34: a new account is active.');
        self::assertSame('false', self::default('users', 'is_hidden'), '§3.12 r6: only Super Admin is hidden.');
        self::assertStringStartsWith("'0'", (string) self::default('users', 'failed_login_attempts'),
            'SEC-03: a new account has no failures against it.');
    }

    /**
     * The scaffold's leftovers, pinned as gone. `email_verified_at` and
     * `remember_token` came from Laravel's stub; nothing in the documentation
     * asks for either — `D-29` expires a session after eight hours idle and
     * `SEC-04`'s verification is a step in the password-change flow, not a
     * column. `id` being a `uuid` rather than a `bigint` is the whole reason
     * the table was replaced instead of altered.
     */
    public function test_that_the_scaffolded_columns_are_gone(): void
    {
        self::assertFalse(Schema::hasColumn('users', 'email_verified_at'));
        self::assertFalse(Schema::hasColumn('users', 'remember_token'));
        self::assertNotSame('bigint', self::type('users', 'id'));
    }

    // ─────────────────────────────────────────────── the user_sessions columns

    public function test_that_user_sessions_is_a_device_list(): void
    {
        self::assertSame(
            ['id', 'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at',
                'user_id', 'session_id', 'ip_address', 'user_agent', 'last_activity_at'],
            self::columns('user_sessions'),
        );

        // 45 is the widest textual IPv6 — an IPv4-mapped ::ffff:255.255.255.255.
        self::assertSame(45, self::length('user_sessions', 'ip_address'));
        self::assertSame('YES', self::nullability('user_sessions', 'ip_address'));
        self::assertSame('text', self::type('user_sessions', 'user_agent'));

        // D-29 compares against a real interval, so this is a real timestamp
        // and not Laravel's epoch integer.
        self::assertSame('timestamp with time zone', self::type('user_sessions', 'last_activity_at'));
        self::assertSame('NO', self::nullability('user_sessions', 'last_activity_at'));
    }

    /**
     * The absence that matters most here.
     *
     * `SEC-05` asks for an "active device list", and §14.2 puts the session
     * driver on Redis. A `payload` column would either be dead weight or a
     * second copy of state Redis already owns — and the second is how two
     * sources of truth start. This fails if someone adds one while the driver
     * still says `redis`, which is the moment to have the conversation.
     */
    public function test_that_no_session_payload_is_stored_while_redis_owns_it(): void
    {
        // Read from `.env.example` and not from `config()`. The suite runs with
        // `SESSION_DRIVER=array` — phpunit.xml sets it, deliberately, so tests
        // do not touch Redis — so the runtime value here says nothing about
        // where sessions live when the system is deployed. `.env.example` is
        // the checked-in statement of that, and CI already asserts it is
        // complete.
        $environment = file_get_contents(base_path('.env.example'));
        self::assertIsString($environment);

        self::assertMatchesRegularExpression(
            '/^SESSION_DRIVER=redis$/mu',
            $environment,
            'The deployed session driver is no longer Redis. If sessions now live in PostgreSQL, this rule needs revisiting.',
        );

        self::assertFalse(Schema::hasColumn('user_sessions', 'payload'),
            'user_sessions has a payload column while the session driver is Redis — two sources of truth.');
    }

    // ─────────────────────────────────────────── uniqueness under soft delete

    public function test_that_two_live_accounts_cannot_share_an_email(): void
    {
        self::insertUser('sara@example.test');

        $this->expectException(QueryException::class);

        self::insertUser('sara@example.test');
    }

    public function test_that_an_archived_account_releases_its_email(): void
    {
        $id = self::insertUser('sara@example.test');
        DB::table('users')->where('id', $id)->update(['deleted_at' => now()]);

        $replacement = self::insertUser('sara@example.test');

        self::assertNotSame($id, $replacement);
        self::assertSame(2, DB::table('users')->where('email', 'sara@example.test')->count());
    }

    /**
     * `D-34` again, from the other side: deactivating is not archiving. A
     * deactivated account keeps its address and stays visible to the Team
     * Leader who has to reassign its deals — which is exactly what a soft
     * delete would prevent, and why both columns exist.
     */
    public function test_that_deactivating_does_not_free_the_email(): void
    {
        $id = self::insertUser('sara@example.test');
        DB::table('users')->where('id', $id)->update(['is_active' => false]);

        $this->expectException(QueryException::class);

        self::insertUser('sara@example.test');
    }

    #[DataProvider('partialUniqueIndexes')]
    public function test_that_the_unique_indexes_are_partial(string $index): void
    {
        $definition = DB::scalar('select indexdef from pg_indexes where indexname = ?', [$index]);

        self::assertIsString($definition, "{$index} does not exist.");
        self::assertStringContainsString('UNIQUE', $definition);
        self::assertStringContainsString('WHERE (deleted_at IS NULL)', $definition,
            "{$index} is a plain unique index; an archived row would reserve its key forever.");
    }

    public function test_that_a_negative_failure_count_is_refused(): void
    {
        $id = self::insertUser('sara@example.test');

        $this->expectException(QueryException::class);

        DB::table('users')->where('id', $id)->update(['failed_login_attempts' => -1]);
    }

    // ──────────────────────────────────────────────────── referential integrity

    public function test_that_a_user_cannot_hold_a_role_that_does_not_exist(): void
    {
        $this->expectException(QueryException::class);

        DB::table('users')->insert([
            'id' => Uuid::uuid7()->toString(),
            'name' => 'Sara',
            'email' => 'sara@example.test',
            'password' => 'x',
            'role_id' => Uuid::uuid7()->toString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * RESTRICT and emphatically not CASCADE. Cascading would mean removing a
     * role deletes the people who held it — the exact opposite of `D-34`.
     */
    public function test_that_a_role_with_holders_cannot_be_deleted(): void
    {
        self::insertUser('sara@example.test');

        $this->expectException(QueryException::class);

        DB::table('roles')->where('id', self::roleId())->delete();
    }

    public function test_that_the_role_key_restricts_and_the_session_key_cascades(): void
    {
        self::assertSame('RESTRICT', self::deleteRule('users_role_id_foreign'),
            'A role must not take its holders with it (D-34).');
        self::assertSame('CASCADE', self::deleteRule('user_sessions_user_id_foreign'),
            'A session must not outlive the account it belongs to.');
    }

    public function test_that_removing_a_user_takes_their_sessions_with_them(): void
    {
        $user = self::insertUser('sara@example.test');
        self::insertSession($user);
        self::insertSession($user);

        self::assertSame(2, DB::table('user_sessions')->count());

        // A physical delete, which DB-01 forbids the application from doing.
        // The cascade exists for the repairs the rules do allow.
        DB::table('users')->where('id', $user)->delete();

        self::assertSame(0, DB::table('user_sessions')->count());
    }

    public function test_that_a_session_cannot_name_a_user_that_does_not_exist(): void
    {
        $this->expectException(QueryException::class);

        self::insertSession(Uuid::uuid7()->toString());
    }

    public function test_that_a_revoked_session_frees_its_identifier(): void
    {
        $user = self::insertUser('sara@example.test');
        $first = self::insertSession($user, 'sess-abc');

        DB::table('user_sessions')->where('id', $first)->update(['deleted_at' => now()]);

        self::insertSession($user, 'sess-abc');

        self::assertSame(1, DB::table('user_sessions')->whereNull('deleted_at')->count());
    }

    // ───────────────────────────────────────────────────────────── the down path

    /**
     * The interesting half of `DEV-03` here: `down()` must not merely drop what
     * `up()` made, it must **put the scaffold back**. Rolling past this
     * migration leaves `0001_01_01_000000`'s own `down()` about to drop a
     * `users` table, and if this one did not recreate it the sequence would be
     * unreversible in the middle.
     */
    public function test_down_restores_the_scaffolded_table_it_replaced(): void
    {
        self::assertSame('uuid', self::type('users', 'id'));

        Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);

        self::assertFalse(Schema::hasTable('user_sessions'), 'down() must drop user_sessions.');
        self::assertTrue(Schema::hasTable('users'), 'down() must put the scaffolded users table back.');
        self::assertSame('bigint', self::type('users', 'id'), 'The restored table is the scaffold, key and all.');
        self::assertTrue(Schema::hasColumn('users', 'remember_token'));
    }

    public function test_down_leaves_no_orphan_index_or_constraint(): void
    {
        Artisan::call('migrate:reset', ['--force' => true]);

        foreach (self::TABLES as $table) {
            self::assertFalse(Schema::hasTable($table), "down() must drop {$table}.");
        }

        self::assertNull(
            DB::scalar("select indexname from pg_indexes where indexname = 'users_email_unique_alive'"),
            'The partial unique index outlived its table.',
        );
        self::assertNull(
            DB::scalar("select conname from pg_constraint where conname = 'users_failed_login_attempts_check'"),
            'The CHECK constraint outlived its table.',
        );
    }

    // ─────────────────────────────────────────────────────────────── providers

    /** @return iterable<string, array{string}> */
    public static function tables(): iterable
    {
        foreach (self::TABLES as $table) {
            yield $table => [$table];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function partialUniqueIndexes(): iterable
    {
        foreach (['users_email_unique_alive', 'user_sessions_session_id_unique_alive'] as $index) {
            yield $index => [$index];
        }
    }

    // ───────────────────────────────────────────────────────────────── helpers

    private static function roleId(): string
    {
        $existing = DB::table('roles')->where('slug', 'indoor_sales')->value('id');

        if (is_string($existing)) {
            return $existing;
        }

        $id = Uuid::uuid7()->toString();

        DB::table('roles')->insert([
            'id' => $id,
            'name' => 'Indoor Sales',
            'slug' => 'indoor_sales',
            'is_system' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private static function insertUser(string $email): string
    {
        $id = Uuid::uuid7()->toString();

        DB::table('users')->insert([
            'id' => $id,
            'name' => 'Sara',
            'email' => $email,
            'password' => 'not-a-real-hash',
            'role_id' => self::roleId(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private static function insertSession(string $userId, ?string $sessionId = null): string
    {
        $id = Uuid::uuid7()->toString();

        DB::table('user_sessions')->insert([
            'id' => $id,
            'user_id' => $userId,
            'session_id' => $sessionId ?? Uuid::uuid7()->toString(),
            'ip_address' => '::ffff:192.0.2.1',
            'user_agent' => 'probe',
            'last_activity_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** @return list<string> */
    private static function columns(string $table): array
    {
        $names = [];

        foreach (Schema::getColumnListing($table) as $name) {
            self::assertIsString($name);
            $names[] = $name;
        }

        return $names;
    }

    private static function type(string $table, string $column): ?string
    {
        return self::info('data_type', $table, $column);
    }

    private static function nullability(string $table, string $column): ?string
    {
        return self::info('is_nullable', $table, $column);
    }

    private static function default(string $table, string $column): ?string
    {
        return self::info('column_default', $table, $column);
    }

    private static function info(string $field, string $table, string $column): ?string
    {
        $value = DB::scalar(
            "select {$field} from information_schema.columns where table_name = ? and column_name = ?",
            [$table, $column],
        );

        return is_string($value) ? $value : null;
    }

    private static function length(string $table, string $column): ?int
    {
        $value = DB::scalar(
            'select character_maximum_length from information_schema.columns where table_name = ? and column_name = ?',
            [$table, $column],
        );

        return is_int($value) ? $value : null;
    }

    private static function deleteRule(string $constraint): ?string
    {
        $value = DB::scalar(
            'select delete_rule from information_schema.referential_constraints where constraint_name = ?',
            [$constraint],
        );

        return is_string($value) ? $value : null;
    }
}
