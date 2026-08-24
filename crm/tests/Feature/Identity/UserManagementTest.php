<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Application\Administration\CreateUser;
use App\Modules\Identity\Domain\Administration\RoleAssignmentPolicy;
use App\Modules\Identity\Domain\Administration\UserAdministrationRefused;
use App\Modules\Identity\Domain\Administration\UserListCriteria;
use App\Modules\Identity\Domain\Authentication\IdentityAuditEvents;
use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Identity\Infrastructure\Eloquent\UserSession;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Point 3.2 — §3.11's user administration.
 *
 * §3.1 · §3.11 · §3.12 rules 1, 4, 6 and 7 · §8's Employees screen ·
 * §9 Flow 9 · §10.1 · `D-28` · `D-34` · `SEC-02` · `SEC-05` · `SEC-07` ·
 * `SEC-08` · `SEC-09` · `SEC-12` · `AUD-01` · `OpenAPI §4.1`, §4.2, §5, §5.1,
 * §6.1, §6.2, §7.1, §7.2, §8.2.
 *
 * ── Two rules are checked against the document, not against this file ──────
 *
 * §3.11's create-user allowlist and §3.12 rule 7's denylist are both read back
 * out of the mounted master documentation. Transcribing an authorisation rule
 * into a test and then asserting the code matches the transcription proves the
 * two copies agree with each other and nothing about whether either is right —
 * this project's defect №4.
 */
final class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private const MASTER_DOCUMENTATION = '/opt/crm/docs/CRM_Documentation_EN.md';

    private const ENDPOINT = '/api/v1/users';

    private const PASSWORD = 'Passw0rd123';

    protected function setUp(): void
    {
        parent::setUp();

        // SEC-07: the grants come from the database, so the matrix has to be in
        // it before a single request is made. Without this every call is 403
        // and the suite would pass for the wrong reason.
        $this->seed(RolePermissionSeeder::class);
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function userWith(RoleName $role, ?string $email = null, bool $isActive = true): User
    {
        $row = Role::query()->where('slug', $role->value)->firstOrFail();

        $user = new User;
        $user->fill([
            'name' => 'Test '.$role->label(),
            'email' => $email ?? str_replace('_', '.', $role->value).'@example.test',
            'password' => self::PASSWORD,   // the `hashed` cast does SEC-02's half
            'role_id' => $row->id,
            'is_active' => $isActive,
            'is_hidden' => $role->isHidden(),
        ]);
        $user->save();

        return $user;
    }

    private function roleId(RoleName $role): string
    {
        return Role::query()->where('slug', $role->value)->firstOrFail()->id;
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

    private static function documentation(): string
    {
        self::assertFileExists(
            self::MASTER_DOCUMENTATION,
            'The master documentation is not mounted; this suite cannot check itself against it.',
        );

        $text = file_get_contents(self::MASTER_DOCUMENTATION);

        self::assertIsString($text);

        return $text;
    }

    /**
     * §3.11 and §3.12 write role names as prose, sometimes abbreviated. This is
     * the mapping from the document's spelling to §3.1's slug, and every entry
     * is a label the document actually uses.
     */
    private static function slugFor(string $label): string
    {
        return match (trim($label)) {
            'Out.Sup', 'Outdoor Supervisor' => RoleName::OutdoorSupervisor->value,
            'Out.Sales', 'Outdoor Sales' => RoleName::OutdoorSales->value,
            'Sales', 'Indoor', 'Indoor Sales' => RoleName::IndoorSales->value,
            'Procure', 'Procurement' => RoleName::Procurement->value,
            'Manager' => RoleName::Manager->value,
            'CEO' => RoleName::Ceo->value,
            'Super Admin' => RoleName::SuperAdmin->value,
            'TL', 'Team Leader' => RoleName::TeamLeader->value,
            default => throw new \RuntimeException('The documentation names a role this test cannot map: '.$label),
        };
    }

    // ── the documented rules, read out of the document ──────────────────────

    public function test_the_manager_allowlist_is_the_one_section_3_11_prints(): void
    {
        self::assertSame(
            1,
            preg_match(
                '/^\|\s*create user\s*\|\s*✅ any role\s*\|\s*✅ \(([^)]+?)\s*only\)\s*\|/mu',
                self::documentation(),
                $row,
            ),
            "§3.11's create-user row no longer reads as this test expects; the allowlist cannot be checked.",
        );

        self::assertIsString($row[1] ?? null);

        $slugs = array_map(self::slugFor(...), explode('·', $row[1]));

        self::assertSame(
            $slugs,
            RoleAssignmentPolicy::MANAGER_MAY_CREATE,
            'RoleAssignmentPolicy disagrees with §3.11 about which roles a Manager may create.',
        );
    }

    public function test_rule_seven_forbids_exactly_the_three_roles_the_document_names(): void
    {
        self::assertSame(
            1,
            preg_match(
                '/^7\.\s*\*\*The Manager may not create\*\*\s*(.+?)\s*accounts\./mu',
                self::documentation(),
                $row,
            ),
            '§3.12 rule 7 no longer reads as this test expects.',
        );

        self::assertIsString($row[1] ?? null);

        $named = preg_split('/,\s*|\s+or\s+/u', $row[1]);

        self::assertIsArray($named);

        $slugs = array_map(self::slugFor(...), $named);

        self::assertSame($slugs, RoleAssignmentPolicy::FORBIDDEN_TO_MANAGER);
    }

    /**
     * The two documented statements are checked against each other, which is
     * the check that found they disagree.
     *
     * §3.11's list excludes Team Leader; rule 7's does not mention it. The
     * allowlist is the narrower reading and this pins that choice, so the day
     * the owner decides otherwise the test says which line to change.
     */
    public function test_the_allowlist_honours_rule_seven_and_is_stricter_by_exactly_team_leader(): void
    {
        foreach (RoleAssignmentPolicy::FORBIDDEN_TO_MANAGER as $forbidden) {
            self::assertNotContains(
                $forbidden,
                RoleAssignmentPolicy::MANAGER_MAY_CREATE,
                'The §3.11 allowlist contradicts §3.12 rule 7.',
            );
        }

        $everyRole = array_map(static fn (RoleName $role): string => $role->value, RoleName::cases());

        $ruleSevenWouldAllow = array_values(array_diff($everyRole, RoleAssignmentPolicy::FORBIDDEN_TO_MANAGER));

        self::assertSame(
            [RoleName::TeamLeader->value],
            array_values(array_diff($ruleSevenWouldAllow, RoleAssignmentPolicy::MANAGER_MAY_CREATE)),
            'The gap between §3.11 and rule 7 is no longer exactly Team Leader; the owner question has moved.',
        );
    }

    // ── §3.12 rule 6: the hidden Super Admin ────────────────────────────────

    public function test_the_listing_excludes_the_hidden_super_admin(): void
    {
        $manager = $this->userWith(RoleName::Manager);
        $hidden = $this->userWith(RoleName::SuperAdmin);
        $visible = $this->userWith(RoleName::IndoorSales);

        $response = $this->withToken($this->tokenFor($manager))
            ->getJson(self::ENDPOINT)
            ->assertStatus(200);

        /** @var list<string> $ids */
        $ids = $response->json('data.*.id');

        self::assertContains($manager->id, $ids);
        self::assertContains($visible->id, $ids);
        self::assertNotContains($hidden->id, $ids, '§3.12 rule 6: the Super Admin appeared in a user list.');
    }

    public function test_the_super_admin_does_not_see_the_super_admin_either(): void
    {
        $superAdmin = $this->userWith(RoleName::SuperAdmin);

        $response = $this->withToken($this->tokenFor($superAdmin))
            ->getJson(self::ENDPOINT)
            ->assertStatus(200);

        /** @var list<string> $ids */
        $ids = $response->json('data.*.id');

        // "never listed in any user list, **for any role**" — the rule names no
        // exception, so neither does the implementation. The consequence is
        // real and deliberate: one Super Admin cannot administer another here.
        self::assertNotContains($superAdmin->id, $ids);
    }

    /** @return array<string, array{string, string}> */
    public static function hiddenTargets(): array
    {
        return [
            'show' => ['GET', ''],
            'update' => ['PATCH', ''],
            'deactivate' => ['PATCH', '/deactivate'],
            'reactivate' => ['PATCH', '/reactivate'],
        ];
    }

    #[DataProvider('hiddenTargets')]
    public function test_the_hidden_super_admin_cannot_be_reached_by_guessing_its_id(string $method, string $suffix): void
    {
        $manager = $this->userWith(RoleName::Manager);
        $hidden = $this->userWith(RoleName::SuperAdmin);

        $response = $this->withToken($this->tokenFor($manager))->json(
            $method,
            self::ENDPOINT.'/'.$hidden->id.$suffix,
            $method === 'PATCH' && $suffix === '' ? ['name' => 'Renamed'] : [],
        );

        // 404, not 403: OpenAPI §5.1 forbids a refusal that confirms the
        // resource exists, and confirming it is precisely what rule 6 forbids.
        $response->assertStatus(404);
        self::assertSame('resource_not_found', $response->json('error.code'));

        $hidden->refresh();
        self::assertSame('Test Super Admin', $hidden->name);
        self::assertTrue($hidden->is_active);
    }

    // ── SEC-09 / §3.12 rule 1: enforcement at the API ───────────────────────

    /** @return array<string, array{string, string}> */
    public static function protectedRoutes(): array
    {
        return [
            'index' => ['GET', ''],
            'store' => ['POST', ''],
            'show' => ['GET', '/{id}'],
            'update' => ['PATCH', '/{id}'],
            'deactivate' => ['PATCH', '/{id}/deactivate'],
            'reactivate' => ['PATCH', '/{id}/reactivate'],
        ];
    }

    #[DataProvider('protectedRoutes')]
    public function test_a_role_without_administration_permission_is_refused(string $method, string $suffix): void
    {
        $indoor = $this->userWith(RoleName::IndoorSales);
        $target = $this->userWith(RoleName::Procurement);

        $path = self::ENDPOINT.str_replace('{id}', $target->id, $suffix);

        $response = $this->withToken($this->tokenFor($indoor))->json($method, $path, []);

        $response->assertStatus(403);
        self::assertSame('permission_denied', $response->json('error.code'));
        self::assertSame('unauthorized_action', $response->json('error.details.0.code'));
        self::assertIsString($response->json('meta.request_id'));
    }

    #[DataProvider('protectedRoutes')]
    public function test_an_unauthenticated_caller_is_refused(string $method, string $suffix): void
    {
        $target = $this->userWith(RoleName::Procurement);

        $this->json($method, self::ENDPOINT.str_replace('{id}', $target->id, $suffix), [])
            ->assertStatus(401);
    }

    /**
     * §3.11's "Others" column is `—` on every row, and the Team Leader has an
     * §8 screen called "Sales Team" that could be mistaken for this one.
     */
    public function test_the_team_leader_holds_no_administration_permission(): void
    {
        $teamLeader = $this->userWith(RoleName::TeamLeader);

        $this->withToken($this->tokenFor($teamLeader))->getJson(self::ENDPOINT)->assertStatus(403);
    }

    // ── §9 Flow 9: creation ─────────────────────────────────────────────────

    public function test_a_manager_creates_a_user_who_can_then_sign_in(): void
    {
        $manager = $this->userWith(RoleName::Manager);

        $response = $this->withToken($this->tokenFor($manager))->postJson(self::ENDPOINT, [
            'name' => 'Nadia Salem',
            'email' => 'nadia@example.test',
            'password' => 'Str0ngpass',
            'role_id' => $this->roleId(RoleName::IndoorSales),
        ])->assertStatus(201);

        self::assertSame('Nadia Salem', $response->json('data.name'));
        self::assertSame(RoleName::IndoorSales->value, $response->json('data.role.slug'));
        self::assertTrue($response->json('data.is_active'));
        self::assertIsString($response->json('meta.request_id'));

        // No credential on the wire, in either direction.
        self::assertArrayNotHasKey('password', (array) $response->json('data'));

        $created = User::query()->where('email', 'nadia@example.test')->firstOrFail();

        // SEC-02: stored hashed, and demonstrably not the plaintext.
        self::assertNotSame('Str0ngpass', $created->password);
        self::assertTrue(Hash::check('Str0ngpass', $created->password));

        $this->postJson('/api/v1/auth/login', [
            'email' => 'nadia@example.test',
            'password' => 'Str0ngpass',
        ])->assertStatus(201);
    }

    public function test_creation_writes_an_audit_row_that_holds_no_credential(): void
    {
        $manager = $this->userWith(RoleName::Manager);

        $this->withToken($this->tokenFor($manager))->postJson(self::ENDPOINT, [
            'name' => 'Nadia Salem',
            'email' => 'nadia@example.test',
            'password' => 'Str0ngpass',
            'role_id' => $this->roleId(RoleName::IndoorSales),
        ])->assertStatus(201);

        $rows = $this->auditRows(IdentityAuditEvents::USER_CREATED);

        self::assertCount(1, $rows, 'AUD-01: creating a user left no audit row.');

        $serialised = json_encode($rows[0]);

        self::assertIsString($serialised);
        self::assertStringNotContainsString('Str0ngpass', $serialised);
        self::assertStringNotContainsString('$2y$', $serialised, 'A bcrypt hash reached a permanent audit row.');
        self::assertStringNotContainsString('$argon2', $serialised);
        self::assertStringContainsString('nadia@example.test', $serialised);
    }

    /** @return array<string, array{string}> */
    public static function rolesAManagerMayNotCreate(): array
    {
        return [
            'manager' => [RoleName::Manager->value],
            'ceo' => [RoleName::Ceo->value],
            'super admin' => [RoleName::SuperAdmin->value],
            'team leader' => [RoleName::TeamLeader->value],
        ];
    }

    #[DataProvider('rolesAManagerMayNotCreate')]
    public function test_a_manager_cannot_create_a_role_outside_the_allowlist(string $slug): void
    {
        $manager = $this->userWith(RoleName::Manager);

        $response = $this->withToken($this->tokenFor($manager))->postJson(self::ENDPOINT, [
            'name' => 'Escalated',
            'email' => 'escalated@example.test',
            'password' => 'Str0ngpass',
            'role_id' => $this->roleId(RoleName::from($slug)),
        ]);

        // 422 and not 403: the caller may administer users; the submitted role
        // is what is unacceptable (§3.12 rule 7).
        $response->assertStatus(422);
        self::assertSame('validation_failed', $response->json('error.code'));
        self::assertSame('role_not_assignable', $response->json('error.details.0.code'));
        self::assertSame('role_id', $response->json('error.details.0.field'));

        self::assertNull(User::query()->where('email', 'escalated@example.test')->first());
        self::assertCount(0, $this->auditRows(IdentityAuditEvents::USER_CREATED));
    }

    public function test_the_super_admin_may_create_any_role(): void
    {
        $superAdmin = $this->userWith(RoleName::SuperAdmin);
        $token = $this->tokenFor($superAdmin);

        foreach (RoleName::cases() as $index => $role) {
            $this->withToken($token)->postJson(self::ENDPOINT, [
                'name' => 'Created '.$role->label(),
                'email' => 'created'.$index.'@example.test',
                'password' => 'Str0ngpass',
                'role_id' => $this->roleId($role),
            ])->assertStatus(201);
        }

        self::assertCount(count(RoleName::cases()), $this->auditRows(IdentityAuditEvents::USER_CREATED));
    }

    public function test_a_created_super_admin_is_hidden_without_the_client_saying_so(): void
    {
        $superAdmin = $this->userWith(RoleName::SuperAdmin);

        $this->withToken($this->tokenFor($superAdmin))->postJson(self::ENDPOINT, [
            'name' => 'Second Developer',
            'email' => 'second@example.test',
            'password' => 'Str0ngpass',
            'role_id' => $this->roleId(RoleName::SuperAdmin),
            // §3.12 rule 6 is derived from the role. A client asking to be
            // visible must not be able to opt out of the rule.
            'is_hidden' => false,
        ])->assertStatus(201);

        $created = User::query()->where('email', 'second@example.test')->firstOrFail();

        self::assertTrue($created->is_hidden);
    }

    public function test_an_ordinary_role_is_not_hidden(): void
    {
        $manager = $this->userWith(RoleName::Manager);

        $this->withToken($this->tokenFor($manager))->postJson(self::ENDPOINT, [
            'name' => 'Nadia Salem',
            'email' => 'nadia@example.test',
            'password' => 'Str0ngpass',
            'role_id' => $this->roleId(RoleName::IndoorSales),
            'is_hidden' => true,
        ])->assertStatus(201);

        self::assertFalse(User::query()->where('email', 'nadia@example.test')->firstOrFail()->is_hidden);
    }

    public function test_a_duplicate_live_address_is_refused(): void
    {
        $manager = $this->userWith(RoleName::Manager);
        $existing = $this->userWith(RoleName::IndoorSales, 'taken@example.test');

        $this->withToken($this->tokenFor($manager))->postJson(self::ENDPOINT, [
            'name' => 'Impostor',
            'email' => $existing->email,
            'password' => 'Str0ngpass',
            'role_id' => $this->roleId(RoleName::IndoorSales),
        ])->assertStatus(422);

        self::assertSame(1, User::query()->where('email', 'taken@example.test')->count());
    }

    /**
     * `D-28` is enforced by the use case, not only by the Form Request.
     *
     * `min:8` passes on nine letters with no digit, so a request that clears
     * the validator and is still refused proves the second check exists. The
     * use case is invoked directly because the HTTP layer would have to be
     * broken to let this through.
     */
    public function test_the_password_policy_is_re_asked_behind_the_form_request(): void
    {
        $manager = $this->userWith(RoleName::Manager);

        $create = $this->app->make(CreateUser::class);

        $this->expectException(UserAdministrationRefused::class);

        $create->handle(
            $manager->id,
            'No Digits',
            'nodigits@example.test',
            'abcdefghi',   // nine characters, passes min:8, fails D-28
            $this->roleId(RoleName::IndoorSales),
        );
    }

    // ── §9 Flow 9: later changes ────────────────────────────────────────────

    public function test_a_manager_updates_a_name_and_the_change_is_audited(): void
    {
        $manager = $this->userWith(RoleName::Manager);
        $target = $this->userWith(RoleName::IndoorSales);

        $this->withToken($this->tokenFor($manager))
            ->patchJson(self::ENDPOINT.'/'.$target->id, ['name' => 'Renamed Person'])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'Renamed Person');

        self::assertSame('Renamed Person', $target->refresh()->name);

        $rows = $this->auditRows(IdentityAuditEvents::USER_UPDATED);

        self::assertCount(1, $rows);

        $serialised = json_encode($rows[0]);

        self::assertIsString($serialised);
        self::assertStringContainsString('Renamed Person', $serialised);
        self::assertStringContainsString('Test Indoor Sales', $serialised, 'AUD-02: the old value is missing.');
    }

    public function test_a_role_change_writes_the_mandatory_rule_four_entry(): void
    {
        $manager = $this->userWith(RoleName::Manager);
        $target = $this->userWith(RoleName::IndoorSales);

        $this->withToken($this->tokenFor($manager))
            ->patchJson(self::ENDPOINT.'/'.$target->id, [
                'role_id' => $this->roleId(RoleName::Procurement),
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.role.slug', RoleName::Procurement->value);

        // §3.12 rule 4 lists "role change" among the mandatory entries, and an
        // auditor filters on the event column.
        $rows = $this->auditRows(IdentityAuditEvents::ROLE_CHANGED);

        self::assertCount(1, $rows, '§3.12 rule 4: a role change left no ROLE_CHANGED entry.');

        $serialised = json_encode($rows[0]);

        self::assertIsString($serialised);
        self::assertStringContainsString(RoleName::IndoorSales->value, $serialised);
        self::assertStringContainsString(RoleName::Procurement->value, $serialised);
    }

    #[DataProvider('rolesAManagerMayNotCreate')]
    public function test_a_manager_cannot_promote_anybody_into_a_forbidden_role(string $slug): void
    {
        $manager = $this->userWith(RoleName::Manager);
        $target = $this->userWith(RoleName::IndoorSales);
        $originalRole = $target->role_id;

        $response = $this->withToken($this->tokenFor($manager))
            ->patchJson(self::ENDPOINT.'/'.$target->id, ['role_id' => $this->roleId(RoleName::from($slug))]);

        $response->assertStatus(422);
        self::assertSame('role_not_assignable', $response->json('error.details.0.code'));

        self::assertSame($originalRole, $target->refresh()->role_id, 'Rule 7 was bypassed through PATCH.');
        self::assertCount(0, $this->auditRows(IdentityAuditEvents::ROLE_CHANGED));
    }

    public function test_a_manager_cannot_promote_themselves(): void
    {
        $manager = $this->userWith(RoleName::Manager);

        $this->withToken($this->tokenFor($manager))
            ->patchJson(self::ENDPOINT.'/'.$manager->id, ['role_id' => $this->roleId(RoleName::SuperAdmin)])
            ->assertStatus(422);

        self::assertFalse($manager->refresh()->is_hidden);
        self::assertSame(RoleName::Manager->value, $manager->role()->firstOrFail()->slug);
    }

    public function test_a_patch_with_no_recognised_field_is_refused(): void
    {
        $manager = $this->userWith(RoleName::Manager);
        $target = $this->userWith(RoleName::IndoorSales);

        $this->withToken($this->tokenFor($manager))
            ->patchJson(self::ENDPOINT.'/'.$target->id, ['is_active' => false])
            ->assertStatus(422);

        // The ignored field really was ignored rather than applied.
        self::assertTrue($target->refresh()->is_active);
    }

    // ── D-34 · §10.1: deactivation ──────────────────────────────────────────

    public function test_deactivation_flips_the_switch_purges_sessions_and_blocks_login(): void
    {
        $manager = $this->userWith(RoleName::Manager);
        $target = $this->userWith(RoleName::IndoorSales);

        $targetToken = $this->tokenFor($target);

        self::assertSame(1, UserSession::query()->where('user_id', $target->id)->count());

        $response = $this->withToken($this->tokenFor($manager))
            ->patchJson(self::ENDPOINT.'/'.$target->id.'/deactivate')
            ->assertStatus(200);

        self::assertFalse($response->json('data.is_active'));
        self::assertSame(1, $response->json('data.sessions_revoked'));
        self::assertTrue($response->json('data.changed'));

        // D-34: deactivated, never deleted.
        $target->refresh();
        self::assertFalse($target->is_active);
        self::assertNull($target->deleted_at);

        // SEC-05: the token already in the field stops working immediately,
        // rather than at D-29's eight-hour idle boundary.
        self::assertSame(0, UserSession::query()->where('user_id', $target->id)->count());
        $this->withToken($targetToken)->getJson('/api/v1/auth/me')->assertStatus(401);

        // §10.1: "Login — Blocked".
        $this->postJson('/api/v1/auth/login', [
            'email' => $target->email,
            'password' => self::PASSWORD,
        ])->assertStatus(403);

        self::assertCount(1, $this->auditRows(IdentityAuditEvents::USER_DEACTIVATED));
    }

    public function test_deactivating_an_inactive_account_changes_nothing_and_logs_nothing(): void
    {
        $manager = $this->userWith(RoleName::Manager);
        $target = $this->userWith(RoleName::IndoorSales, isActive: false);

        $response = $this->withToken($this->tokenFor($manager))
            ->patchJson(self::ENDPOINT.'/'.$target->id.'/deactivate')
            ->assertStatus(200);

        self::assertFalse($response->json('data.changed'));

        // AUD-03 keeps rows for ever, so a row describing a click that changed
        // nothing is permanent noise.
        self::assertCount(0, $this->auditRows(IdentityAuditEvents::USER_DEACTIVATED));
    }

    public function test_reactivation_restores_login_and_is_audited(): void
    {
        $manager = $this->userWith(RoleName::Manager);
        $target = $this->userWith(RoleName::IndoorSales, isActive: false);

        $this->postJson('/api/v1/auth/login', [
            'email' => $target->email,
            'password' => self::PASSWORD,
        ])->assertStatus(403);

        $this->withToken($this->tokenFor($manager))
            ->patchJson(self::ENDPOINT.'/'.$target->id.'/reactivate')
            ->assertStatus(200)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.sessions_revoked', 0);

        $this->postJson('/api/v1/auth/login', [
            'email' => $target->email,
            'password' => self::PASSWORD,
        ])->assertStatus(201);

        self::assertCount(1, $this->auditRows(IdentityAuditEvents::USER_ACTIVATED));
    }

    // ── OpenAPI §4.2 · §6: the list contract ────────────────────────────────

    public function test_the_collection_envelope_carries_all_six_pagination_keys(): void
    {
        $manager = $this->userWith(RoleName::Manager);

        foreach (range(1, 5) as $index) {
            $this->userWith(RoleName::IndoorSales, 'person'.$index.'@example.test');
        }

        $response = $this->withToken($this->tokenFor($manager))
            ->getJson(self::ENDPOINT.'?page=2&per_page=2')
            ->assertStatus(200);

        self::assertSame([
            'page' => 2,
            'per_page' => 2,
            'total' => 6,
            'total_pages' => 3,
            'has_next_page' => true,
            'has_previous_page' => true,
        ], $response->json('meta.pagination'));

        self::assertCount(2, (array) $response->json('data'));
    }

    public function test_paging_never_returns_the_same_row_twice(): void
    {
        $manager = $this->userWith(RoleName::Manager);

        // Same name on every row, so the sort key cannot break the tie and only
        // the id tiebreak keeps the pages disjoint.
        foreach (range(1, 6) as $index) {
            $user = $this->userWith(RoleName::IndoorSales, 'same'.$index.'@example.test');
            $user->name = 'Identical Name';
            $user->save();
        }

        $token = $this->tokenFor($manager);
        $seen = [];

        foreach ([1, 2, 3, 4] as $page) {
            /** @var list<string> $ids */
            $ids = $this->withToken($token)
                ->getJson(self::ENDPOINT.'?page='.$page.'&per_page=2')
                ->assertStatus(200)
                ->json('data.*.id');

            $seen = array_merge($seen, $ids);
        }

        self::assertSame(array_unique($seen), $seen, 'A row appeared on two pages of one listing.');
        self::assertCount(7, $seen);
    }

    public function test_the_status_filter_narrows_the_listing(): void
    {
        $manager = $this->userWith(RoleName::Manager);
        $active = $this->userWith(RoleName::IndoorSales, 'active@example.test');
        $inactive = $this->userWith(RoleName::Procurement, 'inactive@example.test', isActive: false);

        $token = $this->tokenFor($manager);

        /** @var list<string> $activeIds */
        $activeIds = $this->withToken($token)
            ->getJson(self::ENDPOINT.'?filter[is_active]=true')
            ->assertStatus(200)
            ->json('data.*.id');

        self::assertContains($active->id, $activeIds);
        self::assertNotContains($inactive->id, $activeIds);

        /** @var list<string> $inactiveIds */
        $inactiveIds = $this->withToken($token)
            ->getJson(self::ENDPOINT.'?filter[is_active]=false')
            ->assertStatus(200)
            ->json('data.*.id');

        self::assertSame([$inactive->id], $inactiveIds);
    }

    public function test_the_role_filter_narrows_the_listing(): void
    {
        $manager = $this->userWith(RoleName::Manager);
        $this->userWith(RoleName::IndoorSales);
        $procurement = $this->userWith(RoleName::Procurement);

        /** @var list<string> $ids */
        $ids = $this->withToken($this->tokenFor($manager))
            ->getJson(self::ENDPOINT.'?filter[role]='.RoleName::Procurement->value)
            ->assertStatus(200)
            ->json('data.*.id');

        self::assertSame([$procurement->id], $ids);
    }

    /** @return array<string, array{string}> */
    public static function unhonourableQueries(): array
    {
        return [
            'per_page above the maximum' => ['?per_page=101'],
            'per_page zero' => ['?per_page=0'],
            'per_page not a number' => ['?per_page=many'],
            'page zero' => ['?page=0'],
            'unknown filter' => ['?filter[salary]=1000'],
            'non-boolean status' => ['?filter[is_active]=maybe'],
            'unknown sort field' => ['?sort=salary'],
            'two sort fields' => ['?sort=name,email'],
        ];
    }

    #[DataProvider('unhonourableQueries')]
    public function test_an_unhonourable_list_query_is_four_hundred_and_not_ignored(string $query): void
    {
        $manager = $this->userWith(RoleName::Manager);

        $response = $this->withToken($this->tokenFor($manager))->getJson(self::ENDPOINT.$query);

        // OpenAPI §6.1 and §6.2: 400 invalid_request, and never silently
        // ignored. 422 would be wrong — nobody typed this into a form.
        $response->assertStatus(400);
        self::assertSame('invalid_request', $response->json('error.code'));
        self::assertIsString($response->json('meta.request_id'));
    }

    public function test_the_documented_defaults_are_the_ones_openapi_states(): void
    {
        self::assertSame(25, UserListCriteria::DEFAULT_PER_PAGE);
        self::assertSame(100, UserListCriteria::MAX_PER_PAGE);

        $manager = $this->userWith(RoleName::Manager);

        $this->withToken($this->tokenFor($manager))
            ->getJson(self::ENDPOINT)
            ->assertStatus(200)
            ->assertJsonPath('meta.pagination.per_page', 25)
            ->assertJsonPath('meta.pagination.page', 1);
    }

    public function test_sorting_is_applied_in_the_direction_asked_for(): void
    {
        $manager = $this->userWith(RoleName::Manager, 'zzz@example.test');
        $manager->name = 'Zoe';
        $manager->save();

        $first = $this->userWith(RoleName::IndoorSales, 'aaa@example.test');
        $first->name = 'Amir';
        $first->save();

        $token = $this->tokenFor($manager);

        self::assertSame(
            'Amir',
            $this->withToken($token)->getJson(self::ENDPOINT.'?sort=name')->json('data.0.name'),
        );

        self::assertSame(
            'Zoe',
            $this->withToken($token)->getJson(self::ENDPOINT.'?sort=-name')->json('data.0.name'),
        );
    }

    // ── the detail endpoint ─────────────────────────────────────────────────

    public function test_the_detail_endpoint_returns_the_documented_shape(): void
    {
        $manager = $this->userWith(RoleName::Manager);
        $target = $this->userWith(RoleName::IndoorSales);

        $response = $this->withToken($this->tokenFor($manager))
            ->getJson(self::ENDPOINT.'/'.$target->id)
            ->assertStatus(200);

        self::assertSame([
            'id', 'name', 'email', 'role_id', 'role', 'is_active', 'created_at', 'updated_at',
        ], array_keys((array) $response->json('data')));

        self::assertSame($target->id, $response->json('data.id'));
        self::assertSame(RoleName::IndoorSales->value, $response->json('data.role.slug'));
    }

    public function test_an_unknown_id_is_four_hundred_and_four(): void
    {
        $manager = $this->userWith(RoleName::Manager);

        $this->withToken($this->tokenFor($manager))
            ->getJson(self::ENDPOINT.'/018f6a2c-2e7e-7d9a-a5e8-3b329495aa10')
            ->assertStatus(404);
    }
}
