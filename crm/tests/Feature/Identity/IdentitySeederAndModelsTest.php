<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Personas\TestPersonas;
use App\Modules\Identity\Domain\Rbac\PermissionMatrix;
use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Permission;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Support\Seeding\ProductionSeedRefused;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * Point 2.1 — the models, and the seeders that fill the tables Step 1 built.
 *
 * The counts below — 8, 144, 217 — are not typed in as expectations (57 rows
 * + D-80's `currency.view`: 144 explicit scopes, 217 grants). Each is
 * derived from `PermissionMatrix` inside the test and compared against the
 * database, and only then compared against the literal, so a matrix that grows
 * fails loudly rather than quietly disagreeing with a number nobody re-derived.
 *
 * The trap this file is built around is the one `SeederContractTest` names: **a
 * seeder that does nothing is trivially idempotent.** So the idempotency test
 * asserts the rows exist first, and compares identifiers and `created_at`
 * rather than only counts — a seeder that deleted and re-inserted everything
 * would keep the count and fail here, which is the point.
 */
final class IdentitySeederAndModelsTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Passw0rd123';

    protected function setUp(): void
    {
        parent::setUp();

        // config(), not putenv(): the seeder reads config/seeding.php, because
        // env() returns null once config:cache has run.
        Config::set('seeding.test_user_password', self::PASSWORD);
    }

    // ──────────────────────────────────────────────── what the matrix implies

    public function test_that_the_seeder_writes_exactly_what_section_3_defines(): void
    {
        $this->seedMatrix();

        [$triples, $grants] = self::expectedFromMatrix();

        self::assertSame(count(RoleName::cases()), Role::count());
        self::assertSame(count($triples), Permission::count());
        self::assertSame($grants, DB::table('role_permissions')->count());

        // The literals, second. If the matrix changes these fail together and
        // the disagreement is visible rather than absorbed.
        self::assertSame(8, Role::count());
        self::assertSame(144, Permission::count());
        self::assertSame(217, DB::table('role_permissions')->count());
    }

    /**
     * Counting is not the same as being right. This compares the actual set of
     * `resource.action.scope` strings, so a seeder writing 144 of the wrong
     * rows fails.
     */
    public function test_that_every_written_triple_is_one_the_matrix_names(): void
    {
        $this->seedMatrix();

        [$expected] = self::expectedFromMatrix();

        $stored = Permission::all()
            ->map(static fn (Permission $permission): string => $permission->triple())
            ->sort()
            ->values()
            ->all();

        sort($expected);

        self::assertSame($expected, $stored);
    }

    public function test_that_the_role_names_are_the_ones_section_3_1_lists(): void
    {
        $this->seedMatrix();

        // Read out of the §3.1 table rather than restated — the same pin Point
        // 1.1 used for DB-02. /u because the file is bilingual.
        $documentation = file_get_contents('/opt/crm/docs/CRM_Documentation_EN.md');
        self::assertIsString($documentation);

        foreach (RoleName::cases() as $role) {
            $label = $role->label();

            self::assertMatchesRegularExpression(
                '/^\|\s*'.preg_quote($label, '/').'\s*\|/mu',
                $documentation,
                "The role name '{$label}' does not appear as a row in the §3.1 table.",
            );

            self::assertSame($label, Role::where('slug', $role->value)->value('name'));
        }
    }

    // ───────────────────────────────────────────────────────────── idempotency

    public function test_that_running_the_matrix_seeder_twice_changes_nothing(): void
    {
        $this->seedMatrix();

        // A seeder that did nothing would pass every comparison below, so the
        // rows are asserted present before anything is compared.
        self::assertSame(144, Permission::count());
        self::assertSame(217, DB::table('role_permissions')->count());

        $before = self::snapshot();

        $this->seedMatrix();

        self::assertSame($before, self::snapshot(),
            'The second run changed identifiers or creation times — that is re-creation, not idempotency.');
    }

    public function test_that_running_the_user_seeder_twice_changes_nothing(): void
    {
        $this->seedMatrix();
        $this->seedUsers();

        self::assertSame(8, User::count());

        $before = self::userSnapshot();

        $this->seedUsers();

        self::assertSame(8, User::count());
        self::assertSame($before, self::userSnapshot());
    }

    /**
     * `DB-01` archives rather than deletes, and the canonical matrix must be
     * present. So a re-run restores a role somebody archived, rather than
     * leaving a documented role missing or creating a duplicate beside it —
     * which the partial unique index would happily allow.
     */
    public function test_that_a_rerun_restores_an_archived_canonical_role(): void
    {
        $this->seedMatrix();

        Role::where('slug', RoleName::Manager->value)->delete();
        self::assertSame(7, Role::count());

        $this->seedMatrix();

        self::assertSame(8, Role::count());
        self::assertNotNull(Role::where('slug', RoleName::Manager->value)->first());
    }

    // ──────────────────────────────────────────────────────── the test personas

    public function test_that_the_eight_personas_are_seeded(): void
    {
        $this->seedMatrix();
        $this->seedUsers();

        self::assertSame(count(TestPersonas::all()), User::count());
        self::assertSame(8, User::count());

        foreach (TestPersonas::all() as $persona) {
            $user = User::where('email', $persona->email())->first();

            self::assertNotNull($user, "{$persona->email()} was not seeded.");
            self::assertSame($persona->name(), $user->name);
            $role = $user->role;
            self::assertNotNull($role);
            self::assertSame($persona->role()->value, $role->slug);
            self::assertTrue($user->is_active);
        }
    }

    public function test_that_the_seeded_password_is_hashed_and_verifies(): void
    {
        $this->seedMatrix();
        $this->seedUsers();

        $user = User::where('email', 'manager@example.test')->firstOrFail();

        self::assertNotSame(self::PASSWORD, $user->password, 'SEC-02: the password is stored in the clear.');
        self::assertTrue(Hash::check(self::PASSWORD, $user->password));
    }

    /** §3.12 rule 6 — hidden, and hidden alone. */
    public function test_that_only_the_super_admin_is_hidden(): void
    {
        $this->seedMatrix();
        $this->seedUsers();

        self::assertSame(1, User::where('is_hidden', true)->count());
        self::assertSame(
            'super.admin@example.test',
            User::where('is_hidden', true)->value('email'),
        );
    }

    // ───────────────────────────────────────────────────────── the guards

    public function test_that_test_users_are_refused_in_production(): void
    {
        $this->seedMatrix();
        $this->app->instance('env', 'production');

        try {
            $this->seedUsers();
            self::fail('UserSeeder ran in production.');
        } catch (ProductionSeedRefused $refused) {
            self::assertStringContainsString('production', $refused->getMessage());
        }

        self::assertSame(0, User::count(), 'Rows were written before the guard fired.');
    }

    /**
     * `RolePermissionSeeder` is the opposite case and must **not** be blocked:
     * `SEC-07` puts the matrix in the database, so production needs it.
     */
    public function test_that_the_matrix_seeder_is_not_blocked_in_production(): void
    {
        $this->app->instance('env', 'production');

        $this->seedMatrix();

        self::assertSame(144, Permission::count());
    }

    public function test_that_a_missing_password_stops_the_seeder(): void
    {
        $this->seedMatrix();
        Config::set('seeding.test_user_password', null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(UserSeeder::PASSWORD_KEY);

        $this->seedUsers();
    }

    /** `D-28`: at least eight characters, letters and numbers. */
    public function test_that_a_password_the_system_would_reject_is_refused(): void
    {
        $this->seedMatrix();
        Config::set('seeding.test_user_password', 'short1');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('D-28');

        $this->seedUsers();
    }

    public function test_that_users_cannot_be_seeded_before_their_roles(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RolePermissionSeeder must run before UserSeeder');

        $this->seedUsers();
    }

    // ──────────────────────────────────────────────────────── the relations

    public function test_that_a_role_reaches_its_permissions_and_back(): void
    {
        $this->seedMatrix();

        $manager = Role::where('slug', RoleName::Manager->value)->firstOrFail();
        $permissions = $manager->permissions;

        self::assertGreaterThan(0, $permissions->count());

        $first = $permissions->first();
        self::assertNotNull($first);

        // The inverse, from the same row back to the same role.
        self::assertContains(
            $manager->id,
            $first->roles->pluck('id')->all(),
            'permissions() and roles() do not describe the same pivot.',
        );
    }

    public function test_that_the_grant_count_matches_the_relation(): void
    {
        $this->seedMatrix();

        $throughRelations = 0;

        foreach (Role::all() as $role) {
            $throughRelations += $role->permissions->count();
        }

        self::assertSame(217, $throughRelations,
            'Walking the relation finds a different number of grants than the table holds.');
    }

    public function test_that_a_user_reaches_its_role_and_sessions(): void
    {
        $this->seedMatrix();
        $this->seedUsers();

        $user = User::where('email', 'indoor.sales@example.test')->firstOrFail();

        $role = $user->role;
        self::assertNotNull($role);

        self::assertSame(RoleName::IndoorSales->value, $role->slug);
        self::assertContains($user->id, $role->users->pluck('id')->all());

        self::assertCount(0, $user->sessions);

        $user->sessions()->create([
            'session_id' => 'sess-probe',
            'ip_address' => '::ffff:192.0.2.1',
            'user_agent' => 'probe',
            'last_activity_at' => now(),
        ]);

        $reloaded = $user->fresh();
        self::assertNotNull($reloaded);
        self::assertCount(1, $reloaded->sessions);
    }

    /** A revoked device leaves the list §3 shows, without leaving the table. */
    public function test_that_a_revoked_session_drops_out_of_the_relation(): void
    {
        $this->seedMatrix();
        $this->seedUsers();

        $user = User::where('email', 'indoor.sales@example.test')->firstOrFail();
        $session = $user->sessions()->create([
            'session_id' => 'sess-probe',
            'last_activity_at' => now(),
        ]);

        $session->delete();

        $reloaded = $user->fresh();
        self::assertNotNull($reloaded);
        self::assertCount(0, $reloaded->sessions);
        self::assertSame(1, DB::table('user_sessions')->count());
    }

    public function test_that_the_system_and_custom_scopes_separate_the_two_kinds(): void
    {
        $this->seedMatrix();

        self::assertSame(8, Role::system()->count());
        self::assertSame(0, Role::custom()->count());

        Role::create(['name' => 'Auditor', 'slug' => 'auditor', 'is_system' => false]);

        self::assertSame(8, Role::system()->count());
        self::assertSame(1, Role::custom()->count());
    }

    // ───────────────────────────────────────────────────────────────── helpers

    private function seedMatrix(): void
    {
        $this->app->make(RolePermissionSeeder::class)->run($this->app);
    }

    private function seedUsers(): void
    {
        $this->app->make(UserSeeder::class)->run($this->app);
    }

    /**
     * Derived from the registry, so the expectations move when §3 does.
     *
     * @return array{list<string>, int} the distinct triples, and the grant count
     */
    private static function expectedFromMatrix(): array
    {
        $triples = [];
        $grants = 0;

        foreach (PermissionMatrix::all() as $permission) {
            foreach ($permission->grants() as $grant) {
                $triples[$permission->resource().'.'.$permission->action().'.'.$grant->scope()->value] = true;
                $grants++;
            }
        }

        return [array_keys($triples), $grants];
    }

    /**
     * Identifiers and creation times, not counts.
     *
     * A seeder that truncated and re-inserted would hold every count steady
     * and change every id — which is the failure this shape catches and a
     * count comparison does not.
     *
     * @return array<string, mixed>
     */
    private static function snapshot(): array
    {
        // Rows as plain arrays. `get()` hands back stdClass instances, and
        // assertSame on those compares object *identity* — two reads of an
        // unchanged table would fail while two reads of a rewritten one would
        // fail identically, so the assertion would prove nothing either way.
        return [
            'roles' => self::rows(
                DB::table('roles')->orderBy('slug')->get(['id', 'slug', 'name', 'created_at']),
            ),
            'permissions' => self::rows(
                DB::table('permissions')
                    ->orderBy('resource')->orderBy('action')->orderBy('scope')
                    ->get(['id', 'resource', 'action', 'scope', 'created_at']),
            ),
            'grants' => self::rows(
                DB::table('role_permissions')
                    ->orderBy('role_id')->orderBy('permission_id')
                    ->get(['id', 'role_id', 'permission_id', 'created_at']),
            ),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function userSnapshot(): array
    {
        return self::rows(
            DB::table('users')->orderBy('email')
                ->get(['id', 'email', 'name', 'role_id', 'is_hidden', 'created_at']),
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \stdClass>  $rows
     * @return list<array<string, mixed>>
     */
    private static function rows(\Illuminate\Support\Collection $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $columns */
            $columns = (array) $row;
            $out[] = $columns;
        }

        return $out;
    }
}
