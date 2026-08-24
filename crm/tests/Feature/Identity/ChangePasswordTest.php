<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Authentication\IdentityAuditEvents;
use App\Modules\Identity\Domain\Authentication\PasswordRefusal;
use App\Modules\Identity\Domain\PasswordPolicy;
use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Identity\Infrastructure\Eloquent\UserSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Point 3.1 — §9 Flow 0's password change.
 *
 * `D-28` · `SEC-02` · `SEC-12` · `AUD-01` · `AUD-02` · `OpenAPI §4.1`, §5, §5.1.
 *
 * ⚠️ **`SEC-04` is deliberately not under test here, because it is not built.**
 * "Mandatory email verification for password changes" is a step this endpoint
 * does not take; `test_the_documented_flow_still_owes_an_emailed_code` pins the
 * gap so it cannot be mistaken for done.
 */
final class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    private const CURRENT = 'Passw0rd123';

    private const NEXT = 'Newpassw0rd456';

    private const ENDPOINT = '/api/v1/auth/change-password';

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

    // ── the happy path ──────────────────────────────────────────────────────

    public function test_an_authenticated_user_can_change_their_password(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user);

        $this->withToken($token)->postJson(self::ENDPOINT, [
            'current_password' => self::CURRENT,
            'new_password' => self::NEXT,
            'new_password_confirmation' => self::NEXT,
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.password_changed', true)
            ->assertJsonPath('data.reauthentication_required', true)
            ->assertJsonStructure(['data' => ['sessions_revoked'], 'meta' => ['request_id']]);
    }

    public function test_the_stored_hash_changes_and_is_not_the_plaintext(): void
    {
        $user = $this->user();
        $before = (string) $user->password;

        $this->withToken($this->tokenFor($user))->postJson(self::ENDPOINT, [
            'current_password' => self::CURRENT,
            'new_password' => self::NEXT,
            'new_password_confirmation' => self::NEXT,
        ])->assertStatus(200);

        $user->refresh();

        self::assertNotSame($before, (string) $user->password);
        self::assertNotSame(self::NEXT, (string) $user->password, 'SEC-02: never a plaintext column.');
        self::assertTrue(Hash::check(self::NEXT, (string) $user->password));
        self::assertFalse(Hash::check(self::CURRENT, (string) $user->password));
    }

    public function test_only_the_new_password_works_afterwards(): void
    {
        $user = $this->user();

        $this->withToken($this->tokenFor($user))->postJson(self::ENDPOINT, [
            'current_password' => self::CURRENT,
            'new_password' => self::NEXT,
            'new_password_confirmation' => self::NEXT,
        ])->assertStatus(200);

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

        $response = $this->withToken($laptop)->postJson(self::ENDPOINT, [
            'current_password' => self::CURRENT,
            'new_password' => self::NEXT,
            'new_password_confirmation' => self::NEXT,
        ])->assertStatus(200);

        self::assertSame(2, $response->json('data.sessions_revoked'));

        // §9 Flow 0 ends with "log in again". A change that leaves the live
        // session alive does not evict whoever the user was trying to evict.
        $this->withToken($laptop)->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->withToken($phone)->getJson('/api/v1/auth/me')->assertStatus(401);

        // DB-01: revoked is a soft delete, so the rows survive for the audit.
        self::assertSame(0, UserSession::query()->where('user_id', $user->id)->count());
        self::assertSame(2, UserSession::withTrashed()->where('user_id', $user->id)->count());
    }

    // ── refusals ────────────────────────────────────────────────────────────

    public function test_a_wrong_current_password_is_refused_and_changes_nothing(): void
    {
        $user = $this->user();
        $before = (string) $user->password;

        $this->withToken($this->tokenFor($user))->postJson(self::ENDPOINT, [
            'current_password' => 'Wr0ngPassword',
            'new_password' => self::NEXT,
            'new_password_confirmation' => self::NEXT,
        ])
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

        $this->withToken($token)->postJson(self::ENDPOINT, [
            'current_password' => 'Wr0ngPassword',
            'new_password' => self::NEXT,
            'new_password_confirmation' => self::NEXT,
        ])->assertStatus(422);

        // A refusal that still logged everybody out would be a denial-of-service
        // any holder of one session could aim at the account's other devices.
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(200);
    }

    /** `D-28`: "8 characters minimum, letters and numbers". */
    #[DataProvider('nonCompliantPasswords')]
    public function test_a_password_that_fails_d_28_is_refused(string $candidate, string $why): void
    {
        $user = $this->user();
        $before = (string) $user->password;

        $response = $this->withToken($this->tokenFor($user))->postJson(self::ENDPOINT, [
            'current_password' => self::CURRENT,
            'new_password' => $candidate,
            'new_password_confirmation' => $candidate,
        ]);

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

        $this->withToken($this->tokenFor($user))->postJson(self::ENDPOINT, [
            'current_password' => self::CURRENT,
            'new_password' => self::CURRENT,
            'new_password_confirmation' => self::CURRENT,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.code', PasswordRefusal::SameAsCurrent->value)
            ->assertJsonPath('error.details.0.field', 'new_password');
    }

    public function test_a_mismatched_confirmation_is_refused(): void
    {
        $user = $this->user();

        $this->withToken($this->tokenFor($user))->postJson(self::ENDPOINT, [
            'current_password' => self::CURRENT,
            'new_password' => self::NEXT,
            'new_password_confirmation' => 'Something3lse',
        ])
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

        // The endpoint takes no target, and this is the test that keeps it that
        // way: a `user_id` parameter added later would make this pass silently.
        $this->withToken($this->tokenFor($attacker, 'Attack3rPass'))->postJson(self::ENDPOINT, [
            'current_password' => 'Attack3rPass',
            'new_password' => self::NEXT,
            'new_password_confirmation' => self::NEXT,
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

        $this->withToken($this->tokenFor($user))->postJson(self::ENDPOINT, [
            'current_password' => self::CURRENT,
            'new_password' => self::NEXT,
            'new_password_confirmation' => self::NEXT,
        ])->assertStatus(200);

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

        $this->withToken($this->tokenFor($user))->postJson(self::ENDPOINT, [
            'current_password' => 'Wr0ngPassword',
            'new_password' => self::NEXT,
            'new_password_confirmation' => self::NEXT,
        ])->assertStatus(422);

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

    // ── the gap, pinned ─────────────────────────────────────────────────────

    public function test_the_documented_flow_still_owes_an_emailed_code(): void
    {
        $documentation = file_get_contents('/opt/crm/docs/CRM_Documentation_EN.md');

        self::assertIsString($documentation);

        // SEC-04 and §9 Flow 0 both require a verification code by email before
        // a new password is accepted. This endpoint does not send one and does
        // not check one. The assertion is on the *documentation*, so the day
        // somebody implements it and deletes this test, they have to have read
        // the requirement it names.
        self::assertMatchesRegularExpression(
            '/^\|\s*SEC-04\s*\|\s*Mandatory email verification for password changes/mu',
            $documentation,
            'SEC-04 has been reworded; re-read it before assuming this gap is still the gap.',
        );

        self::assertFalse(
            class_exists('App\\Modules\\Identity\\Domain\\Authentication\\EmailVerificationCode'),
            'An emailed verification code now exists — SEC-04 may be closed, and this pin removed.',
        );
    }
}
