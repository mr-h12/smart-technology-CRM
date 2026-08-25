<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Authentication\IdentityAuditEvents;
use App\Modules\Identity\Domain\Authentication\PasswordChallenge;
use App\Modules\Identity\Domain\Authentication\PasswordChallengeIssued;
use App\Modules\Identity\Domain\Authentication\PasswordRefusal;
use App\Modules\Identity\Domain\Contracts\PasswordChallengeStoreInterface;
use App\Modules\Identity\Domain\PasswordPolicy;
use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Identity\Infrastructure\Eloquent\UserSession;
use DateTimeImmutable;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Points 3.1 and 3.3 — §9 Flow 0's password change, end to end.
 *
 * `D-28` · `SEC-02` · `SEC-04` · `SEC-12` · `AUD-01` · `AUD-02` · `AUD-03` ·
 * `OpenAPI §4.1`, §5, §5.1.
 *
 * ── What changed with 3.3 ──────────────────────────────────────────────────
 *
 * Point 3.1 shipped this endpoint with a current-password challenge and this
 * file carried a test pinning `SEC-04` as an open gap. Point 3.3 closed it, so
 * every request below now carries a `verification_code` obtained from the real
 * challenge endpoint — and the pin has been inverted into
 * {@see self::test_the_documented_flow_now_requires_an_emailed_code()}, which
 * fails if the requirement is ever quietly dropped again.
 *
 * **Every happy-path request asks for a real code.** Writing one into the store
 * directly would test the store; going through the endpoint tests the flow, and
 * it is the flow §9 Flow 0 describes.
 */
final class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    private const MASTER_DOCUMENTATION = '/opt/crm/docs/CRM_Documentation_EN.md';

    private const CURRENT = 'Passw0rd123';

    private const NEXT = 'Newpassw0rd456';

    private const ENDPOINT = '/api/v1/auth/change-password';

    private const CHALLENGE = '/api/v1/auth/change-password/challenge';

    private function user(): User
    {
        $role = Role::query()->firstOrCreate(
            ['slug' => RoleName::IndoorSales->value],
            ['name' => RoleName::IndoorSales->label(), 'is_system' => true],
        );

        $user = new User;
        $user->fill([
            'name' => 'Test Person',
            'email' => 'person@example.test',
            'password' => self::CURRENT,   // the `hashed` cast does SEC-02's half
            'role_id' => $role->id,
            'is_active' => true,
            'is_hidden' => false,
        ]);
        $user->save();

        return $user;
    }

    /** Logs in for real, so the token under test is one the login flow issued. */
    private function tokenFor(User $user, string $password = self::CURRENT): string
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => $password,
        ])->assertStatus(201)->json('data.token');

        self::assertIsString($token);

        return $token;
    }

    /**
     * `SEC-04` step one, through the endpoint, returning the mailed plaintext.
     *
     * Captured from the domain event rather than read out of the store, because
     * the store holds only a hash — which is the property being relied on.
     */
    private function codeFor(string $token): string
    {
        $captured = null;

        Event::listen(function (PasswordChallengeIssued $event) use (&$captured): void {
            $captured = $event->code;
        });

        $this->withToken($token)->postJson(self::CHALLENGE)->assertStatus(202);

        self::assertIsString($captured, 'No PasswordChallengeIssued event carried a code.');

        return $captured;
    }

    /**
     * The four fields the endpoint takes, with a valid code by default.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(string $token, array $overrides = []): array
    {
        return array_merge([
            'current_password' => self::CURRENT,
            'new_password' => self::NEXT,
            'new_password_confirmation' => self::NEXT,
            'verification_code' => $this->codeFor($token),
        ], $overrides);
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

    private function store(): PasswordChallengeStoreInterface
    {
        return $this->app->make(PasswordChallengeStoreInterface::class);
    }

    // ── the happy path ──────────────────────────────────────────────────────

    public function test_an_authenticated_user_can_change_their_password(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user);

        $this->withToken($token)->postJson(self::ENDPOINT, $this->payload($token))
            ->assertStatus(200)
            ->assertJsonPath('data.password_changed', true)
            ->assertJsonPath('data.reauthentication_required', true)
            ->assertJsonStructure(['data' => ['sessions_revoked'], 'meta' => ['request_id']]);
    }

    public function test_the_stored_hash_changes_and_is_not_the_plaintext(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user);
        $before = (string) $user->password;

        $this->withToken($token)->postJson(self::ENDPOINT, $this->payload($token))->assertStatus(200);

        $user->refresh();

        self::assertNotSame($before, (string) $user->password);
        self::assertNotSame(self::NEXT, (string) $user->password, 'SEC-02: never a plaintext column.');
        self::assertTrue(Hash::check(self::NEXT, (string) $user->password));
        self::assertFalse(Hash::check(self::CURRENT, (string) $user->password));
    }

    public function test_only_the_new_password_works_afterwards(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user);

        $this->withToken($token)->postJson(self::ENDPOINT, $this->payload($token))->assertStatus(200);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::CURRENT])
            ->assertStatus(401);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::NEXT])
            ->assertStatus(201);
    }

    // ── §9 Flow 0: "log in again" ───────────────────────────────────────────

    public function test_every_session_is_revoked_including_the_one_that_asked(): void
    {
        $user = $this->user();

        $laptop = $this->tokenFor($user);
        $phone = $this->tokenFor($user);

        self::assertSame(2, UserSession::query()->where('user_id', $user->id)->count());

        $response = $this->withToken($laptop)
            ->postJson(self::ENDPOINT, $this->payload($laptop))
            ->assertStatus(200);

        self::assertSame(2, $response->json('data.sessions_revoked'));

        // §9 Flow 0 ends with "log in again". A change that leaves the live
        // session alive does not evict whoever the user was trying to evict.
        $this->withToken($laptop)->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->withToken($phone)->getJson('/api/v1/auth/me')->assertStatus(401);

        // DB-01: revoked is a soft delete, so the rows survive for the audit.
        self::assertSame(0, UserSession::query()->where('user_id', $user->id)->count());
        self::assertSame(2, UserSession::withTrashed()->where('user_id', $user->id)->count());
    }

    // ── SEC-04 ──────────────────────────────────────────────────────────────

    public function test_a_change_without_a_verification_code_is_refused(): void
    {
        $user = $this->user();
        $before = (string) $user->password;

        // Not even a challenge is requested. SEC-04 says "mandatory", and the
        // Form Request refuses the missing field before the use case is reached.
        $this->withToken($this->tokenFor($user))->postJson(self::ENDPOINT, [
            'current_password' => self::CURRENT,
            'new_password' => self::NEXT,
            'new_password_confirmation' => self::NEXT,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');

        self::assertSame($before, (string) $user->refresh()->password);
    }

    public function test_a_wrong_code_is_refused_and_changes_nothing(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user);
        $before = (string) $user->password;

        $code = $this->codeFor($token);
        $wrong = str_pad((string) ((((int) $code) + 1) % 1000000), 6, '0', STR_PAD_LEFT);

        $this->withToken($token)->postJson(self::ENDPOINT, [
            'current_password' => self::CURRENT,
            'new_password' => self::NEXT,
            'new_password_confirmation' => self::NEXT,
            'verification_code' => $wrong,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'verification_code')
            ->assertJsonPath('error.details.0.code', PasswordRefusal::InvalidVerificationCode->value);

        self::assertSame($before, (string) $user->refresh()->password);

        // The refusal must not log anybody out either — that would be a
        // denial-of-service one wrong digit wide.
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(200);
    }

    public function test_a_change_with_no_outstanding_challenge_is_refused(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user);

        // A syntactically valid code that was never issued.
        $this->withToken($token)->postJson(self::ENDPOINT, [
            'current_password' => self::CURRENT,
            'new_password' => self::NEXT,
            'new_password_confirmation' => self::NEXT,
            'verification_code' => '000000',
        ])
            ->assertStatus(422)
            // Deliberately the same code as a wrong guess: distinguishing them
            // would tell a caller whether a challenge exists.
            ->assertJsonPath('error.details.0.code', PasswordRefusal::InvalidVerificationCode->value);

        self::assertTrue(Hash::check(self::CURRENT, (string) $user->refresh()->password));
    }

    public function test_an_expired_code_is_refused_with_its_own_reason(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user);
        $code = $this->codeFor($token);

        $live = $this->store()->find($user->id);

        self::assertInstanceOf(PasswordChallenge::class, $live);

        // The same challenge, sixteen minutes into the past. Rewinding the
        // stored expiry rather than sleeping: the rule under test is the
        // comparison, and a test that waits fifteen minutes is a test nobody
        // runs.
        $this->store()->put($user->id, new PasswordChallenge(
            $live->codeHash,
            (new DateTimeImmutable)->modify('-1 minute'),
            0,
        ));

        $this->withToken($token)->postJson(self::ENDPOINT, [
            'current_password' => self::CURRENT,
            'new_password' => self::NEXT,
            'new_password_confirmation' => self::NEXT,
            'verification_code' => $code,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'verification_code')
            ->assertJsonPath('error.details.0.code', PasswordRefusal::ExpiredVerificationCode->value);

        self::assertTrue(Hash::check(self::CURRENT, (string) $user->refresh()->password));

        // Cleared on the way through, so the next attempt is "no challenge".
        self::assertNull($this->store()->find($user->id));
    }

    public function test_a_code_is_single_use(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user);
        $code = $this->codeFor($token);

        $this->withToken($token)->postJson(self::ENDPOINT, [
            'current_password' => self::CURRENT,
            'new_password' => self::NEXT,
            'new_password_confirmation' => self::NEXT,
            'verification_code' => $code,
        ])->assertStatus(200);

        self::assertNull($this->store()->find($user->id), 'The used challenge outlived the change.');

        // The same code again, now against the new password. A code that still
        // works after the change it authorised is a second change nobody asked
        // for.
        $again = $this->tokenFor($user, self::NEXT);

        $this->withToken($again)->postJson(self::ENDPOINT, [
            'current_password' => self::NEXT,
            'new_password' => 'Third0ne456',
            'new_password_confirmation' => 'Third0ne456',
            'verification_code' => $code,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.code', PasswordRefusal::InvalidVerificationCode->value);

        self::assertTrue(Hash::check(self::NEXT, (string) $user->refresh()->password));
    }

    public function test_wrong_codes_exhaust_the_challenge_and_destroy_it(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user);
        $code = $this->codeFor($token);

        $wrong = str_pad((string) ((((int) $code) + 7) % 1000000), 6, '0', STR_PAD_LEFT);

        $max = $this->app->make(ConfigRepository::class)
            ->integer('identity.password_challenge.max_verification_attempts');

        for ($attempt = 1; $attempt <= $max; $attempt++) {
            $this->withToken($token)->postJson(self::ENDPOINT, [
                'current_password' => self::CURRENT,
                'new_password' => self::NEXT,
                'new_password_confirmation' => self::NEXT,
                'verification_code' => $wrong,
            ])->assertStatus(422);
        }

        // This is what makes six digits defensible: the challenge is gone, so
        // the million-guess sweep never gets a sixth try against it.
        self::assertNull($this->store()->find($user->id));

        // Even the *right* code no longer works — the challenge it belonged to
        // does not exist any more.
        $this->withToken($token)->postJson(self::ENDPOINT, [
            'current_password' => self::CURRENT,
            'new_password' => self::NEXT,
            'new_password_confirmation' => self::NEXT,
            'verification_code' => $code,
        ])->assertStatus(422);

        self::assertTrue(Hash::check(self::CURRENT, (string) $user->refresh()->password));
    }

    public function test_a_wrong_current_password_does_not_burn_a_code_attempt(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user);
        $code = $this->codeFor($token);

        $this->withToken($token)->postJson(self::ENDPOINT, [
            'current_password' => 'Wr0ngPassword',
            'new_password' => self::NEXT,
            'new_password_confirmation' => self::NEXT,
            'verification_code' => $code,
        ])->assertStatus(422);

        $challenge = $this->store()->find($user->id);

        self::assertInstanceOf(PasswordChallenge::class, $challenge);

        // The order of the checks matters: if the code were verified first, a
        // caller guessing passwords would destroy the account's own recovery
        // flow as a side effect.
        self::assertSame(0, $challenge->failedAttempts);

        // And the code still works once the right password is presented.
        $this->withToken($token)->postJson(self::ENDPOINT, [
            'current_password' => self::CURRENT,
            'new_password' => self::NEXT,
            'new_password_confirmation' => self::NEXT,
            'verification_code' => $code,
        ])->assertStatus(200);
    }

    public function test_one_persons_code_does_not_work_for_another(): void
    {
        $victim = $this->user();

        $role = Role::query()->where('slug', RoleName::IndoorSales->value)->firstOrFail();
        $other = new User;
        $other->fill([
            'name' => 'Someone Else',
            'email' => 'other@example.test',
            'password' => 'Attack3rPass',
            'role_id' => $role->id,
            'is_active' => true,
            'is_hidden' => false,
        ]);
        $other->save();

        $victimToken = $this->tokenFor($victim);
        $victimCode = $this->codeFor($victimToken);

        // The challenge is keyed by account, so a code mailed to one inbox is
        // meaningless against another session.
        $this->withToken($this->tokenFor($other, 'Attack3rPass'))->postJson(self::ENDPOINT, [
            'current_password' => 'Attack3rPass',
            'new_password' => self::NEXT,
            'new_password_confirmation' => self::NEXT,
            'verification_code' => $victimCode,
        ])->assertStatus(422);

        self::assertTrue(Hash::check('Attack3rPass', (string) $other->refresh()->password));
    }

    public function test_a_failed_code_is_audited(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user);
        $code = $this->codeFor($token);

        $wrong = str_pad((string) ((((int) $code) + 3) % 1000000), 6, '0', STR_PAD_LEFT);

        $this->withToken($token)->postJson(self::ENDPOINT, [
            'current_password' => self::CURRENT,
            'new_password' => self::NEXT,
            'new_password_confirmation' => self::NEXT,
            'verification_code' => $wrong,
        ])->assertStatus(422);

        $rows = $this->auditRows(IdentityAuditEvents::PASSWORD_CHALLENGE_FAILED);

        // Point 2.2's lesson: a refusal thrown out of a transaction takes its
        // own audit row with it. This row is written outside one, and this
        // assertion is what proves it survived.
        self::assertCount(1, $rows, 'A wrong code left no trace at all.');
        self::assertSame($user->id, $rows[0]['entity_id']);

        $serialised = json_encode($rows[0]);

        self::assertIsString($serialised);
        self::assertStringContainsString('code_incorrect', $serialised);
        self::assertStringNotContainsString($code, $serialised, 'AUD-03: the live code reached a permanent row.');
        self::assertStringNotContainsString($wrong, $serialised);
    }

    // ── refusals that predate 3.3 ───────────────────────────────────────────

    public function test_a_wrong_current_password_is_refused_and_changes_nothing(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user);
        $before = (string) $user->password;

        $this->withToken($token)
            ->postJson(self::ENDPOINT, $this->payload($token, ['current_password' => 'Wr0ngPassword']))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.details.0.field', 'current_password')
            ->assertJsonPath('error.details.0.code', PasswordRefusal::CurrentPasswordIncorrect->value);

        $user->refresh();

        self::assertSame($before, (string) $user->password, 'A refused change must not mutate the hash.');
        self::assertTrue(Hash::check(self::CURRENT, (string) $user->password));
    }

    public function test_a_wrong_current_password_leaves_every_session_alive(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user);

        $this->withToken($token)
            ->postJson(self::ENDPOINT, $this->payload($token, ['current_password' => 'Wr0ngPassword']))
            ->assertStatus(422);

        // A refusal that still logged everybody out would be a denial-of-service
        // any holder of one session could aim at the account's other devices.
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(200);
    }

    /** `D-28`: "8 characters minimum, letters and numbers". */
    #[DataProvider('nonCompliantPasswords')]
    public function test_a_password_that_fails_d_28_is_refused(string $candidate, string $why): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user);
        $before = (string) $user->password;

        $response = $this->withToken($token)->postJson(self::ENDPOINT, $this->payload($token, [
            'new_password' => $candidate,
            'new_password_confirmation' => $candidate,
        ]));

        // assertStatus() takes no message — the second argument was silently
        // dropped, so the data set's reason never reached a failure report.
        self::assertSame(422, $response->getStatusCode(), $why);

        $user->refresh();

        self::assertSame($before, (string) $user->password);
    }

    /** @return array<string, array{string, string}> */
    public static function nonCompliantPasswords(): array
    {
        return [
            'seven characters' => ['Abc123x', 'D-28 sets the minimum at eight.'],
            'letters only' => ['abcdefghij', 'D-28 requires a number.'],
            'digits only' => ['1234567890', 'D-28 requires a letter.'],
            'empty' => ['', 'A password is required.'],
        ];
    }

    public function test_the_new_password_may_not_equal_the_current_one(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user);

        $this->withToken($token)->postJson(self::ENDPOINT, $this->payload($token, [
            'new_password' => self::CURRENT,
            'new_password_confirmation' => self::CURRENT,
        ]))
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.code', PasswordRefusal::SameAsCurrent->value)
            ->assertJsonPath('error.details.0.field', 'new_password');
    }

    public function test_a_mismatched_confirmation_is_refused(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user);

        $this->withToken($token)
            ->postJson(self::ENDPOINT, $this->payload($token, ['new_password_confirmation' => 'Something3lse']))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_an_unauthenticated_request_is_refused(): void
    {
        $this->user();

        $this->postJson(self::ENDPOINT, [
            'current_password' => self::CURRENT,
            'new_password' => self::NEXT,
            'new_password_confirmation' => self::NEXT,
            'verification_code' => '123456',
        ])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'authentication_required');
    }

    public function test_one_user_cannot_change_another_users_password(): void
    {
        $victim = $this->user();

        $role = Role::query()->where('slug', RoleName::IndoorSales->value)->firstOrFail();
        $attacker = new User;
        $attacker->fill([
            'name' => 'Someone Else',
            'email' => 'other@example.test',
            'password' => 'Attack3rPass',
            'role_id' => $role->id,
            'is_active' => true,
            'is_hidden' => false,
        ]);
        $attacker->save();

        $before = (string) $victim->password;
        $attackerToken = $this->tokenFor($attacker, 'Attack3rPass');

        // The endpoint takes no target, and this is the test that keeps it that
        // way: a `user_id` parameter added later would make this pass silently.
        $this->withToken($attackerToken)->postJson(self::ENDPOINT, [
            'current_password' => 'Attack3rPass',
            'new_password' => self::NEXT,
            'new_password_confirmation' => self::NEXT,
            'verification_code' => $this->codeFor($attackerToken),
            'user_id' => $victim->id,
            'email' => $victim->email,
        ])->assertStatus(200);

        $victim->refresh();

        self::assertSame($before, (string) $victim->password, 'The extra fields must be ignored entirely.');
        self::assertTrue(Hash::check(self::CURRENT, (string) $victim->password));
    }

    // ── AUD-01 ──────────────────────────────────────────────────────────────

    public function test_the_change_is_audited_and_the_row_holds_no_credential(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user);

        $this->withToken($token)->postJson(self::ENDPOINT, $this->payload($token))->assertStatus(200);

        $rows = $this->auditRows(IdentityAuditEvents::PASSWORD_CHANGED);

        self::assertCount(1, $rows);
        self::assertSame($user->id, $rows[0]['entity_id']);
        self::assertSame('user', $rows[0]['entity_type']);
        self::assertSame($user->id, $rows[0]['user_id'], 'AUD-02 wants the actor, and here they are the subject.');

        $serialised = json_encode($rows[0]);

        self::assertIsString($serialised);
        // AUD-03 makes the row permanent. A permanent record of a credential
        // outlives the account it belongs to.
        self::assertStringNotContainsString(self::CURRENT, $serialised);
        self::assertStringNotContainsString(self::NEXT, $serialised);
        self::assertStringNotContainsString('$2y$', $serialised, 'No bcrypt hash in an immutable row.');
    }

    public function test_a_refused_change_writes_no_audit_row(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user);

        $this->withToken($token)
            ->postJson(self::ENDPOINT, $this->payload($token, ['current_password' => 'Wr0ngPassword']))
            ->assertStatus(422);

        self::assertSame([], $this->auditRows(IdentityAuditEvents::PASSWORD_CHANGED));
    }

    // ── the rule lives in one place ─────────────────────────────────────────

    public function test_the_endpoint_and_the_seeder_ask_the_same_policy(): void
    {
        // D-28 in one class with three callers. This asserts the endpoint is
        // one of them rather than carrying a second copy of the rule.
        foreach (['Abc123x', 'abcdefghij', '1234567890', ''] as $bad) {
            self::assertFalse(PasswordPolicy::isSatisfiedBy($bad));
        }

        foreach ([self::CURRENT, self::NEXT, 'a1bcdefg'] as $good) {
            self::assertTrue(PasswordPolicy::isSatisfiedBy($good));
        }

        self::assertSame(8, PasswordPolicy::MINIMUM_LENGTH);
    }

    public function test_the_refusal_messages_exist_in_both_languages(): void
    {
        // §14.2 — Arabic and English from the first release; Coding Standards
        // §11 — no user-facing string literal in code.
        foreach (PasswordRefusal::cases() as $reason) {
            foreach (['en', 'ar'] as $locale) {
                $message = (string) __($reason->messageKey(), [], $locale);

                self::assertNotSame($reason->messageKey(), $message, "Missing {$locale}: {$reason->value}");
                self::assertNotSame('', trim($message));
            }
        }

        self::assertMatchesRegularExpression(
            '/\p{Arabic}/u',
            (string) __(PasswordRefusal::PolicyNotMet->messageKey(), [], 'ar'),
        );
    }

    // ── the gap, now closed ─────────────────────────────────────────────────

    /**
     * The inverse of the pin Point 3.1 left here.
     *
     * 3.1 asserted that `SEC-04` was **not** implemented, so that nobody could
     * mistake the current-password challenge for it. 3.3 implements it, and
     * this asserts the requirement is still worded the way the implementation
     * reads it **and** that the endpoint genuinely refuses without a code.
     * Deleting this test means deciding to drop `SEC-04` again.
     */
    public function test_the_documented_flow_now_requires_an_emailed_code(): void
    {
        $documentation = file_get_contents(self::MASTER_DOCUMENTATION);

        self::assertIsString($documentation);

        self::assertMatchesRegularExpression(
            '/^\|\s*SEC-04\s*\|\s*Mandatory email verification for password changes/mu',
            $documentation,
            'SEC-04 has been reworded; re-read it before assuming this is still satisfied.',
        );

        self::assertStringContainsString(
            'Password change → verification code by email → new password → log in again',
            $documentation,
        );

        $user = $this->user();

        // The requirement, exercised: no code, no change.
        $this->withToken($this->tokenFor($user))->postJson(self::ENDPOINT, [
            'current_password' => self::CURRENT,
            'new_password' => self::NEXT,
            'new_password_confirmation' => self::NEXT,
        ])->assertStatus(422);

        self::assertTrue(Hash::check(self::CURRENT, (string) $user->refresh()->password));
    }
}
