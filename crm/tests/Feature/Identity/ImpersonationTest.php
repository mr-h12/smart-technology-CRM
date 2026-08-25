<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Audit\Infrastructure\RequestAuditContext;
use App\Modules\Identity\Domain\Authentication\IdentityAuditEvents;
use App\Modules\Identity\Domain\Authentication\SessionAttribute;
use App\Modules\Identity\Domain\Impersonation\ImpersonationRefusal;
use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Identity\Infrastructure\Eloquent\UserSession;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Point 3.4 — `SEC-10`: "Login As restricted to Super Admin, with mandatory
 * logging".
 *
 * `SEC-10` · §3.1 · §3.11 · §3.12 rules 4 and 6 · `D-34` · §10.1 · `AUD-01` ·
 * `AUD-02` · `AUD-05` · `D-69` · `OpenAPI §5`, §5.1.
 *
 * ── The property this file exists to defend ────────────────────────────────
 *
 * An impersonation session runs with the target's role and the target's
 * permissions — that is the feature. The danger is that it therefore *looks*
 * like the target in every record it leaves, and §3.12 rule 4 makes Login As
 * mandatory to log precisely so the real person is named. So the central test
 * here is not that impersonation works; it is that an action taken during one
 * writes **both** identities into `audit_log`.
 */
final class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    private const MASTER_DOCUMENTATION = '/opt/crm/docs/CRM_Documentation_EN.md';

    private const LEAVE = '/api/v1/auth/impersonate/leave';

    private const PASSWORD = 'Passw0rd123';

    protected function setUp(): void
    {
        parent::setUp();

        // SEC-07: `admin.login_as` is a grant row, so the matrix has to be in
        // the database before a single request is made.
        $this->seed(RolePermissionSeeder::class);
    }

    private function userWith(RoleName $role, ?string $email = null, bool $isActive = true): User
    {
        $row = Role::query()->where('slug', $role->value)->firstOrFail();

        $user = new User;
        $user->fill([
            'name' => 'Test '.$role->label(),
            'email' => $email ?? str_replace('_', '.', $role->value).'@example.test',
            'password' => self::PASSWORD,
            'role_id' => $row->id,
            'is_active' => $isActive,
            'is_hidden' => $role->isHidden(),
        ]);
        $user->save();

        return $user;
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

    private static function endpointFor(User $target): string
    {
        return '/api/v1/auth/impersonate/'.$target->id;
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

    // ── the documented requirement ──────────────────────────────────────────

    public function test_sec_10_and_rule_four_read_the_way_this_point_implements_them(): void
    {
        self::assertFileExists(self::MASTER_DOCUMENTATION);

        $text = file_get_contents(self::MASTER_DOCUMENTATION);

        self::assertIsString($text);

        self::assertMatchesRegularExpression(
            '/^\|\s*SEC-10\s*\|\s*Login As restricted to Super Admin, with mandatory logging/mu',
            $text,
            'SEC-10 has been reworded; re-read it before trusting this implementation.',
        );

        // §3.12 rule 4's list of mandatory entries names it.
        self::assertMatchesRegularExpression(
            '/^4\.\s*\*\*Mandatory audit entries\*\*.*Login As/mu',
            $text,
        );

        // §3.11 gives the row to the Super Admin column and to nobody else.
        self::assertMatchesRegularExpression(
            '/^\|\s*Login As User\s*\|\s*✅[^|]*\|\s*—\s*\|\s*—\s*\|/mu',
            $text,
            '§3.11 no longer restricts Login As to the Super Admin alone.',
        );
    }

    // ── the happy path ──────────────────────────────────────────────────────

    public function test_the_super_admin_can_sign_in_as_an_active_user(): void
    {
        $superAdmin = $this->userWith(RoleName::SuperAdmin);
        $target = $this->userWith(RoleName::IndoorSales);

        $response = $this->withToken($this->tokenFor($superAdmin))
            ->postJson(self::endpointFor($target))
            ->assertStatus(201);

        self::assertIsString($response->json('data.token'));
        self::assertSame($target->id, $response->json('data.impersonating.id'));
        self::assertSame(RoleName::IndoorSales->value, $response->json('data.impersonating.role'));
        self::assertSame($superAdmin->id, $response->json('data.impersonator_id'));
        self::assertIsString($response->json('meta.request_id'));
    }

    public function test_the_impersonation_token_acts_as_the_target(): void
    {
        $superAdmin = $this->userWith(RoleName::SuperAdmin);
        $target = $this->userWith(RoleName::IndoorSales);

        $token = $this->withToken($this->tokenFor($superAdmin))
            ->postJson(self::endpointFor($target))
            ->assertStatus(201)
            ->json('data.token');

        self::assertIsString($token);

        // /me answers as the employee, not as the Super Admin: the session
        // carries their id, their role and their grants, which is the whole
        // point of Login As.
        $me = $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(200);

        self::assertSame($target->id, $me->json('data.id'));
        self::assertSame(RoleName::IndoorSales->value, $me->json('data.role.slug'));
    }

    public function test_the_impersonated_session_holds_the_targets_permissions_and_not_the_super_admins(): void
    {
        $superAdmin = $this->userWith(RoleName::SuperAdmin);
        $target = $this->userWith(RoleName::IndoorSales);
        $superAdminToken = $this->tokenFor($superAdmin);

        // The Super Admin may list users; §3.11 gives Indoor Sales nothing.
        $this->withToken($superAdminToken)->getJson('/api/v1/users')->assertStatus(200);

        $token = $this->withToken($superAdminToken)
            ->postJson(self::endpointFor($target))
            ->assertStatus(201)
            ->json('data.token');

        self::assertIsString($token);

        // Unconditional access does **not** travel with the impersonator. If it
        // did, Login As would be a way to hand §3.1's exemption to a session
        // that reports itself as an ordinary employee.
        $this->withToken($token)->getJson('/api/v1/users')->assertStatus(403);
    }

    public function test_the_session_row_records_both_identities(): void
    {
        $superAdmin = $this->userWith(RoleName::SuperAdmin);
        $target = $this->userWith(RoleName::IndoorSales);

        $this->withToken($this->tokenFor($superAdmin))
            ->postJson(self::endpointFor($target))
            ->assertStatus(201);

        $session = UserSession::query()
            ->where('user_id', $target->id)
            ->whereNotNull('impersonator_id')
            ->firstOrFail();

        self::assertSame($superAdmin->id, $session->impersonator_id);
    }

    // ── the dual audit trail, which is the point ────────────────────────────

    public function test_starting_writes_the_mandatory_rule_four_entry(): void
    {
        $superAdmin = $this->userWith(RoleName::SuperAdmin);
        $target = $this->userWith(RoleName::IndoorSales);

        $this->withToken($this->tokenFor($superAdmin))
            ->postJson(self::endpointFor($target))
            ->assertStatus(201);

        $rows = $this->auditRows(IdentityAuditEvents::IMPERSONATION_STARTED);

        self::assertCount(1, $rows, '§3.12 rule 4: Login As left no audit entry.');

        // The actor is the human who did it; the entity is who they became.
        self::assertSame($superAdmin->id, $rows[0]['user_id']);
        self::assertSame($target->id, $rows[0]['entity_id']);
        self::assertSame('user', $rows[0]['entity_type']);
    }

    public function test_an_action_taken_while_impersonating_names_both_people(): void
    {
        $superAdmin = $this->userWith(RoleName::SuperAdmin);
        $target = $this->userWith(RoleName::IndoorSales);

        $token = $this->withToken($this->tokenFor($superAdmin))
            ->postJson(self::endpointFor($target))
            ->assertStatus(201)
            ->json('data.token');

        self::assertIsString($token);

        // An ordinary audited action, taken through the impersonation session.
        // Logging out is the smallest one that exists and belongs to the target.
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertStatus(200);

        $rows = $this->auditRows(IdentityAuditEvents::LOGOUT);

        self::assertCount(1, $rows);

        // This is the assertion the whole point exists for. Without it the row
        // reads "the Indoor Sales employee logged out", and the Super Admin who
        // actually did it is nowhere — which is precisely the record §3.12
        // rule 4 and SEC-10 exist to prevent.
        self::assertSame($superAdmin->id, $rows[0]['user_id'],
            'SEC-10: the actor on an impersonated action must be the Super Admin.');
        self::assertSame($target->id, $rows[0]['impersonated_user_id'],
            'SEC-10: the impersonated account must be named on the row.');
    }

    public function test_an_ordinary_session_leaves_no_impersonated_user_on_its_rows(): void
    {
        $user = $this->userWith(RoleName::IndoorSales);

        $this->withToken($this->tokenFor($user))->postJson('/api/v1/auth/logout')->assertStatus(200);

        $rows = $this->auditRows(IdentityAuditEvents::LOGOUT);

        self::assertCount(1, $rows);
        self::assertSame($user->id, $rows[0]['user_id']);

        // Null on almost every row in the system. A column that filled itself
        // in would make "was this impersonated" unanswerable.
        self::assertNull($rows[0]['impersonated_user_id']);
    }

    public function test_the_audit_module_and_the_identity_module_agree_on_the_attribute_name(): void
    {
        // The two constants are duplicated because deptrac lets Identity depend
        // on Audit and not the reverse, so neither file may name the other's.
        // This is the test that notices when they drift apart — the same
        // arrangement RequestAuditContext already uses for AddRequestId.
        self::assertSame(SessionAttribute::IMPERSONATOR, RequestAuditContext::IMPERSONATOR_ATTRIBUTE);
    }

    // ── SEC-10: only the Super Admin ────────────────────────────────────────

    /** @return array<string, array{RoleName}> */
    public static function rolesThatMayNotImpersonate(): array
    {
        return [
            'manager' => [RoleName::Manager],
            'team leader' => [RoleName::TeamLeader],
            'indoor sales' => [RoleName::IndoorSales],
            'outdoor sales' => [RoleName::OutdoorSales],
            'outdoor supervisor' => [RoleName::OutdoorSupervisor],
            'procurement' => [RoleName::Procurement],
            'ceo' => [RoleName::Ceo],
        ];
    }

    #[DataProvider('rolesThatMayNotImpersonate')]
    public function test_no_role_but_the_super_admin_may_impersonate(RoleName $role): void
    {
        $caller = $this->userWith($role);
        $target = $this->userWith(RoleName::Procurement, 'target@example.test');

        $response = $this->withToken($this->tokenFor($caller))->postJson(self::endpointFor($target));

        $response->assertStatus(403);
        self::assertSame('permission_denied', $response->json('error.code'));

        self::assertSame([], $this->auditRows(IdentityAuditEvents::IMPERSONATION_STARTED));
        self::assertSame(0, UserSession::query()->whereNotNull('impersonator_id')->count());
    }

    public function test_an_unauthenticated_caller_is_refused(): void
    {
        $target = $this->userWith(RoleName::IndoorSales);

        $this->postJson(self::endpointFor($target))->assertStatus(401);
        $this->postJson(self::LEAVE)->assertStatus(401);
    }

    /**
     * The use case asks §3.1 again, behind the matrix.
     *
     * §3.12 rule 5 makes the matrix configuration — an administrator can grant
     * `admin.login_as` to another role with an `INSERT`. `SEC-10` is a
     * requirement they cannot change that way, so the sentence is enforced
     * where no row reaches it. This test grants the row and asserts the answer
     * is still no.
     */
    public function test_granting_the_permission_to_another_role_does_not_grant_login_as(): void
    {
        $manager = $this->userWith(RoleName::Manager);
        $target = $this->userWith(RoleName::IndoorSales);

        $managerRole = Role::query()->where('slug', RoleName::Manager->value)->firstOrFail();
        $permission = DB::table('permissions')
            ->where('resource', 'admin')->where('action', 'login_as')
            ->firstOrFail();

        DB::table('role_permissions')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid7(),
            'role_id' => $managerRole->id,
            'permission_id' => $permission->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withToken($this->tokenFor($manager))->postJson(self::endpointFor($target));

        // Past the middleware, refused by SEC-10 itself.
        $response->assertStatus(403);
        self::assertSame(
            ImpersonationRefusal::CallerIsNotSuperAdmin->value,
            $response->json('error.details.0.code'),
        );
    }

    // ── refusals about the target ───────────────────────────────────────────

    public function test_a_deactivated_account_cannot_be_impersonated(): void
    {
        $superAdmin = $this->userWith(RoleName::SuperAdmin);
        $target = $this->userWith(RoleName::IndoorSales, isActive: false);

        $response = $this->withToken($this->tokenFor($superAdmin))->postJson(self::endpointFor($target));

        // D-34 · §10.1: that account is blocked from signing in, and becoming
        // it would be a way around the one switch the requirement provides.
        $response->assertStatus(422);
        self::assertSame('business_rule_blocked', $response->json('error.code'));
        self::assertSame(ImpersonationRefusal::TargetSuspended->value, $response->json('error.details.0.code'));

        self::assertSame(0, UserSession::query()->where('user_id', $target->id)->count());
        self::assertSame([], $this->auditRows(IdentityAuditEvents::IMPERSONATION_STARTED));
    }

    public function test_a_hidden_account_is_indistinguishable_from_one_that_does_not_exist(): void
    {
        $superAdmin = $this->userWith(RoleName::SuperAdmin);
        $otherHidden = $this->userWith(RoleName::SuperAdmin, 'second.admin@example.test');
        $token = $this->tokenFor($superAdmin);

        $hidden = $this->withToken($token)->postJson(self::endpointFor($otherHidden));
        $missing = $this->withToken($token)->postJson('/api/v1/auth/impersonate/018f6a2c-2e7e-7d9a-a5e8-3b329495aa10');

        // §3.12 rule 6 plus OpenAPI §5.1: the same answer, so Login As cannot
        // be used to enumerate the accounts rule 6 conceals.
        foreach ([$hidden, $missing] as $response) {
            $response->assertStatus(404);
            self::assertSame('resource_not_found', $response->json('error.code'));
        }
    }

    public function test_the_super_admin_cannot_impersonate_themselves(): void
    {
        $superAdmin = $this->userWith(RoleName::SuperAdmin);

        $this->withToken($this->tokenFor($superAdmin))
            ->postJson(self::endpointFor($superAdmin))
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.code', ImpersonationRefusal::TargetIsSelf->value);
    }

    public function test_impersonation_cannot_be_nested(): void
    {
        $superAdmin = $this->userWith(RoleName::SuperAdmin);
        $first = $this->userWith(RoleName::IndoorSales, 'first@example.test');
        $second = $this->userWith(RoleName::Procurement, 'second@example.test');

        $token = $this->tokenFor($superAdmin);

        $this->withToken($token)->postJson(self::endpointFor($first))->assertStatus(201);

        // A chain leaves `impersonated_user_id` with one slot and two answers.
        $this->withToken($token)->postJson(self::endpointFor($second))
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.code', ImpersonationRefusal::AlreadyImpersonating->value);

        self::assertSame(1, UserSession::query()->whereNotNull('impersonator_id')->count());
    }

    // ── leaving ─────────────────────────────────────────────────────────────

    public function test_leaving_kills_the_impersonation_token_and_keeps_the_original(): void
    {
        $superAdmin = $this->userWith(RoleName::SuperAdmin);
        $target = $this->userWith(RoleName::IndoorSales);

        $original = $this->tokenFor($superAdmin);

        $impersonated = $this->withToken($original)
            ->postJson(self::endpointFor($target))
            ->assertStatus(201)
            ->json('data.token');

        self::assertIsString($impersonated);

        $this->withToken($impersonated)->postJson(self::LEAVE)
            ->assertStatus(200)
            ->assertJsonPath('data.impersonation_ended', true)
            ->assertJsonPath('data.resume_with_original_token', true);

        // The impersonation token is dead …
        $this->withToken($impersonated)->getJson('/api/v1/auth/me')->assertStatus(401);

        // … and the Super Admin's own session was never touched, which is what
        // makes "resume" a client-side switch rather than a second login.
        $me = $this->withToken($original)->getJson('/api/v1/auth/me')->assertStatus(200);
        self::assertSame($superAdmin->id, $me->json('data.id'));
    }

    public function test_leaving_writes_the_closing_audit_entry(): void
    {
        $superAdmin = $this->userWith(RoleName::SuperAdmin);
        $target = $this->userWith(RoleName::IndoorSales);

        $impersonated = $this->withToken($this->tokenFor($superAdmin))
            ->postJson(self::endpointFor($target))
            ->assertStatus(201)
            ->json('data.token');

        self::assertIsString($impersonated);

        $this->withToken($impersonated)->postJson(self::LEAVE)->assertStatus(200);

        $rows = $this->auditRows(IdentityAuditEvents::IMPERSONATION_ENDED);

        self::assertCount(1, $rows, 'The log said when it began and never that it stopped.');
        self::assertSame($superAdmin->id, $rows[0]['user_id']);
        self::assertSame($target->id, $rows[0]['entity_id']);
        self::assertSame($target->id, $rows[0]['impersonated_user_id']);
    }

    public function test_leaving_frees_the_super_admin_to_impersonate_again(): void
    {
        $superAdmin = $this->userWith(RoleName::SuperAdmin);
        $first = $this->userWith(RoleName::IndoorSales, 'first@example.test');
        $second = $this->userWith(RoleName::Procurement, 'second@example.test');

        $original = $this->tokenFor($superAdmin);

        $token = $this->withToken($original)->postJson(self::endpointFor($first))
            ->assertStatus(201)->json('data.token');

        self::assertIsString($token);

        $this->withToken($token)->postJson(self::LEAVE)->assertStatus(200);

        // The nesting guard counts *live* sessions, so a revoked one must not
        // keep the Super Admin locked out of the feature for eight hours.
        $this->withToken($original)->postJson(self::endpointFor($second))->assertStatus(201);
    }

    public function test_an_ordinary_session_cannot_leave_an_impersonation_it_is_not_in(): void
    {
        $user = $this->userWith(RoleName::IndoorSales);

        $this->withToken($this->tokenFor($user))->postJson(self::LEAVE)
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.code', ImpersonationRefusal::NotImpersonating->value);

        self::assertSame([], $this->auditRows(IdentityAuditEvents::IMPERSONATION_ENDED));
    }

    /**
     * `leave` is a literal segment and `{user}` is a wildcard that would eat it.
     *
     * Laravel matches in registration order, so with the two swapped a Super
     * Admin is stuck inside somebody else's account until `D-29`'s eight idle
     * hours expire the session.
     */
    public function test_the_leave_route_is_not_swallowed_by_the_user_wildcard(): void
    {
        $superAdmin = $this->userWith(RoleName::SuperAdmin);
        $target = $this->userWith(RoleName::IndoorSales);

        $token = $this->withToken($this->tokenFor($superAdmin))
            ->postJson(self::endpointFor($target))
            ->assertStatus(201)
            ->json('data.token');

        self::assertIsString($token);

        // 200, not the 404 an impersonation of a user called "leave" would give.
        $this->withToken($token)->postJson(self::LEAVE)->assertStatus(200);
    }

    // ── i18n ────────────────────────────────────────────────────────────────

    public function test_every_refusal_message_exists_in_both_languages(): void
    {
        foreach (ImpersonationRefusal::cases() as $reason) {
            foreach (['en', 'ar'] as $locale) {
                $message = (string) __($reason->messageKey(), [], $locale);

                self::assertNotSame($reason->messageKey(), $message, "Missing {$locale}: {$reason->value}");
                self::assertNotSame('', trim($message));
            }
        }

        self::assertMatchesRegularExpression(
            '/\p{Arabic}/u',
            (string) __(ImpersonationRefusal::TargetSuspended->messageKey(), [], 'ar'),
        );
    }
}
