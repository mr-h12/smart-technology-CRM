<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Authentication\IdentityAuditEvents;
use App\Modules\Identity\Domain\Rbac\PermissionMatrix;
use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Domain\RoleAdministration\ReferenceListCriteria;
use App\Modules\Identity\Infrastructure\Eloquent\Permission;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Point 4.1 — §3.11's "create / edit role · permissions".
 *
 * §3.1 · §3.2 · §3.11 · §3.12 rules 1, 3 and 5 · `SEC-07` · `SEC-09` ·
 * `DB-01` · `DB-02` · `AUD-01` · `AUD-02` · `AUD-03` ·
 * `OpenAPI §4.1`, §4.2, §5, §5.1, §6.1, §6.2, §7.1, §7.2.
 *
 * ── The load-bearing test is not the CRUD ──────────────────────────────────
 *
 * §3.12 rule 5 does not say "an endpoint exists to edit permissions". It says
 * "changing this matrix is a configuration change, **not a deployment**". The
 * only way to check that claim is to take a request that was refused, grant the
 * permission through the API, re-issue the identical request in the same
 * process, and watch it succeed — and then revoke and watch it fail again.
 * Everything else in this file is scaffolding around
 * {@see self::test_a_granted_permission_takes_effect_on_the_very_next_request}.
 *
 * ── Rule 3 is checked against the document, not against this file ──────────
 *
 * The forbidden `delete` cells are counted in the mounted master documentation
 * and compared with what `PermissionMatrix::forbiddenKeys()` derives.
 * Transcribing an authorisation rule into a test and asserting the code matches
 * the transcription proves the two copies agree with each other and nothing
 * about whether either is right — this project's defect №4.
 */
final class RolePermissionManagementTest extends TestCase
{
    use RefreshDatabase;

    private const MASTER_DOCUMENTATION = '/opt/crm/docs/CRM_Documentation_EN.md';

    private const ROLES = '/api/v1/roles';

    private const PERMISSIONS = '/api/v1/permissions';

    private const PASSWORD = 'Passw0rd123';

    /** §3.11's create-user row, which Procurement does not hold. */
    private const PROBE_TRIPLE = 'admin.create_user.all';

    /** The endpoint that row guards (Point 3.2). */
    private const PROBE_ENDPOINT = '/api/v1/users';

    protected function setUp(): void
    {
        parent::setUp();

        // SEC-07: the grants come from the database, so the matrix has to be in
        // it before a single request is made. Without this every call is 403
        // and the suite would pass for the wrong reason.
        $this->seed(RolePermissionSeeder::class);
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    /** @var array<string, User> */
    private array $users = [];

    /**
     * One account per role, reused.
     *
     * Memoised because several tests need a Super Admin *and* a
     * {@see self::superAdminToken()}, and `users_email_unique_alive` refuses
     * the second insert — measured, on the first run of this file.
     */
    private function userWith(RoleName $role): User
    {
        if (isset($this->users[$role->value])) {
            return $this->users[$role->value];
        }

        $row = Role::query()->where('slug', $role->value)->firstOrFail();

        $user = new User;
        $user->fill([
            'name' => 'Test '.$role->label(),
            'email' => str_replace('_', '.', $role->value).'@example.test',
            'password' => self::PASSWORD,   // the `hashed` cast does SEC-02's half
            'role_id' => $row->id,
            'is_active' => true,
            'is_hidden' => $role->isHidden(),
        ]);
        $user->save();

        return $this->users[$role->value] = $user;
    }

    /** Logs in for real, so the token is one the login flow issued. */
    private function tokenFor(User $user): string
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertStatus(201)->json('data.token');

        self::assertIsString($token);

        return $token;
    }

    private function superAdminToken(): string
    {
        return $this->tokenFor($this->userWith(RoleName::SuperAdmin));
    }

    /** @return array<string, string> */
    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    private function roleId(RoleName $role): string
    {
        return Role::query()->where('slug', $role->value)->firstOrFail()->id;
    }

    private function permissionId(string $triple): string
    {
        [$resource, $action, $scope] = explode('.', $triple);

        return Permission::query()
            ->where('resource', $resource)
            ->where('action', $action)
            ->where('scope', $scope)
            ->firstOrFail()
            ->id;
    }

    /** The triples a role holds right now, read straight from the tables. */
    /** @return list<string> */
    private function grantedTriples(RoleName $role): array
    {
        $rows = DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_permissions.role_id', $this->roleId($role))
            ->whereNull('role_permissions.deleted_at')
            ->whereNull('permissions.deleted_at')
            ->get(['permissions.resource', 'permissions.action', 'permissions.scope']);

        $triples = [];

        foreach ($rows as $row) {
            self::assertIsString($row->resource);
            self::assertIsString($row->action);
            self::assertIsString($row->scope);

            $triples[] = $row->resource.'.'.$row->action.'.'.$row->scope;
        }

        sort($triples);

        return $triples;
    }

    /** @return list<string> */
    private function grantedIds(RoleName $role): array
    {
        $ids = DB::table('role_permissions')
            ->where('role_id', $this->roleId($role))
            ->whereNull('deleted_at')
            ->pluck('permission_id')
            ->all();

        $strings = [];

        foreach ($ids as $id) {
            if (is_string($id)) {
                $strings[] = $id;
            }
        }

        return $strings;
    }

    /** @return list<array<string, mixed>> */
    private function auditRows(string $event): array
    {
        $rows = [];

        foreach (DB::select('SELECT * FROM audit_log WHERE event = ?', [$event]) as $row) {
            /** @var array<string, mixed> $fields */
            $fields = (array) $row;
            $rows[] = $fields;
        }

        return $rows;
    }

    /** @return TestResponse<\Illuminate\Http\JsonResponse> */
    private function patchGrants(string $token, string $roleId, string ...$permissionIds): TestResponse
    {
        return $this->withHeaders($this->bearer($token))
            ->patchJson(self::ROLES.'/'.$roleId.'/permissions', ['permission_ids' => array_values($permissionIds)]);
    }

    // ── listing: the canonical roles and triples ────────────────────────────

    public function test_the_role_listing_returns_the_eight_documented_roles(): void
    {
        $response = $this->withHeaders($this->bearer($this->superAdminToken()))
            ->getJson(self::ROLES.'?per_page=100')
            ->assertStatus(200);

        $slugs = $response->json('data.*.slug');

        self::assertIsArray($slugs);
        sort($slugs);

        $expected = array_map(static fn (RoleName $role): string => $role->value, RoleName::cases());
        sort($expected);

        self::assertSame($expected, $slugs, '§3.1 names eight roles; the listing must show all of them.');
        self::assertSame(count($expected), $response->json('meta.pagination.total'));
    }

    public function test_every_seeded_role_reports_is_system_true(): void
    {
        $response = $this->withHeaders($this->bearer($this->superAdminToken()))
            ->getJson(self::ROLES.'?per_page=100')
            ->assertStatus(200);

        foreach ((array) $response->json('data') as $role) {
            self::assertIsArray($role);
            self::assertTrue($role['is_system'],
                '§3.1\'s eight are seeded is_system; a role an administrator adds later is not.');
        }
    }

    public function test_only_the_super_admin_role_is_reported_as_not_editable(): void
    {
        $response = $this->withHeaders($this->bearer($this->superAdminToken()))
            ->getJson(self::ROLES.'?per_page=100')
            ->assertStatus(200);

        $locked = [];

        foreach ((array) $response->json('data') as $role) {
            self::assertIsArray($role);

            if ($role['is_editable'] === false) {
                self::assertIsString($role['slug']);
                $locked[] = $role['slug'];
            }
        }

        self::assertSame([RoleName::SuperAdmin->value], $locked,
            '§3.12 rule 5 makes every other role editable; §3.1 makes this one answer before the rows.');
    }

    public function test_a_role_carries_the_triples_it_grants(): void
    {
        $response = $this->withHeaders($this->bearer($this->superAdminToken()))
            ->getJson(self::ROLES.'?per_page=100')
            ->assertStatus(200);

        $seen = null;

        foreach ((array) $response->json('data') as $role) {
            self::assertIsArray($role);

            if ($role['slug'] === RoleName::Procurement->value) {
                $seen = $role;
            }
        }

        self::assertIsArray($seen);
        self::assertIsArray($seen['permissions']);

        $triples = [];

        foreach ($seen['permissions'] as $permission) {
            self::assertIsArray($permission);
            self::assertIsString($permission['triple']);
            self::assertIsString($permission['id']);
            $triples[] = $permission['triple'];
        }

        sort($triples);

        self::assertSame($this->grantedTriples(RoleName::Procurement), $triples,
            'SEC-07: the payload must be what role_permissions says, not what the code remembers.');
    }

    public function test_the_permission_listing_returns_every_seeded_triple(): void
    {
        $response = $this->withHeaders($this->bearer($this->superAdminToken()))
            ->getJson(self::PERMISSIONS.'?per_page=100')
            ->assertStatus(200);

        self::assertSame(
            Permission::query()->count(),
            $response->json('meta.pagination.total'),
            '§3.2 makes the scope part of the identity, so every triple is a row and every row is listed.',
        );
    }

    public function test_a_permission_row_carries_its_triple_split_into_three_parts(): void
    {
        $response = $this->withHeaders($this->bearer($this->superAdminToken()))
            ->getJson(self::PERMISSIONS.'?filter[resource]=customer&per_page=100')
            ->assertStatus(200);

        $rows = (array) $response->json('data');

        self::assertNotSame([], $rows);

        foreach ($rows as $row) {
            self::assertIsArray($row);
            self::assertSame('customer', $row['resource']);
            self::assertIsString($row['action']);
            self::assertIsString($row['scope']);
            self::assertSame(
                'customer.'.$row['action'].'.'.$row['scope'],
                $row['triple'],
                '§3.2: "Permission = Resource + Action + Scope".',
            );
        }
    }

    // ── §3.12 rule 5: the change takes effect without a deployment ──────────

    public function test_a_granted_permission_takes_effect_on_the_very_next_request(): void
    {
        $procurement = $this->userWith(RoleName::Procurement);
        $procurementToken = $this->tokenFor($procurement);

        // Before: §3.11 gives Procurement no administration row at all.
        $this->withHeaders($this->bearer($procurementToken))
            ->getJson(self::PROBE_ENDPOINT)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'permission_denied');

        $keep = $this->grantedIds(RoleName::Procurement);
        $keep[] = $this->permissionId(self::PROBE_TRIPLE);

        $this->patchGrants($this->superAdminToken(), $this->roleId(RoleName::Procurement), ...$keep)
            ->assertStatus(200);

        // After: the identical request, in the same process, with nothing
        // restarted and no cache cleared. This is the whole of §3.12 rule 5.
        $this->withHeaders($this->bearer($procurementToken))
            ->getJson(self::PROBE_ENDPOINT)
            ->assertStatus(200);
    }

    public function test_a_revoked_permission_stops_working_on_the_very_next_request(): void
    {
        $manager = $this->userWith(RoleName::Manager);
        $managerToken = $this->tokenFor($manager);

        $this->withHeaders($this->bearer($managerToken))
            ->getJson(self::PROBE_ENDPOINT)
            ->assertStatus(200);

        $probe = $this->permissionId(self::PROBE_TRIPLE);
        $keep = array_values(array_filter(
            $this->grantedIds(RoleName::Manager),
            static fn (string $id): bool => $id !== $probe,
        ));

        $this->patchGrants($this->superAdminToken(), $this->roleId(RoleName::Manager), ...$keep)
            ->assertStatus(200);

        $this->withHeaders($this->bearer($managerToken))
            ->getJson(self::PROBE_ENDPOINT)
            ->assertStatus(403)
            ->assertJsonPath('error.details.0.code', 'unauthorized_action');
    }

    public function test_an_empty_permission_list_revokes_every_grant(): void
    {
        $this->patchGrants($this->superAdminToken(), $this->roleId(RoleName::Procurement))
            ->assertStatus(200)
            ->assertJsonPath('data.permissions', []);

        self::assertSame([], $this->grantedTriples(RoleName::Procurement),
            'An empty array is a meaningful submission: §3 writes a role that holds nothing as a column of dashes.');
    }

    public function test_a_regrant_restores_the_same_row_rather_than_inserting_a_second(): void
    {
        $roleId = $this->roleId(RoleName::Procurement);
        $probe = $this->permissionId('customer.view.asgn');

        $original = DB::table('role_permissions')
            ->where('role_id', $roleId)->where('permission_id', $probe)->first();

        self::assertNotNull($original, 'Fixture: §3.3 grants Procurement customer.view.asgn.');

        $without = array_values(array_filter(
            $this->grantedIds(RoleName::Procurement),
            static fn (string $id): bool => $id !== $probe,
        ));

        $token = $this->superAdminToken();

        $this->patchGrants($token, $roleId, ...$without)->assertStatus(200);
        $this->patchGrants($token, $roleId, ...[...$without, $probe])->assertStatus(200);

        $rows = DB::table('role_permissions')
            ->where('role_id', $roleId)->where('permission_id', $probe)->get();

        $restored = $rows->first();

        self::assertNotNull($restored);
        self::assertCount(1, $rows,
            'The partial unique index permits a duplicate once the first is soft-deleted; DB-01 wants the row restored.');
        self::assertNull($restored->deleted_at);
        self::assertSame($original->id, $restored->id, 'A restore, not a replacement.');
        self::assertSame($original->created_at, $restored->created_at,
            'DB-02: created_at records when the grant was first made, not when it was last toggled.');
    }

    // ── SEC-09 · §3.12 rule 1: enforcement at the API ───────────────────────

    /** @return array<string, array{RoleName}> */
    public static function nonSuperAdminRoles(): array
    {
        $cases = [];

        foreach (RoleName::cases() as $role) {
            if ($role->hasUnconditionalAccess()) {
                continue;
            }

            $cases[$role->value] = [$role];
        }

        return $cases;
    }

    #[DataProvider('nonSuperAdminRoles')]
    public function test_no_role_but_super_admin_may_read_the_matrix(RoleName $role): void
    {
        $token = $this->tokenFor($this->userWith($role));

        foreach ([self::ROLES, self::PERMISSIONS] as $endpoint) {
            $this->withHeaders($this->bearer($token))
                ->getJson($endpoint)
                ->assertStatus(403)
                ->assertJsonPath('error.code', 'permission_denied')
                ->assertJsonPath('error.details.0.code', 'unauthorized_action');
        }
    }

    #[DataProvider('nonSuperAdminRoles')]
    public function test_no_role_but_super_admin_may_edit_the_matrix(RoleName $role): void
    {
        $token = $this->tokenFor($this->userWith($role));

        $this->patchGrants($token, $this->roleId(RoleName::Procurement))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'permission_denied');

        self::assertNotSame([], $this->grantedTriples(RoleName::Procurement),
            'A refused call must not have written anything.');
    }

    public function test_the_manager_is_refused_even_though_it_administers_users(): void
    {
        // §3.11 gives the Manager "create user" and "deactivate user" and puts
        // a `—` in "create / edit role · permissions". D-78 mapped six user
        // endpoints onto the two rows the Manager holds; it may not be read as
        // licence to map these onto them too.
        $token = $this->tokenFor($this->userWith(RoleName::Manager));

        $this->withHeaders($this->bearer($token))->getJson(self::ROLES)->assertStatus(403);
        $this->withHeaders($this->bearer($token))->getJson(self::PERMISSIONS)->assertStatus(403);
    }

    public function test_an_unauthenticated_caller_is_401_and_not_403(): void
    {
        $this->getJson(self::ROLES)->assertStatus(401);
        $this->getJson(self::PERMISSIONS)->assertStatus(401);
        $this->patchJson(self::ROLES.'/'.$this->roleId(RoleName::Procurement).'/permissions', [
            'permission_ids' => [],
        ])->assertStatus(401);
    }

    // ── the four refusals ───────────────────────────────────────────────────

    public function test_the_super_admin_role_cannot_have_its_permissions_edited(): void
    {
        $before = $this->grantedTriples(RoleName::SuperAdmin);

        $this->patchGrants($this->superAdminToken(), $this->roleId(RoleName::SuperAdmin))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'business_rule_blocked')
            ->assertJsonPath('error.details.0.code', 'role_is_immutable');

        self::assertSame($before, $this->grantedTriples(RoleName::SuperAdmin));
    }

    public function test_revoking_every_super_admin_grant_would_have_changed_nothing_anyway(): void
    {
        // The reason the refusal above exists, demonstrated rather than
        // asserted: §3.1's unconditional access is answered before the grant
        // rows are read, so a successful revocation would have been a lie.
        DB::table('role_permissions')
            ->where('role_id', $this->roleId(RoleName::SuperAdmin))
            ->update(['deleted_at' => now()]);

        self::assertSame([], $this->grantedTriples(RoleName::SuperAdmin));

        $this->withHeaders($this->bearer($this->superAdminToken()))
            ->getJson(self::ROLES)
            ->assertStatus(200);
    }

    public function test_an_unknown_role_is_404_and_not_403(): void
    {
        $this->patchGrants($this->superAdminToken(), (string) Str::uuid7())
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found')
            ->assertJsonPath('error.details.0.code', 'role_not_found');
    }

    public function test_a_role_id_that_is_not_a_uuid_is_404_and_not_500(): void
    {
        // PostgreSQL raises `invalid input syntax for type uuid` on a bare
        // comparison, and OpenAPI §5.1 does not let a malformed identifier and
        // a missing row answer differently.
        $this->patchGrants($this->superAdminToken(), 'not-a-uuid')
            ->assertStatus(404)
            ->assertJsonPath('error.details.0.code', 'role_not_found');

        $this->withHeaders($this->bearer($this->superAdminToken()))
            ->getJson(self::ROLES.'/not-a-uuid')
            ->assertStatus(404);
    }

    public function test_an_unknown_permission_id_is_refused_and_grants_nothing(): void
    {
        $before = $this->grantedTriples(RoleName::Procurement);

        $this->patchGrants(
            $this->superAdminToken(),
            $this->roleId(RoleName::Procurement),
            $this->permissionId(self::PROBE_TRIPLE),
            (string) Str::uuid7(),
        )
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.details.0.field', 'permission_ids')
            ->assertJsonPath('error.details.0.code', 'permission_not_found');

        self::assertSame($before, $this->grantedTriples(RoleName::Procurement),
            'The valid id in the same body must not be granted on its own.');
    }

    public function test_an_unknown_role_is_reported_before_a_forbidden_grant(): void
    {
        // Order matters: a 422 that fired first would tell an unauthorised-ish
        // caller which role ids are real.
        $this->patchGrants($this->superAdminToken(), (string) Str::uuid7(), (string) Str::uuid7())
            ->assertStatus(404);
    }

    // ── §3.12 rule 3: the forbidden delete cells ────────────────────────────

    public function test_the_forbidden_cells_are_the_ones_the_document_marks_forbidden(): void
    {
        $document = file_get_contents(self::MASTER_DOCUMENTATION);

        self::assertIsString($document, 'The master documentation must be mounted at '.self::MASTER_DOCUMENTATION);

        $forbiddenRows = 0;

        foreach (explode("\n", $document) as $line) {
            if (str_starts_with($line, '| delete') && str_contains($line, '❌ Forbidden')) {
                $forbiddenRows++;
            }
        }

        self::assertGreaterThan(0, $forbiddenRows, 'The scan found nothing, which means it is not scanning.');
        self::assertCount($forbiddenRows, PermissionMatrix::forbiddenKeys(),
            '§3.12 rule 3: every merged "❌ Forbidden for every role" delete row must be underivable as a grant.');
    }

    public function test_a_forbidden_permission_cannot_be_granted_even_when_the_row_exists(): void
    {
        // The two rule 3 cells have no `permissions` row today, because the
        // seeder only writes a triple for a cell somebody holds. Inserting one
        // by hand is what turns "refused because absent" into "refused because
        // forbidden" — otherwise this test passes for the wrong reason and
        // would keep passing with the guard deleted.
        $forbidden = PermissionMatrix::forbiddenKeys();

        self::assertNotSame([], $forbidden);

        [$resource, $action] = explode('.', $forbidden[0]);

        self::assertSame(0, Permission::query()->where('resource', $resource)->where('action', $action)->count(),
            'Fixture: rule 3 cells are absent from the seeded matrix.');

        $row = new Permission;
        $row->fill(['resource' => $resource, 'action' => $action, 'scope' => 'all']);
        $row->save();

        $this->patchGrants($this->superAdminToken(), $this->roleId(RoleName::Procurement), $row->id)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'business_rule_blocked')
            ->assertJsonPath('error.details.0.field', 'permission_ids')
            ->assertJsonPath('error.details.0.code', 'grant_forbidden');

        self::assertNotContains($forbidden[0].'.all', $this->grantedTriples(RoleName::Procurement));
    }

    public function test_the_permitted_quotation_delete_is_not_caught_by_rule_three(): void
    {
        // §3.5 grants "delete (Draft only)" to four roles. A guard that matched
        // on the word `delete` rather than on the documented cell would revoke
        // a permission the document grants.
        self::assertNotContains('quotation.delete', PermissionMatrix::forbiddenKeys());

        $this->patchGrants(
            $this->superAdminToken(),
            $this->roleId(RoleName::Procurement),
            $this->permissionId('quotation.delete.own'),
        )->assertStatus(200);

        self::assertSame(['quotation.delete.own'], $this->grantedTriples(RoleName::Procurement));
    }

    // ── AUD-01: the audit row and its diff ──────────────────────────────────

    public function test_the_audit_row_records_the_exact_grant_diff(): void
    {
        $roleId = $this->roleId(RoleName::Procurement);
        $before = $this->grantedTriples(RoleName::Procurement);

        $granted = $this->permissionId(self::PROBE_TRIPLE);
        $revoked = $this->permissionId('customer.view.asgn');

        $target = array_values(array_filter(
            $this->grantedIds(RoleName::Procurement),
            static fn (string $id): bool => $id !== $revoked,
        ));
        $target[] = $granted;

        $this->patchGrants($this->superAdminToken(), $roleId, ...$target)->assertStatus(200);

        $rows = $this->auditRows(IdentityAuditEvents::ROLE_PERMISSIONS_UPDATED);

        self::assertCount(1, $rows);

        $row = $rows[0];

        self::assertSame('role', $row['entity_type']);
        self::assertSame($roleId, $row['entity_id']);

        self::assertIsString($row['old_values']);
        self::assertIsString($row['new_values']);

        /** @var array<string, mixed> $old */
        $old = json_decode($row['old_values'], true);
        /** @var array<string, mixed> $new */
        $new = json_decode($row['new_values'], true);

        self::assertSame($before, $old['permissions'], 'AUD-02: the old value is what was there.');
        self::assertSame([self::PROBE_TRIPLE], $new['granted']);
        self::assertSame(['customer.view.asgn'], $new['revoked']);
        self::assertSame($this->grantedTriples(RoleName::Procurement), $new['permissions']);
    }

    public function test_the_audit_row_names_triples_and_never_permission_ids(): void
    {
        $granted = $this->permissionId(self::PROBE_TRIPLE);

        $this->patchGrants(
            $this->superAdminToken(),
            $this->roleId(RoleName::Procurement),
            ...[...$this->grantedIds(RoleName::Procurement), $granted],
        )->assertStatus(200);

        $rows = $this->auditRows(IdentityAuditEvents::ROLE_PERMISSIONS_UPDATED);

        self::assertCount(1, $rows);
        self::assertIsString($rows[0]['new_values']);

        // AUD-03 makes the row permanent, and a permanent record built out of
        // primary keys stops being readable the first time a row is retired.
        self::assertStringNotContainsString($granted, $rows[0]['new_values']);
        self::assertStringContainsString(self::PROBE_TRIPLE, $rows[0]['new_values']);
    }

    public function test_the_audit_row_names_the_super_admin_who_made_the_change(): void
    {
        $superAdmin = $this->userWith(RoleName::SuperAdmin);

        $this->patchGrants($this->tokenFor($superAdmin), $this->roleId(RoleName::Procurement))
            ->assertStatus(200);

        $rows = $this->auditRows(IdentityAuditEvents::ROLE_PERMISSIONS_UPDATED);

        self::assertCount(1, $rows);
        self::assertSame($superAdmin->id, $rows[0]['user_id'], 'AUD-02: the actor is who acted.');
    }

    public function test_a_submission_that_changes_nothing_writes_no_audit_row(): void
    {
        $this->patchGrants(
            $this->superAdminToken(),
            $this->roleId(RoleName::Procurement),
            ...$this->grantedIds(RoleName::Procurement),
        )
            ->assertStatus(200)
            ->assertJsonPath('data.diff.changed', false)
            ->assertJsonPath('data.diff.granted', [])
            ->assertJsonPath('data.diff.revoked', []);

        self::assertSame([], $this->auditRows(IdentityAuditEvents::ROLE_PERMISSIONS_UPDATED),
            'AUD-03 makes rows permanent; a log that records a click has to be filtered before it can be read.');
    }

    public function test_a_refused_edit_writes_no_audit_row(): void
    {
        $this->patchGrants($this->superAdminToken(), $this->roleId(RoleName::SuperAdmin))->assertStatus(422);
        $this->patchGrants($this->superAdminToken(), (string) Str::uuid7())->assertStatus(404);

        self::assertSame([], $this->auditRows(IdentityAuditEvents::ROLE_PERMISSIONS_UPDATED));
    }

    // ── OpenAPI §4.2, §5, §6 ────────────────────────────────────────────────

    public function test_both_listings_carry_the_six_pagination_keys(): void
    {
        $token = $this->superAdminToken();

        foreach ([self::ROLES, self::PERMISSIONS] as $endpoint) {
            $this->withHeaders($this->bearer($token))
                ->getJson($endpoint)
                ->assertStatus(200)
                ->assertJsonStructure([
                    'data',
                    'meta' => [
                        'pagination' => [
                            'page', 'per_page', 'total', 'total_pages', 'has_next_page', 'has_previous_page',
                        ],
                        'request_id',
                    ],
                ]);
        }
    }

    public function test_the_permission_listing_is_bounded_by_the_default_page_size(): void
    {
        $response = $this->withHeaders($this->bearer($this->superAdminToken()))
            ->getJson(self::PERMISSIONS)
            ->assertStatus(200);

        $data = $response->json('data');

        self::assertIsArray($data);
        self::assertCount(ReferenceListCriteria::DEFAULT_PER_PAGE, $data,
            '§4.2: "an endpoint must never return an unbounded collection".');
        self::assertTrue($response->json('meta.pagination.has_next_page'));
    }

    /** @return array<string, array{string}> */
    public static function badQueries(): array
    {
        return [
            'per_page above the maximum' => ['?per_page=101'],
            'per_page not a whole number' => ['?per_page=1.5'],
            'page zero' => ['?page=0'],
            'unknown sort field' => ['?sort=colour'],
            'two sort fields' => ['?sort=resource,action'],
            'unknown filter' => ['?filter[colour]=red'],
        ];
    }

    #[DataProvider('badQueries')]
    public function test_a_query_that_cannot_be_honoured_is_400_and_not_422(string $query): void
    {
        // §6.2: "Reject unknown filter, sort, group, or include values with
        // 400 invalid_request; never ignore them silently."
        $this->withHeaders($this->bearer($this->superAdminToken()))
            ->getJson(self::PERMISSIONS.$query)
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'invalid_request');
    }

    public function test_the_roles_resource_declares_its_own_filters(): void
    {
        $token = $this->superAdminToken();

        // `resource` is the permissions resource's filter, not the roles one.
        $this->withHeaders($this->bearer($token))
            ->getJson(self::ROLES.'?filter[resource]=customer')
            ->assertStatus(400);

        $this->withHeaders($this->bearer($token))
            ->getJson(self::ROLES.'?filter[is_system]=false')
            ->assertStatus(200)
            ->assertJsonPath('meta.pagination.total', 0);
    }

    public function test_a_missing_permission_ids_key_is_refused_rather_than_read_as_revoke_everything(): void
    {
        $before = $this->grantedTriples(RoleName::Procurement);

        $this->withHeaders($this->bearer($this->superAdminToken()))
            ->patchJson(self::ROLES.'/'.$this->roleId(RoleName::Procurement).'/permissions', [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');

        self::assertSame($before, $this->grantedTriples(RoleName::Procurement));
    }

    public function test_the_two_reference_resources_share_openapi_section_six_ones_limits(): void
    {
        // §6.1 fixes the default at 25 and the maximum at 100 for every
        // resource. The two criteria classes declare them separately, so this
        // is the assertion that they did not drift.
        self::assertSame(
            \App\Modules\Identity\Domain\Administration\UserListCriteria::DEFAULT_PER_PAGE,
            ReferenceListCriteria::DEFAULT_PER_PAGE,
        );
        self::assertSame(
            \App\Modules\Identity\Domain\Administration\UserListCriteria::MAX_PER_PAGE,
            ReferenceListCriteria::MAX_PER_PAGE,
        );
    }

    public function test_the_endpoints_carry_openapi_section_three_threes_trace_headers(): void
    {
        // §3.3 and D-69: X-Request-Id is **server-generated** on every
        // response, X-Correlation-Id is the caller's and is repeated back. The
        // first version of this test asserted the opposite and failed, which is
        // how the distinction got read rather than remembered.
        $response = $this->withHeaders($this->bearer($this->superAdminToken()) + [
            'X-Correlation-Id' => 'point-4-1-probe',
        ])->getJson(self::ROLES)->assertStatus(200);

        self::assertSame('point-4-1-probe', $response->headers->get('X-Correlation-Id'));

        $requestId = $response->headers->get('X-Request-Id');

        self::assertIsString($requestId);
        self::assertNotSame('', $requestId);
        self::assertSame($requestId, $response->json('meta.request_id'),
            'OpenAPI §4.1: meta.request_id and the header are one value, not two.');
    }
}
