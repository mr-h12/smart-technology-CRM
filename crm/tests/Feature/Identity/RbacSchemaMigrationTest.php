<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Rbac\PermissionMatrix;
use App\Modules\Identity\Domain\Rbac\Scope;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Point 1.1 — `roles`, `permissions` and `role_permissions`.
 *
 * **The constraints are the feature.** A schema dump with the right column
 * names proves nothing about whether the database refuses a wrong row, so most
 * of what follows inserts something invalid and requires a rejection.
 *
 * Two things here are deliberately *not* compared against the migration that
 * created them, because a check that agrees with its own source is defect #4 on
 * this project's list. The audit block is read out of `DB-02` in the master
 * documentation, and the permitted scopes are compared against `Scope::cases()`
 * — the migration writes its five literals, the enum declares its five cases,
 * and neither can see the other.
 */
final class RbacSchemaMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = ['roles', 'permissions', 'role_permissions'];

    /** Mounted read-only by compose and by the CI test container — see Point 8.4. */
    private const MASTER_DOCUMENTATION = '/opt/crm/docs/CRM_Documentation_EN.md';

    // ───────────────────────────────────────────── the standard column block

    /**
     * `DB-02` names the audit columns and `DB-01` requires the soft delete. Both
     * are read from §4.8 rather than restated here, so a change to the rule
     * fails this test instead of quietly leaving the tables behind.
     */
    #[DataProvider('tables')]
    public function test_that_every_table_carries_the_block_section_4_8_requires(string $table): void
    {
        self::assertFileExists(
            self::MASTER_DOCUMENTATION,
            'The master documentation is not mounted. This fails rather than skips: without it '
            .'the audit block below would only be agreeing with the migration that wrote it.',
        );

        $documentation = file_get_contents(self::MASTER_DOCUMENTATION);
        self::assertIsString($documentation);

        // /u throughout — the file is bilingual, and in byte mode a pattern can
        // shift on the trailing byte of an Arabic character.
        $matched = preg_match(
            '/^\|\s*DB-02\s*\|\s*Mandatory audit columns:\s*(.+?)\s*\|/mu',
            $documentation,
            $row,
        );
        self::assertSame(1, $matched, 'The DB-02 row in §4.8 no longer lists the audit columns.');

        $required = array_map(trim(...), explode('·', $row[1]));
        self::assertSame(
            ['created_by', 'created_at', 'updated_by', 'updated_at'],
            $required,
            'DB-02 changed. The standardAudit() macro and this expectation both need revisiting.',
        );

        foreach ($required as $column) {
            self::assertTrue(
                Schema::hasColumn($table, $column),
                "{$table} is missing {$column}, which DB-02 makes mandatory.",
            );
        }

        // DB-01: soft delete on every table, no physical DELETE.
        self::assertMatchesRegularExpression(
            '/^\|\s*DB-01\s*\|\s*Soft delete on every table/mu',
            $documentation,
            'DB-01 no longer reads as a soft-delete rule.',
        );
        self::assertTrue(Schema::hasColumn($table, 'deleted_at'), "{$table} must be soft-deletable (DB-01).");

        // D-61: a UUID key, not an auto-increment.
        self::assertSame('uuid', self::columnType($table, 'id'));
        self::assertSame('NO', self::nullability($table, 'id'));
    }

    /**
     * `deleted_by` is **not** part of the block, and its absence is deliberate
     * rather than an oversight. `DB-02` names four columns and this is not one
     * of them; `standardAudit()` creates four and this is not one of them; and
     * every table Module 0 shipped — `files`, `document_sequences`, the four
     * attachment pivots — is without it. Adding it to these three alone would
     * make the RBAC tables the only ones shaped differently, which is precisely
     * what the macro exists to prevent. Pinned so that if it is ever wanted, it
     * is added everywhere by a decision rather than here by accident.
     */
    #[DataProvider('tables')]
    public function test_that_deleted_by_is_absent_as_the_rule_specifies(string $table): void
    {
        self::assertFalse(
            Schema::hasColumn($table, 'deleted_by'),
            "{$table} has deleted_by. DB-02 does not name it and no other table in this system "
            .'carries it — if it is now wanted, it belongs in the shared macro and in a decision.',
        );
    }

    // ─────────────────────────────────────────────────────── the column shapes

    public function test_that_roles_holds_what_section_3_1_needs(): void
    {
        self::assertSame(
            ['id', 'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at',
                'name', 'slug', 'is_system', 'description'],
            self::columns('roles'),
        );

        self::assertSame(['character varying', 128], [self::columnType('roles', 'name'), self::length('roles', 'name')]);
        self::assertSame(['character varying', 64], [self::columnType('roles', 'slug'), self::length('roles', 'slug')]);
        self::assertSame('boolean', self::columnType('roles', 'is_system'));
        self::assertSame('NO', self::nullability('roles', 'is_system'));
        self::assertSame('YES', self::nullability('roles', 'description'));
    }

    public function test_that_permissions_expresses_resource_action_scope(): void
    {
        // §3.2: "Permission = Resource + Action + Scope". The scope is part of
        // the permission's identity, which is why it is a column here and not
        // on the link table.
        self::assertSame(
            ['id', 'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at',
                'resource', 'action', 'scope', 'description'],
            self::columns('permissions'),
        );

        foreach (['resource' => 64, 'action' => 64, 'scope' => 16] as $column => $length) {
            self::assertSame('character varying', self::columnType('permissions', $column));
            self::assertSame($length, self::length('permissions', $column));
            self::assertSame('NO', self::nullability('permissions', $column));
        }
    }

    public function test_that_role_permissions_links_and_nothing_more(): void
    {
        self::assertSame(
            ['id', 'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at',
                'role_id', 'permission_id'],
            self::columns('role_permissions'),
        );

        self::assertSame('uuid', self::columnType('role_permissions', 'role_id'));
        self::assertSame('uuid', self::columnType('role_permissions', 'permission_id'));
        self::assertSame('NO', self::nullability('role_permissions', 'role_id'));
        self::assertSame('NO', self::nullability('role_permissions', 'permission_id'));
    }

    // ────────────────────────────────────────────────────────── the scope check

    /**
     * The migration writes five string literals; `Scope` declares five cases.
     * Neither file can read the other, so this is the only place they are
     * required to agree.
     */
    public function test_that_the_permitted_scopes_are_exactly_the_five_in_section_3_2(): void
    {
        $definition = DB::scalar(
            "select pg_get_constraintdef(oid) from pg_constraint where conname = 'permissions_scope_check'",
        );

        self::assertIsString($definition, 'permissions_scope_check does not exist.');

        foreach (Scope::cases() as $scope) {
            self::assertStringContainsString(
                "'".$scope->value."'",
                $definition,
                "The CHECK constraint does not admit the documented scope '{$scope->value}'.",
            );
        }

        // And no sixth. Counting the quoted literals catches a scope the
        // database permits that §3.2 never named.
        self::assertSame(
            count(Scope::cases()),
            preg_match_all("/'[a-z]+'/u", $definition),
            'The CHECK constraint permits a different number of scopes than §3.2 defines.',
        );
    }

    public function test_that_an_undocumented_scope_is_refused(): void
    {
        $this->expectException(QueryException::class);

        self::insertPermission('customer', 'view', 'everything');
    }

    // ──────────────────────────────────────────── uniqueness under soft delete

    public function test_that_two_live_roles_cannot_share_a_slug(): void
    {
        self::insertRole('Manager', 'manager');

        $this->expectException(QueryException::class);

        self::insertRole('Manager Copy', 'manager');
    }

    /**
     * The reason every unique index here is partial. `D-34` archives rather than
     * deletes, so under a plain UNIQUE one archived role would reserve its slug
     * for the lifetime of the system and no replacement could ever take it.
     */
    public function test_that_an_archived_role_releases_its_slug(): void
    {
        $id = self::insertRole('Manager', 'manager');

        DB::table('roles')->where('id', $id)->update(['deleted_at' => now()]);

        $replacement = self::insertRole('Manager', 'manager');

        self::assertNotSame($id, $replacement);
        self::assertSame(2, DB::table('roles')->where('slug', 'manager')->count());
    }

    public function test_that_the_same_triple_cannot_be_defined_twice(): void
    {
        self::insertPermission('customer', 'view', 'own');

        $this->expectException(QueryException::class);

        self::insertPermission('customer', 'view', 'own');
    }

    public function test_that_the_same_action_may_exist_at_a_different_scope(): void
    {
        // §3.3 grants `customer.view` at All, Team, Out, Own and Asgn depending
        // on the role. Those are five distinct permissions, not one.
        self::insertPermission('customer', 'view', 'own');
        self::insertPermission('customer', 'view', 'team');
        self::insertPermission('customer', 'view', 'all');

        self::assertSame(3, DB::table('permissions')->where('resource', 'customer')->count());
    }

    public function test_that_a_role_cannot_hold_the_same_permission_twice(): void
    {
        $role = self::insertRole('Manager', 'manager');
        $permission = self::insertPermission('customer', 'view', 'all');

        self::link($role, $permission);

        $this->expectException(QueryException::class);

        self::link($role, $permission);
    }

    public function test_that_a_revoked_grant_can_be_granted_again(): void
    {
        $role = self::insertRole('Manager', 'manager');
        $permission = self::insertPermission('customer', 'view', 'all');

        $first = self::link($role, $permission);
        DB::table('role_permissions')->where('id', $first)->update(['deleted_at' => now()]);

        self::link($role, $permission);

        self::assertSame(1, DB::table('role_permissions')->whereNull('deleted_at')->count());
    }

    /**
     * A partial index that PostgreSQL created as an ordinary one would pass
     * every behavioural test above except the two archive cases — and would
     * silently be a different object. Laravel's `unique()->where()` produces
     * exactly that, which is why these are raw DDL.
     */
    #[DataProvider('partialUniqueIndexes')]
    public function test_that_the_unique_indexes_are_partial(string $index): void
    {
        $definition = DB::scalar('select indexdef from pg_indexes where indexname = ?', [$index]);

        self::assertIsString($definition, "{$index} does not exist.");
        self::assertStringContainsString('UNIQUE', $definition);
        self::assertStringContainsString('WHERE (deleted_at IS NULL)', $definition,
            "{$index} is a plain unique index. An archived row would reserve its key forever.");
    }

    // ──────────────────────────────────────────────────── referential integrity

    public function test_that_a_grant_cannot_name_a_role_that_does_not_exist(): void
    {
        $permission = self::insertPermission('customer', 'view', 'all');

        $this->expectException(QueryException::class);

        self::link(Uuid::uuid7()->toString(), $permission);
    }

    public function test_that_a_grant_cannot_name_a_permission_that_does_not_exist(): void
    {
        $role = self::insertRole('Manager', 'manager');

        $this->expectException(QueryException::class);

        self::link($role, Uuid::uuid7()->toString());
    }

    #[DataProvider('foreignKeys')]
    public function test_that_the_foreign_keys_cascade(string $constraint): void
    {
        self::assertSame(
            'CASCADE',
            DB::scalar(
                'select delete_rule from information_schema.referential_constraints where constraint_name = ?',
                [$constraint],
            ),
            "{$constraint} must cascade, so a repair cannot leave a grant pointing at nothing.",
        );
    }

    public function test_that_deleting_a_role_takes_its_grants_with_it(): void
    {
        $role = self::insertRole('Manager', 'manager');
        self::link($role, self::insertPermission('customer', 'view', 'all'));

        // A physical delete, which DB-01 forbids the application from doing.
        // The cascade exists for the cases the rules do allow — a repair or a
        // migration — and this proves it is wired rather than merely declared.
        DB::table('roles')->where('id', $role)->delete();

        self::assertSame(0, DB::table('role_permissions')->count());
    }

    // ─────────────────────────────────────────────── the matrix actually fits

    /**
     * The point of the schema, tested against the thing it exists to hold.
     *
     * Column widths chosen from a guess are how a matrix becomes a truncation
     * bug, so the whole of §3.3…§3.12 — as Point 7.2 recorded it — is written
     * into these tables and counted back out.
     */
    public function test_that_the_whole_documented_matrix_fits(): void
    {
        $permissionIds = [];
        $roleIds = [];
        $links = 0;

        foreach (PermissionMatrix::all() as $permission) {
            foreach ($permission->grants() as $roleSlug => $grant) {
                $triple = $permission->resource().'.'.$permission->action().'.'.$grant->scope()->value;

                $permissionIds[$triple] ??= self::insertPermission(
                    $permission->resource(),
                    $permission->action(),
                    $grant->scope()->value,
                );

                $roleIds[$roleSlug] ??= self::insertRole(ucfirst($roleSlug), $roleSlug);

                self::link($roleIds[$roleSlug], $permissionIds[$triple]);
                $links++;
            }
        }

        self::assertSame(count($permissionIds), DB::table('permissions')->count());
        self::assertSame($links, DB::table('role_permissions')->count());

        // Measured against the registry before the migration was written. If
        // either number moves, the matrix changed and this test should be read
        // rather than adjusted.
        self::assertSame(143, DB::table('permissions')->count());
        self::assertSame(212, DB::table('role_permissions')->count());
        self::assertSame(8, DB::table('roles')->count());
    }

    // ───────────────────────────────────────────────────────────── the down path

    public function test_down_drops_all_three_tables_and_leaves_nothing_behind(): void
    {
        // migrate:reset rather than `migrate:rollback --step 1`: --step counts
        // migrations, not batches, so the meaning of a --step test changes the
        // moment anything is added after it. That lesson is Point 6.2's.
        foreach (self::TABLES as $table) {
            self::assertTrue(Schema::hasTable($table));
        }

        Artisan::call('migrate:reset', ['--force' => true]);

        foreach (self::TABLES as $table) {
            self::assertFalse(Schema::hasTable($table), "down() must drop {$table}.");
        }

        // The indexes and the CHECK belong to the tables and go with them. A
        // leftover would collide on the next migrate rather than fail here.
        self::assertSame(0, self::leftoverIndexCount());
        self::assertNull(
            DB::scalar("select conname from pg_constraint where conname = 'permissions_scope_check'"),
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
        foreach ([
            'roles_slug_unique_alive',
            'roles_name_unique_alive',
            'permissions_triple_unique_alive',
            'role_permissions_pair_unique_alive',
        ] as $index) {
            yield $index => [$index];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function foreignKeys(): iterable
    {
        foreach (['role_permissions_role_id_foreign', 'role_permissions_permission_id_foreign'] as $key) {
            yield $key => [$key];
        }
    }

    // ───────────────────────────────────────────────────────────────── helpers

    private static function insertRole(string $name, string $slug): string
    {
        $id = Uuid::uuid7()->toString();

        DB::table('roles')->insert([
            'id' => $id,
            'name' => $name,
            'slug' => $slug,
            'is_system' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private static function insertPermission(string $resource, string $action, string $scope): string
    {
        $id = Uuid::uuid7()->toString();

        DB::table('permissions')->insert([
            'id' => $id,
            'resource' => $resource,
            'action' => $action,
            'scope' => $scope,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private static function link(string $roleId, string $permissionId): string
    {
        $id = Uuid::uuid7()->toString();

        DB::table('role_permissions')->insert([
            'id' => $id,
            'role_id' => $roleId,
            'permission_id' => $permissionId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * Laravel's own listing rather than a raw `information_schema` select. It
     * returns the columns in ordinal order — verified against the same query
     * before this was written — and it is typed, where `DB::select()` hands
     * back plain objects that level 10 will not let anyone read a property off.
     *
     * @return list<string>
     */
    private static function columns(string $table): array
    {
        $names = [];

        // getColumnListing() is declared as a bare array, so each element is
        // narrowed rather than cast — a cast would turn an unexpected shape
        // into a plausible column name and the comparison would still pass.
        foreach (Schema::getColumnListing($table) as $name) {
            self::assertIsString($name);
            $names[] = $name;
        }

        return $names;
    }

    private static function columnType(string $table, string $column): ?string
    {
        $value = DB::scalar(
            'select data_type from information_schema.columns where table_name = ? and column_name = ?',
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

    private static function nullability(string $table, string $column): ?string
    {
        $value = DB::scalar(
            'select is_nullable from information_schema.columns where table_name = ? and column_name = ?',
            [$table, $column],
        );

        return is_string($value) ? $value : null;
    }

    private static function leftoverIndexCount(): int
    {
        $value = DB::scalar(
            "select count(*) from pg_indexes where schemaname = 'public'
             and (indexname like 'roles\\_%' or indexname like 'permissions\\_%'
                  or indexname like 'role\\_permissions\\_%')",
        );

        return is_int($value) ? $value : (int) (is_numeric($value) ? $value : 0);
    }
}
