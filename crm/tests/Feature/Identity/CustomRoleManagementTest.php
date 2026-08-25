<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Administration\RoleAssignmentPolicy;
use App\Modules\Identity\Domain\Authentication\IdentityAuditEvents;
use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Permission;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Point 4.2 — §13 screen 3's "create new roles", and the role picker §3.11's
 * create-user grant needed all along.
 *
 * §3.1 · §3.11 · §3.12 rules 1, 3, 5 and 7 · §13 screen 3 · `SEC-07` ·
 * `SEC-09` · `DB-01` · `AUD-01` · `AUD-02` · `AUD-03` ·
 * `OpenAPI §4.1`, §4.2, §5.1, §6.1, §7.1.
 *
 * ── What this file is actually about ───────────────────────────────────────
 *
 * §3.12 rule 5 says a ninth role is "a configuration change, not a deployment".
 * Point 4.1 made that true of a role's *grants*; until this point there was no
 * way to bring the ninth role into existence at all, so rule 5's own example
 * could not be performed. The load-bearing test is therefore
 * {@see self::test_a_role_created_through_the_api_authorises_on_the_next_request}
 * — create a role, grant it something, assign a user, and watch the endpoint
 * that refused them a moment ago answer 200.
 *
 * The rest guards the three ways this endpoint could quietly break the system:
 * a system role renamed or archived out from under the seeder, a role archived
 * with people still on it, and a Manager shown roles §3.12 rule 7 forbids them.
 */
final class CustomRoleManagementTest extends TestCase
{
    use RefreshDatabase;

    private const ROLES = '/api/v1/roles';

    private const USERS = '/api/v1/users';

    private const PASSWORD = 'Passw0rd123';

    protected function setUp(): void
    {
        parent::setUp();

        // SEC-07: the grants come from the database, so the matrix has to be in
        // it before a single request is made.
        $this->seed(RolePermissionSeeder::class);
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    /** @var array<string, User> */
    private array $users = [];

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
            'password' => self::PASSWORD,
            'role_id' => $row->id,
            'is_active' => true,
            'is_hidden' => $role->isHidden(),
        ]);
        $user->save();

        return $this->users[$role->value] = $user;
    }

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

    /**
     * `POST /roles` as the Super Admin.
     *
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<\Illuminate\Http\JsonResponse>
     */
    private function createRole(array $overrides = [], ?string $token = null): TestResponse
    {
        return $this->withHeaders($this->bearer($token ?? $this->superAdminToken()))
            ->postJson(self::ROLES, $overrides + [
                'slug' => 'auditor',
                'name' => 'Auditor',
            ]);
    }

    /**
     * The id of a role created through the endpoint, narrowed for PHPStan.
     *
     * @param  TestResponse<\Illuminate\Http\JsonResponse>  $response
     */
    private function createdRoleId(TestResponse $response): string
    {
        $id = $response->json('data.id');

        self::assertIsString($id);

        return $id;
    }

    /** @return list<string> the `slug` of every role on the first page */
    private function listedSlugs(string $token): array
    {
        $rows = $this->withHeaders($this->bearer($token))
            ->getJson(self::ROLES.'?per_page=100')
            ->assertStatus(200)
            ->json('data');

        self::assertIsArray($rows);

        $slugs = [];

        foreach ($rows as $row) {
            self::assertIsArray($row);
            /** @var array<string, mixed> $row */
            $slug = $row['slug'] ?? null;

            self::assertIsString($slug);

            $slugs[] = $slug;
        }

        sort($slugs);

        return $slugs;
    }

    /** @return list<array<string, mixed>> */
    private function auditRows(string $event): array
    {
        $rows = DB::table('audit_log')->where('event', $event)->get();

        $decoded = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $columns */
            $columns = (array) $row;

            $decoded[] = $columns;
        }

        return $decoded;
    }

    // ── §13 screen 3: create ────────────────────────────────────────────────

    public function test_the_super_admin_creates_a_role_and_it_is_not_a_system_role(): void
    {
        $this->createRole(['name_ar' => 'مدقّق', 'description' => 'Read-only reviewer'])
            ->assertStatus(201)
            ->assertJsonPath('data.slug', 'auditor')
            ->assertJsonPath('data.name', 'Auditor')
            ->assertJsonPath('data.name_ar', 'مدقّق')
            ->assertJsonPath('data.description', 'Read-only reviewer')
            // §3.1's eight are the system's; anything added here is not.
            ->assertJsonPath('data.is_system', false)
            // §3.12 rule 5: a new role's matrix is editable from the first day.
            ->assertJsonPath('data.is_editable', true)
            ->assertJsonPath('data.permissions', [])
            ->assertJsonStructure(['data' => ['id'], 'meta' => ['request_id']]);

        $row = Role::query()->where('slug', 'auditor')->firstOrFail();

        self::assertFalse($row->is_system, 'A client must never be able to mint a system role.');
    }

    public function test_a_role_may_be_created_holding_permissions(): void
    {
        $response = $this->createRole([
            'permission_ids' => [
                $this->permissionId('customer.view.all'),
                $this->permissionId('deal.view.all'),
            ],
        ])->assertStatus(201);

        $triples = $response->json('data.permissions.*.triple');

        self::assertIsArray($triples);
        self::assertEqualsCanonicalizing(['customer.view.all', 'deal.view.all'], $triples);
    }

    public function test_a_created_role_is_audited_with_triples_and_never_ids(): void
    {
        $permissionId = $this->permissionId('customer.view.all');

        $this->createRole(['permission_ids' => [$permissionId]])->assertStatus(201);

        $rows = $this->auditRows(IdentityAuditEvents::ROLE_CREATED);

        self::assertCount(1, $rows, 'AUD-01 covers create; a role that appears with no record is unattributable.');

        $encoded = json_encode($rows[0] ?? []);

        self::assertIsString($encoded);
        self::assertStringContainsString('customer.view.all', $encoded);
        self::assertStringNotContainsString($permissionId, $encoded,
            'AUD-03 makes the row permanent, and a record built out of primary keys stops being readable.');
        self::assertStringContainsString('auditor', $encoded);
    }

    public function test_a_refused_creation_writes_nothing(): void
    {
        $this->createRole(['slug' => 'manager'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.details.0.field', 'slug')
            ->assertJsonPath('error.details.0.code', 'slug_already_taken');

        self::assertSame([], $this->auditRows(IdentityAuditEvents::ROLE_CREATED));
        self::assertSame(8, Role::query()->count(), '§3.1 has eight roles and a refusal added none.');
    }

    public function test_a_duplicate_label_is_refused_on_the_field_that_carries_it(): void
    {
        $this->createRole(['slug' => 'auditor', 'name' => 'Procurement'])
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'name')
            ->assertJsonPath('error.details.0.code', 'name_already_taken');

        $this->createRole(['name_ar' => 'مدقّق'])->assertStatus(201);

        $this->createRole(['slug' => 'reviewer', 'name' => 'Reviewer', 'name_ar' => 'مدقّق'])
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'name_ar')
            ->assertJsonPath('error.details.0.code', 'name_ar_already_taken');
    }

    public function test_a_malformed_slug_is_refused_before_anything_is_written(): void
    {
        foreach (['Auditor', 'audit or', '9auditor', 'a', 'audit-or', ''] as $slug) {
            $this->createRole(['slug' => $slug])
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'validation_failed');
        }

        self::assertSame(8, Role::query()->count());
    }

    public function test_a_forbidden_grant_cannot_be_smuggled_in_through_creation(): void
    {
        // §3.12 rule 3 overrides the matrix, so a create endpoint that could
        // grant a forbidden action would be the way around the rule the edit
        // endpoint enforces. There is no `customer.delete.*` row to submit —
        // the seeder never creates one — so the refusal that fires is the
        // unresolved id, and that is the point: neither path grants it.
        $this->createRole(['permission_ids' => ['0199a0f2-0000-7000-8000-000000000000']])
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.code', 'permission_not_found');

        self::assertSame(8, Role::query()->count());
    }

    // ── the load-bearing one: §3.12 rule 5 end to end ───────────────────────

    public function test_a_role_created_through_the_api_authorises_on_the_next_request(): void
    {
        // 1. A brand-new role, created over HTTP, holding one triple: the row
        //    §3.11 uses for `GET /users`.
        $created = $this->createRole([
            'permission_ids' => [$this->permissionId('admin.create_user.all')],
        ])->assertStatus(201);

        $roleId = $this->createdRoleId($created);

        // 2. Somebody assigned to it, created through the API as well, so the
        //    whole path is the one a real administrator would walk.
        $this->withHeaders($this->bearer($this->superAdminToken()))
            ->postJson(self::USERS, [
                'name' => 'New Auditor',
                'email' => 'new.auditor@example.test',
                'password' => self::PASSWORD,
                'role_id' => $roleId,
            ])->assertStatus(201);

        $token = $this->tokenFor(User::query()->where('email', 'new.auditor@example.test')->firstOrFail());

        // 3. The endpoint answers, on a role that did not exist a moment ago
        //    and with nothing deployed and nothing restarted.
        $this->withHeaders($this->bearer($token))->getJson(self::USERS)->assertStatus(200);

        // 4. And revoking it takes the access away again, in the same process.
        $this->withHeaders($this->bearer($this->superAdminToken()))
            ->patchJson(self::ROLES.'/'.$roleId.'/permissions', ['permission_ids' => []])
            ->assertStatus(200);

        $this->withHeaders($this->bearer($token))->getJson(self::USERS)->assertStatus(403);
    }

    // ── §3.11: edit ─────────────────────────────────────────────────────────

    public function test_the_labels_and_description_are_editable(): void
    {
        $roleId = $this->createdRoleId($this->createRole()->assertStatus(201));

        $this->withHeaders($this->bearer($this->superAdminToken()))
            ->patchJson(self::ROLES.'/'.$roleId, [
                'name' => 'Internal Auditor',
                'name_ar' => 'مدقّق داخلي',
                'description' => 'Reviews closed deals',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'Internal Auditor')
            ->assertJsonPath('data.name_ar', 'مدقّق داخلي')
            ->assertJsonPath('data.slug', 'auditor');

        $rows = $this->auditRows(IdentityAuditEvents::ROLE_UPDATED);

        self::assertCount(1, $rows, 'AUD-01 covers update.');
    }

    public function test_the_slug_cannot_be_changed(): void
    {
        // `Role::tryFrom()` matches a database row to §3.1 by slug, and
        // `RoleAssignmentPolicy::permitsSlug()` decides rule 7 from it. A
        // rename would silently re-answer an authorisation question.
        $roleId = $this->createdRoleId($this->createRole()->assertStatus(201));

        $this->withHeaders($this->bearer($this->superAdminToken()))
            ->patchJson(self::ROLES.'/'.$roleId, ['slug' => 'sneaky', 'name' => 'Renamed'])
            ->assertStatus(200)
            ->assertJsonPath('data.slug', 'auditor');

        self::assertSame('auditor', Role::query()->whereKey($roleId)->firstOrFail()->slug);
    }

    public function test_a_patch_that_changes_nothing_writes_no_audit_row(): void
    {
        $roleId = $this->createdRoleId($this->createRole()->assertStatus(201));

        $this->withHeaders($this->bearer($this->superAdminToken()))
            ->patchJson(self::ROLES.'/'.$roleId, ['name' => 'Auditor'])
            ->assertStatus(200);

        // AUD-03 makes rows permanent, so a screen that re-submits its form
        // would otherwise fill the log with entries that record a click.
        self::assertSame([], $this->auditRows(IdentityAuditEvents::ROLE_UPDATED));
    }

    public function test_an_empty_patch_is_refused(): void
    {
        $roleId = $this->createdRoleId($this->createRole()->assertStatus(201));

        $this->withHeaders($this->bearer($this->superAdminToken()))
            ->patchJson(self::ROLES.'/'.$roleId, [])
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.code', 'no_fields_submitted');
    }

    public function test_no_system_role_may_be_renamed(): void
    {
        // All eight, not just the Super Admin: `RolePermissionSeeder` rewrites
        // `name` from `Role::label()` on every run, so a rename here survives
        // until the next deployment and then reverts with no record of why.
        foreach (RoleName::cases() as $role) {
            $this->withHeaders($this->bearer($this->superAdminToken()))
                ->patchJson(self::ROLES.'/'.$this->roleId($role), ['name' => 'Renamed '.$role->value])
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'business_rule_blocked')
                ->assertJsonPath('error.details.0.code', 'system_role_cannot_be_edited');

            self::assertSame(
                $role->label(),
                Role::query()->where('slug', $role->value)->firstOrFail()->name,
                'A refused rename must not have written anything.',
            );
        }
    }

    // ── archive ─────────────────────────────────────────────────────────────

    public function test_a_custom_role_is_archived_and_not_deleted(): void
    {
        $roleId = $this->createdRoleId(
            $this->createRole(['permission_ids' => [$this->permissionId('customer.view.all')]])->assertStatus(201)
        );

        $this->withHeaders($this->bearer($this->superAdminToken()))
            ->deleteJson(self::ROLES.'/'.$roleId)
            ->assertStatus(200)
            ->assertJsonPath('data.archived', true)
            ->assertJsonPath('data.role_id', $roleId)
            ->assertJsonStructure(['meta' => ['request_id']]);

        // DB-01: the row is still there, carrying deleted_at.
        $row = Role::withTrashed()->whereKey($roleId)->firstOrFail();

        self::assertNotNull($row->deleted_at, 'DB-01 forbids physically deleting business data.');

        // Its grants went with it: a live grant pointing at an archived role is
        // one no screen shows and no listing can revoke.
        $live = DB::table('role_permissions')
            ->where('role_id', $roleId)
            ->whereNull('deleted_at')
            ->count();

        self::assertSame(0, $live);

        $this->withHeaders($this->bearer($this->superAdminToken()))
            ->getJson(self::ROLES.'/'.$roleId)
            ->assertStatus(404);
    }

    public function test_the_archive_is_audited_with_what_the_role_was(): void
    {
        $roleId = $this->createdRoleId(
            $this->createRole(['permission_ids' => [$this->permissionId('customer.view.all')]])->assertStatus(201)
        );

        $this->withHeaders($this->bearer($this->superAdminToken()))
            ->deleteJson(self::ROLES.'/'.$roleId)
            ->assertStatus(200);

        $rows = $this->auditRows(IdentityAuditEvents::ROLE_ARCHIVED);

        self::assertCount(1, $rows);

        $encoded = json_encode($rows[0] ?? []);

        self::assertIsString($encoded);
        // AUD-02's old values: the archived row is the only other copy.
        self::assertStringContainsString('customer.view.all', $encoded);
        self::assertStringContainsString('auditor', $encoded);
    }

    public function test_no_system_role_may_be_archived(): void
    {
        foreach (RoleName::cases() as $role) {
            $this->withHeaders($this->bearer($this->superAdminToken()))
                ->deleteJson(self::ROLES.'/'.$this->roleId($role))
                ->assertStatus(422)
                ->assertJsonPath('error.details.0.code', 'system_role_cannot_be_deleted');
        }

        self::assertSame(8, Role::query()->count());
        self::assertSame([], $this->auditRows(IdentityAuditEvents::ROLE_ARCHIVED));
    }

    public function test_a_role_somebody_holds_cannot_be_archived(): void
    {
        $roleId = $this->createdRoleId($this->createRole()->assertStatus(201));

        $this->withHeaders($this->bearer($this->superAdminToken()))
            ->postJson(self::USERS, [
                'name' => 'New Auditor',
                'email' => 'new.auditor@example.test',
                'password' => self::PASSWORD,
                'role_id' => $roleId,
            ])->assertStatus(201);

        // `users.role_id` is NOT NULL, so archiving would leave the account
        // pointing at a row no listing returns and `AuthorizeAction` would
        // answer denied for everything they try.
        $this->withHeaders($this->bearer($this->superAdminToken()))
            ->deleteJson(self::ROLES.'/'.$roleId)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'business_rule_blocked')
            ->assertJsonPath('error.details.0.code', 'role_has_assigned_users');

        self::assertNull(Role::query()->whereKey($roleId)->firstOrFail()->deleted_at);
        self::assertSame([], $this->auditRows(IdentityAuditEvents::ROLE_ARCHIVED));
    }

    public function test_an_archived_account_does_not_hold_a_role_open(): void
    {
        $roleId = $this->createdRoleId($this->createRole()->assertStatus(201));

        $user = new User;
        $user->fill([
            'name' => 'Departed',
            'email' => 'departed@example.test',
            'password' => self::PASSWORD,
            'role_id' => $roleId,
            'is_active' => false,
            'is_hidden' => false,
        ]);
        $user->save();
        $user->delete();   // DB-01's soft delete

        $this->withHeaders($this->bearer($this->superAdminToken()))
            ->deleteJson(self::ROLES.'/'.$roleId)
            ->assertStatus(200);
    }

    public function test_an_unknown_role_is_404_on_every_write(): void
    {
        $missing = '0199a0f2-0000-7000-8000-000000000000';

        // `Xy` and not `X`: the Form Request runs before the use case, so a
        // one-character name is a 422 about the field and never reaches the
        // lookup. Measured — the first version of this test asserted 404 and
        // got 422, which is the shape check doing its job rather than a defect.
        foreach ([['patch', ['name' => 'Xy']], ['delete', []]] as [$verb, $body]) {
            $this->withHeaders($this->bearer($this->superAdminToken()))
                ->json(strtoupper($verb), self::ROLES.'/'.$missing, $body)
                ->assertStatus(404)
                ->assertJsonPath('error.code', 'resource_not_found');
        }

        // Not a UUID at all — a 404, never the 500 PostgreSQL would raise.
        $this->withHeaders($this->bearer($this->superAdminToken()))
            ->deleteJson(self::ROLES.'/not-a-uuid')
            ->assertStatus(404);
    }

    // ── the role picker §3.11's create-user grant always needed ─────────────

    public function test_the_manager_sees_only_the_roles_rule_seven_permits(): void
    {
        $token = $this->tokenFor($this->userWith(RoleName::Manager));

        $expected = [];

        foreach (RoleAssignmentPolicy::assignableBy(RoleName::Manager) as $role) {
            $expected[] = $role->value;
        }

        sort($expected);

        self::assertSame($expected, $this->listedSlugs($token));

        // Said the other way round, because the list above could be right by
        // accident: the three §3.12 rule 7 names are absent, and so is the
        // account §3.12 rule 6 hides.
        foreach (RoleAssignmentPolicy::FORBIDDEN_TO_MANAGER as $slug) {
            self::assertNotContains($slug, $this->listedSlugs($token));
        }

        self::assertNotContains('team_leader', $this->listedSlugs($token),
            'D-78 settled §3.11 against rule 7: only the Super Admin creates a Team Leader.');
    }

    public function test_the_super_admin_sees_every_role_including_a_new_one(): void
    {
        $this->createRole()->assertStatus(201);

        $slugs = $this->listedSlugs($this->superAdminToken());

        self::assertCount(9, $slugs, '§3.1 has eight and rule 5 just added a ninth.');
        self::assertContains('super_admin', $slugs);
        self::assertContains('auditor', $slugs);
    }

    public function test_a_custom_role_is_not_offered_to_the_manager(): void
    {
        // §3.12 rule 5 makes a ninth role legitimate and rule 7 has no entry for
        // it. `RoleAssignmentPolicy::permitsSlug()` fails closed — only the
        // Super Admin may confer it — so the picker must not show it either, or
        // the Manager sees an option the use case will refuse.
        $this->createRole()->assertStatus(201);

        self::assertNotContains('auditor', $this->listedSlugs($this->tokenFor($this->userWith(RoleName::Manager))));
    }

    public function test_the_narrowed_listing_pages_over_what_the_caller_may_see(): void
    {
        // OpenAPI §6.1: "pagination always happens after authorization
        // scoping". A total counting rows the caller may not see is itself a
        // disclosure, and short pages are the visible symptom.
        $token = $this->tokenFor($this->userWith(RoleName::Manager));

        $this->withHeaders($this->bearer($token))
            ->getJson(self::ROLES.'?per_page=2')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.pagination.total', count(RoleAssignmentPolicy::MANAGER_MAY_CREATE))
            ->assertJsonPath('meta.pagination.total_pages', 2)
            ->assertJsonPath('meta.pagination.has_next_page', true);
    }

    public function test_the_manager_still_cannot_reach_a_role_it_was_not_shown(): void
    {
        // §3.12 rule 1: the narrowed listing is a picker, not the enforcement.
        // A Manager who guesses the Manager role's id must still be refused by
        // the use case.
        $token = $this->tokenFor($this->userWith(RoleName::Manager));

        $this->withHeaders($this->bearer($token))
            ->postJson(self::USERS, [
                'name' => 'Another Manager',
                'email' => 'another.manager@example.test',
                'password' => self::PASSWORD,
                'role_id' => $this->roleId(RoleName::Manager),
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.code', 'role_not_assignable');
    }

    // ── SEC-09 · §3.12 rule 1 ───────────────────────────────────────────────

    public function test_a_role_with_no_administration_row_cannot_create_a_role(): void
    {
        $token = $this->tokenFor($this->userWith(RoleName::Procurement));

        $this->createRole([], $token)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'permission_denied');

        self::assertSame(8, Role::query()->count());
    }

    public function test_an_unauthenticated_caller_is_401_and_not_403(): void
    {
        $this->postJson(self::ROLES, ['slug' => 'auditor', 'name' => 'Auditor'])->assertStatus(401);
        $this->patchJson(self::ROLES.'/'.$this->roleId(RoleName::Procurement), ['name' => 'X'])->assertStatus(401);
        $this->deleteJson(self::ROLES.'/'.$this->roleId(RoleName::Procurement))->assertStatus(401);
    }

    // ── §14.2: both languages ───────────────────────────────────────────────

    public function test_the_arabic_label_is_what_an_arabic_caller_is_shown(): void
    {
        $roleId = $this->createdRoleId($this->createRole(['name_ar' => 'مدقّق'])->assertStatus(201));

        $this->withHeaders($this->bearer($this->superAdminToken()) + ['Accept-Language' => 'ar'])
            ->getJson(self::ROLES.'/'.$roleId)
            ->assertStatus(200)
            ->assertJsonPath('data.label', 'مدقّق');

        $this->withHeaders($this->bearer($this->superAdminToken()) + ['Accept-Language' => 'en'])
            ->getJson(self::ROLES.'/'.$roleId)
            ->assertStatus(200)
            ->assertJsonPath('data.label', 'Auditor');
    }

    public function test_a_role_with_no_arabic_label_falls_back_rather_than_rendering_empty(): void
    {
        // §3.1's eight carry no Arabic name — the master documentation does not
        // contain one — so the fallback is what keeps that gap from rendering
        // as a blank cell on the Arabic screen.
        $this->withHeaders($this->bearer($this->superAdminToken()) + ['Accept-Language' => 'ar'])
            ->getJson(self::ROLES.'/'.$this->roleId(RoleName::Procurement))
            ->assertStatus(200)
            ->assertJsonPath('data.name_ar', null)
            ->assertJsonPath('data.label', RoleName::Procurement->label());
    }

    public function test_a_refusal_is_rendered_in_the_callers_language(): void
    {
        $this->withHeaders($this->bearer($this->superAdminToken()) + ['Accept-Language' => 'ar'])
            ->deleteJson(self::ROLES.'/'.$this->roleId(RoleName::Manager))
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.message', (string) __('identity.role_administration.system_role_cannot_be_deleted', [], 'ar'));
    }
}
