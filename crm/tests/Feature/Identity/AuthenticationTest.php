<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Http\Middleware\AddRequestId;
use App\Modules\Identity\Domain\Authentication\IdentityAuditEvents;
use App\Modules\Identity\Domain\Authentication\IdleTimeout;
use App\Modules\Identity\Domain\Authentication\LockoutPolicy;
use App\Modules\Identity\Domain\Authentication\SessionToken;
use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Permission;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Identity\Infrastructure\Eloquent\UserSession;
use App\Modules\Identity\Infrastructure\Notifications\AccountLockedNotification;
use App\Modules\Identity\Presentation\ApiEnvelope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Point 2.2 — §9 Flow 0, and the security rules that hang off it.
 *
 * `SEC-02` · `SEC-03` · `SEC-05` · `SEC-11` · `SEC-16` · `D-28` · `D-29` ·
 * `D-34` · `AUD-01` · `AUD-05` · `OpenAPI §3.1`, §4.1, §5, §5.1.
 *
 * Several expectations are **read out of the master documentation** rather than
 * transcribed. The suspension message, the lockout threshold and the session
 * timeout are all quoted in `§10.1`, `SEC-03` and `SEC-05`, and a test that
 * repeats them from memory passes just as happily when the code and the
 * document have drifted apart.
 */
final class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    /** Mounted read-only into the container by docker-compose.yml (Point 8.4). */
    private const MASTER_DOCUMENTATION = '/opt/crm/docs/CRM_Documentation_EN.md';

    private const PASSWORD = 'Passw0rd123';

    private const WRONG = 'Wr0ngPassword';

    protected function setUp(): void
    {
        parent::setUp();

        // A deterministic lockout window, so the assertions are about SEC-03
        // and not about whichever value the environment happens to carry.
        Config::set('identity.lockout_minutes', 30);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function role(RoleName $name = RoleName::IndoorSales): Role
    {
        $role = Role::query()->firstOrCreate(
            ['slug' => $name->value],
            ['name' => $name->label(), 'is_system' => true],
        );

        self::assertInstanceOf(Role::class, $role);

        return $role;
    }

    private function user(bool $active = true, RoleName $role = RoleName::IndoorSales): User
    {
        $user = new User;
        $user->fill([
            'name' => 'Test Person',
            'email' => 'person@example.test',
            'password' => self::PASSWORD,   // the `hashed` cast does SEC-02's half
            'role_id' => $this->role($role)->id,
            'is_active' => $active,
            'is_hidden' => false,
        ]);
        $user->save();

        return $user;
    }

    /** @return TestResponse<\Illuminate\Http\JsonResponse> */
    private function login(string $email = 'person@example.test', string $password = self::PASSWORD): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
    }

    private function tokenFor(User $user): string
    {
        $response = $this->login($user->email)->assertStatus(201);

        $token = $response->json('data.token');

        self::assertIsString($token);

        return $token;
    }

    /** @return list<array<string, mixed>> */
    private function auditRows(string $event): array
    {
        $rows = [];

        foreach (DB::select('SELECT * FROM audit_log WHERE event = ? ORDER BY created_at', [$event]) as $row) {
            // Cast to a plain array rather than reading properties off a raw
            // stdClass: level 10 forbids the latter, and `assertSame` on two
            // stdClass values compares identity rather than contents anyway.
            /** @var array<string, mixed> $fields */
            $fields = (array) $row;

            $rows[] = $fields;
        }

        return $rows;
    }

    /**
     * The first capture of `$pattern` in the master documentation.
     *
     * A helper rather than three copies, because the interesting failure is
     * always the same one: the document was reworded and the pattern silently
     * matched nothing, which an unguarded `$row[1]` turns into a notice instead
     * of a red test.
     */
    private static function capturedFromDocumentation(string $pattern, string $message): string
    {
        self::assertSame(1, preg_match($pattern, self::documentation(), $row), $message);

        $value = $row[1] ?? null;

        self::assertIsString($value, $message);

        return $value;
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

    // ── the documented numbers and strings ─────────────────────────────────

    public function test_the_lockout_threshold_is_the_one_sec_03_states(): void
    {
        $threshold = self::capturedFromDocumentation(
            '/^\|\s*SEC-03\s*\|\s*Lockout after (\d+) failures/mu',
            'SEC-03 no longer reads as this test expects; the threshold cannot be checked.',
        );

        self::assertSame(
            (int) $threshold,
            LockoutPolicy::MAX_ATTEMPTS,
            'LockoutPolicy disagrees with SEC-03 about how many failures lock an account.',
        );
    }

    public function test_the_idle_timeout_is_the_one_d_29_states(): void
    {
        $hours = self::capturedFromDocumentation(
            '/^\|\s*D-29\s*\|\s*Session expires after \*\*(\d+) hours idle\*\*/mu',
            'D-29 no longer reads as this test expects.',
        );

        self::assertSame((int) $hours, IdleTimeout::HOURS);
    }

    public function test_the_suspension_message_is_the_one_section_10_1_prints(): void
    {
        $message = self::capturedFromDocumentation(
            '/\|\s*Login\s*\|\s*(?:\*\*)?Blocked(?:\*\*)?\s*—\s*"([^"]+)"/u',
            '§10.1 no longer prints the suspension message this test reads.',
        );

        // The lang file is allowed a full stop the table omits; nothing else.
        self::assertSame(
            $message,
            rtrim((string) __('identity.refusal.account_suspended', [], 'en'), '.'),
        );
    }

    public function test_passwords_are_hashed_with_argon2_or_bcrypt(): void
    {
        // SEC-02: "Argon2 or bcrypt". Not a third thing, and not plaintext.
        self::assertContains(
            Config::string('hashing.driver'),
            ['bcrypt', 'argon', 'argon2id'],
            'SEC-02 allows Argon2 or bcrypt and nothing else.',
        );
    }

    public function test_the_envelope_mirrors_the_middlewares_request_attribute(): void
    {
        // ApiEnvelope duplicates this string because deptrac cannot see
        // app/Http from inside a module. This is the assertion that notices the
        // duplication drifting — the same guard RequestAuditContext carries.
        self::assertSame(AddRequestId::ATTRIBUTE, ApiEnvelope::REQUEST_ATTRIBUTE);
    }

    // ── login ───────────────────────────────────────────────────────────────

    public function test_valid_credentials_open_a_session(): void
    {
        $user = $this->user();

        $response = $this->login()->assertStatus(201);

        // OpenAPI §4.1 — data plus meta.request_id.
        $response->assertJsonStructure([
            'data' => ['token', 'token_type', 'idle_timeout_seconds', 'user' => ['id', 'email', 'role', 'permissions']],
            'meta' => ['request_id'],
        ]);

        self::assertSame('Bearer', $response->json('data.token_type'));
        self::assertSame(IdleTimeout::HOURS * 3600, $response->json('data.idle_timeout_seconds'));
        self::assertSame($user->id, $response->json('data.user.id'));
        self::assertNotNull($response->json('meta.request_id'));

        self::assertSame(1, UserSession::query()->where('user_id', $user->id)->count());
    }

    public function test_the_stored_session_holds_a_digest_and_never_the_token(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user);

        $session = UserSession::query()->where('user_id', $user->id)->firstOrFail();

        // Coding Standards §9: never expose session tokens. Storing one is the
        // same failure a day later.
        self::assertNotSame($token, $session->session_id);
        self::assertSame(hash('sha256', $token), $session->session_id);
        self::assertSame(SessionToken::LENGTH, mb_strlen($token));
    }

    public function test_the_response_carries_no_password_and_no_security_counters(): void
    {
        $this->user();

        $body = $this->login()->assertStatus(201)->getContent();

        self::assertIsString($body);
        self::assertStringNotContainsString('password', $body);
        self::assertStringNotContainsString('failed_login_attempts', $body);
        self::assertStringNotContainsString('locked_until', $body);
        self::assertStringNotContainsString('is_hidden', $body);
    }

    public function test_a_wrong_password_is_refused_without_saying_why(): void
    {
        $this->user();

        $this->login('person@example.test', self::WRONG)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'authentication_required');
    }

    public function test_an_unknown_address_is_refused_and_writes_no_audit_row(): void
    {
        $this->login('nobody@example.test')->assertStatus(401);

        // audit_log.entity_id is UUID NOT NULL and there is no entity, so
        // SEC-16's failed-login record for an unknown address is the structured
        // log line only. This pins the known gap so it cannot be forgotten.
        self::assertSame([], $this->auditRows(IdentityAuditEvents::LOGIN_FAILED));
    }

    public function test_a_missing_field_is_a_422_in_the_unified_envelope(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'person@example.test'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.details.0.field', 'password')
            ->assertJsonStructure(['error' => ['code', 'message', 'details'], 'meta' => ['request_id']]);
    }

    // ── D-34: the deactivated employee ──────────────────────────────────────

    public function test_a_deactivated_account_is_told_it_is_suspended(): void
    {
        $this->user(active: false);

        $this->login()
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'permission_denied')
            ->assertJsonPath('error.details.0.code', 'account_suspended')
            ->assertJsonPath('error.message', (string) __('identity.refusal.account_suspended', [], 'en'));
    }

    public function test_the_suspension_message_is_arabic_when_the_caller_asks_for_arabic(): void
    {
        $this->user(active: false);

        $response = $this->withHeader('Accept-Language', 'ar')
            ->postJson('/api/v1/auth/login', ['email' => 'person@example.test', 'password' => self::PASSWORD])
            ->assertStatus(403);

        self::assertSame((string) __('identity.refusal.account_suspended', [], 'ar'), $response->json('error.message'));
        self::assertMatchesRegularExpression('/\p{Arabic}/u', (string) $response->json('error.message'));
    }

    public function test_a_deactivated_account_produces_the_login_blocked_audit_row(): void
    {
        $user = $this->user(active: false);

        $this->login()->assertStatus(403);

        $rows = $this->auditRows(IdentityAuditEvents::LOGIN_BLOCKED);

        self::assertCount(1, $rows);
        self::assertSame($user->id, $rows[0]['entity_id']);
    }

    public function test_a_session_dies_the_moment_the_account_is_deactivated(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user);

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(200);

        $user->is_active = false;
        $user->save();

        // OpenAPI §3.1: "Deactivated … accounts cannot call protected endpoints."
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    // ── SEC-03: lockout ─────────────────────────────────────────────────────

    public function test_four_failures_do_not_lock_the_account(): void
    {
        $user = $this->user();

        for ($i = 0; $i < LockoutPolicy::MAX_ATTEMPTS - 1; $i++) {
            $this->login('person@example.test', self::WRONG)->assertStatus(401);
        }

        $user->refresh();

        self::assertSame(LockoutPolicy::MAX_ATTEMPTS - 1, $user->failed_login_attempts);
        self::assertNull($user->locked_until);
    }

    public function test_five_failures_lock_the_account_and_notify_the_super_admin(): void
    {
        Notification::fake();

        $user = $this->user();

        $superAdmin = new User;
        $superAdmin->fill([
            'name' => 'Test Super Admin',
            'email' => 'super.admin@example.test',
            'password' => self::PASSWORD,
            'role_id' => $this->role(RoleName::SuperAdmin)->id,
            'is_active' => true,
            'is_hidden' => true,
        ]);
        $superAdmin->save();

        for ($i = 1; $i < LockoutPolicy::MAX_ATTEMPTS; $i++) {
            $this->login('person@example.test', self::WRONG)->assertStatus(401);
        }

        // The fifth is the one that locks, so it is the one that says so.
        $this->login('person@example.test', self::WRONG)
            ->assertStatus(423)
            ->assertJsonPath('error.code', 'account_locked');

        $user->refresh();

        self::assertSame(LockoutPolicy::MAX_ATTEMPTS, $user->failed_login_attempts);
        self::assertNotNull($user->locked_until);
        self::assertTrue($user->locked_until->greaterThan(Carbon::now()));

        self::assertCount(LockoutPolicy::MAX_ATTEMPTS, $this->auditRows(IdentityAuditEvents::LOGIN_FAILED));
        self::assertCount(1, $this->auditRows(IdentityAuditEvents::ACCOUNT_LOCKED));

        // SEC-03's second half. §3.12 rule 6 hides Super Admin from lists —
        // hidden is not absent, and the account still gets told.
        Notification::assertSentTo($superAdmin, AccountLockedNotification::class);
    }

    public function test_exactly_five_wrong_passwords_lock_the_account(): void
    {
        // Written with the literal 5 on purpose. Every other lockout test is
        // phrased in terms of LockoutPolicy::MAX_ATTEMPTS, so raising the
        // constant moves them with it and they keep passing — this one and the
        // SEC-03 documentation pin are what actually hold the number down.
        $user = $this->user();

        for ($i = 0; $i < 4; $i++) {
            $this->login('person@example.test', self::WRONG)->assertStatus(401);
        }

        $user->refresh();
        self::assertNull($user->locked_until, 'Four failures must not lock the account.');

        $this->login('person@example.test', self::WRONG)->assertStatus(423);

        $user->refresh();
        self::assertNotNull($user->locked_until, 'The fifth failure must lock the account (SEC-03).');
    }

    public function test_a_locked_account_is_refused_even_with_the_right_password(): void
    {
        $user = $this->user();
        $user->failed_login_attempts = LockoutPolicy::MAX_ATTEMPTS;
        $user->locked_until = Carbon::now()->addMinutes(30);
        $user->save();

        $this->login()->assertStatus(423)->assertJsonPath('error.code', 'account_locked');
    }

    public function test_an_expired_lock_lets_the_account_back_in(): void
    {
        $user = $this->user();
        $user->failed_login_attempts = LockoutPolicy::MAX_ATTEMPTS;
        $user->locked_until = Carbon::now()->subMinute();
        $user->save();

        $this->login()->assertStatus(201);

        $user->refresh();

        self::assertSame(0, $user->failed_login_attempts);
        self::assertNull($user->locked_until);
    }

    public function test_a_successful_login_clears_the_failure_count(): void
    {
        $user = $this->user();

        $this->login('person@example.test', self::WRONG)->assertStatus(401);
        $this->login()->assertStatus(201);

        $user->refresh();

        self::assertSame(0, $user->failed_login_attempts);
    }

    // ── D-29 / SEC-05: the idle session ─────────────────────────────────────

    public function test_a_session_idle_for_eight_hours_stops_working(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user);

        $session = UserSession::query()->where('user_id', $user->id)->firstOrFail();
        $session->last_activity_at = Carbon::now()->subHours(IdleTimeout::HOURS)->subMinute();
        $session->save();

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);

        // The row is retired rather than left to answer "expired" for ever —
        // SEC-05's device list would otherwise keep showing it.
        self::assertNull(UserSession::query()->find($session->id));
        self::assertNotNull(UserSession::withTrashed()->find($session->id));
    }

    public function test_a_session_just_inside_the_window_still_works(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user);

        $session = UserSession::query()->where('user_id', $user->id)->firstOrFail();
        $session->last_activity_at = Carbon::now()->subHours(IdleTimeout::HOURS)->addMinute();
        $session->save();

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(200);
    }

    public function test_activity_pushes_the_idle_clock_forward(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user);

        $session = UserSession::query()->where('user_id', $user->id)->firstOrFail();
        $session->last_activity_at = Carbon::now()->subHours(IdleTimeout::HOURS)->addMinutes(5);
        $session->save();

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(200);

        $session->refresh();

        // D-29 measures idle time, so a used session is not an idle one.
        self::assertTrue($session->last_activity_at->greaterThan(Carbon::now()->subMinute()));
    }

    // ── /me and logout ──────────────────────────────────────────────────────

    public function test_me_returns_the_permissions_the_database_holds(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = $this->user(role: RoleName::Procurement);
        $token = $this->tokenFor($user);

        $response = $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(200);

        $permissions = $response->json('data.permissions');

        self::assertIsArray($permissions);
        self::assertNotSame([], $permissions, 'SEC-07 puts the matrix in the database; this role holds none of it.');

        $expected = Role::query()->where('slug', RoleName::Procurement->value)
            ->firstOrFail()
            ->permissions()->get()
            ->map(static fn (Permission $permission): string => $permission->triple())
            ->sort()->values()->all();

        self::assertSame($expected, $permissions);
        self::assertFalse($response->json('data.unconditional_access'));
    }

    public function test_super_admin_is_reported_as_holding_everything(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = $this->user(role: RoleName::SuperAdmin);
        $token = $this->tokenFor($user);

        $response = $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(200);

        // §3.1 gives Super Admin access the matrix cells do not describe, so
        // the flag is stated rather than inferred from the list's length.
        self::assertTrue($response->json('data.unconditional_access'));
        self::assertSame(
            DB::table('permissions')->whereNull('deleted_at')->count(),
            count((array) $response->json('data.permissions')),
        );
    }

    public function test_me_without_a_token_is_a_401_in_the_unified_envelope(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'authentication_required')
            ->assertJsonStructure(['error' => ['code', 'message'], 'meta' => ['request_id']]);
    }

    public function test_a_forged_token_is_refused(): void
    {
        $this->user();

        $this->withToken(str_repeat('a', SessionToken::LENGTH))
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);

        $this->withToken('not-a-token')->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_logout_revokes_the_session_and_the_token_stops_working(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user);

        $this->withToken($token)->postJson('/api/v1/auth/logout')
            ->assertStatus(200)
            ->assertJsonPath('data.signed_out', true);

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);

        // DB-01: revoked is a soft delete, so the row survives for the audit.
        self::assertSame(0, UserSession::query()->where('user_id', $user->id)->count());
        self::assertSame(1, UserSession::withTrashed()->where('user_id', $user->id)->count());

        self::assertCount(1, $this->auditRows(IdentityAuditEvents::LOGOUT));
    }

    public function test_logging_out_of_one_device_leaves_the_other_signed_in(): void
    {
        $user = $this->user();

        $laptop = $this->tokenFor($user);
        $phone = $this->tokenFor($user);

        $this->withToken($laptop)->postJson('/api/v1/auth/logout')->assertStatus(200);

        // SEC-05's device list only means something if this is true.
        $this->withToken($laptop)->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->withToken($phone)->getJson('/api/v1/auth/me')->assertStatus(200);
    }

    public function test_logout_without_a_token_is_a_401(): void
    {
        $this->postJson('/api/v1/auth/logout')->assertStatus(401);
    }

    // ── AUD-01: the successful login is recorded ────────────────────────────

    public function test_a_successful_login_is_audited_with_its_actor_and_ip(): void
    {
        $user = $this->user();

        $this->login()->assertStatus(201);

        $rows = $this->auditRows(IdentityAuditEvents::LOGIN_SUCCEEDED);

        self::assertCount(1, $rows);
        self::assertSame($user->id, $rows[0]['entity_id']);
        self::assertSame('user', $rows[0]['entity_type']);
        // AUD-02 wants the IP; the login itself is unauthenticated, so the
        // actor column is null and the entity is who it happened to.
        self::assertNotNull($rows[0]['ip_address']);

        $recorded = $rows[0]['new_values'];

        self::assertIsString($recorded);

        $newValues = json_decode($recorded, true);

        self::assertIsArray($newValues);
        self::assertArrayHasKey('session', $newValues);
        // The row names the session, never the credential.
        self::assertArrayNotHasKey('token', $newValues);
    }

    // ── SEC-11: rate limiting ───────────────────────────────────────────────

    public function test_a_burst_of_login_attempts_is_throttled(): void
    {
        Config::set('identity.rate_limit.login.attempts', 3);
        Config::set('identity.rate_limit.login.decay_minutes', 1);

        $this->user();

        for ($i = 0; $i < 3; $i++) {
            $this->login('person@example.test', self::WRONG)->assertStatus(401);
        }

        $throttled = $this->login('person@example.test', self::WRONG)->assertStatus(429);

        $throttled->assertJsonPath('error.code', 'rate_limit_exceeded');
        // §5.1 makes Retry-After mandatory on a 429.
        self::assertNotNull($throttled->headers->get('Retry-After'));
    }

    public function test_the_login_limit_is_configuration_and_not_a_constant(): void
    {
        // OpenAPI §10: "the concrete limits are configurable system settings,
        // not client constants". Two different settings must behave differently.
        Config::set('identity.rate_limit.login.attempts', 1);

        $this->user();

        $this->login('person@example.test', self::WRONG)->assertStatus(401);
        $this->login('person@example.test', self::WRONG)->assertStatus(429);
    }

    public function test_the_rate_limit_is_keyed_per_address_not_only_per_source(): void
    {
        Config::set('identity.rate_limit.login.attempts', 1);

        $this->user();

        $this->login('person@example.test', self::WRONG)->assertStatus(401);

        // Same IP, different address: one person's bad morning must not lock a
        // whole office out from behind one NAT address.
        $this->login('someone.else@example.test', self::WRONG)->assertStatus(401);
    }
}
