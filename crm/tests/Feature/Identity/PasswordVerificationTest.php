<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Authentication\IdentityAuditEvents;
use App\Modules\Identity\Domain\Authentication\PasswordChallenge;
use App\Modules\Identity\Domain\Authentication\PasswordChallengeIssued;
use App\Modules\Identity\Domain\Authentication\VerificationCode;
use App\Modules\Identity\Domain\Contracts\PasswordChallengeStoreInterface;
use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\CachePasswordChallengeStore;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Identity\Infrastructure\Notifications\PasswordChallengeNotification;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Point 3.3 — `SEC-04` step one: the emailed verification code §9 Flow 0
 * requires before a password may change.
 *
 * `SEC-04` · `SEC-11` · `AUD-01` · `AUD-03` · §9 Flow 0 · `OpenAPI §4.3`, §5,
 * §5.1, §10.
 *
 * ── What this file is really guarding ──────────────────────────────────────
 *
 * A six-digit code is 20 bits. It is a real control **only** because four other
 * things hold at the same time: it is stored as a password hash rather than a
 * digest, it dies in fifteen minutes, generation is rate-limited, and a handful
 * of wrong guesses destroys it. Each of those has a test here, because the
 * number on its own does not survive any of them being dropped.
 */
final class PasswordVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const MASTER_DOCUMENTATION = '/opt/crm/docs/CRM_Documentation_EN.md';

    private const CHALLENGE = '/api/v1/auth/change-password/challenge';

    private const PASSWORD = 'Passw0rd123';

    private function user(string $email = 'person@example.test'): User
    {
        $role = Role::query()->firstOrCreate(
            ['slug' => RoleName::IndoorSales->value],
            ['name' => RoleName::IndoorSales->label(), 'is_system' => true],
        );

        $user = new User;
        $user->fill([
            'name' => 'Test Person',
            'email' => $email,
            'password' => self::PASSWORD,
            'role_id' => $role->id,
            'is_active' => true,
            'is_hidden' => false,
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

    /**
     * Requests a code and returns the plaintext, captured from the domain event.
     *
     * Through the real endpoint rather than by writing to the store, so the code
     * under test is one the flow actually issued.
     */
    private function requestCode(string $token): string
    {
        $captured = null;

        Event::listen(function (PasswordChallengeIssued $event) use (&$captured): void {
            $captured = $event->code;
        });

        $this->withToken($token)->postJson(self::CHALLENGE)->assertStatus(202);

        self::assertIsString($captured, 'No PasswordChallengeIssued event carried a code.');

        return $captured;
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

    // ── the documented requirement ──────────────────────────────────────────

    public function test_sec_04_is_the_requirement_this_point_closes(): void
    {
        self::assertFileExists(self::MASTER_DOCUMENTATION);

        $text = file_get_contents(self::MASTER_DOCUMENTATION);

        self::assertIsString($text);

        self::assertSame(
            1,
            preg_match('/^\|\s*SEC-04\s*\|\s*(.+?)\s*\|$/mu', $text, $row),
            'SEC-04 no longer reads as this test expects.',
        );

        self::assertIsString($row[1] ?? null);
        self::assertStringContainsString('email verification', $row[1]);

        // §9 Flow 0's second step, in the document's own words.
        self::assertStringContainsString(
            'Password change → verification code by email → new password → log in again',
            $text,
            '§9 Flow 0 no longer describes the flow this point implements.',
        );
    }

    // ── step one: issuing ───────────────────────────────────────────────────

    public function test_requesting_a_code_answers_202_and_mails_six_digits(): void
    {
        Notification::fake();

        $user = $this->user();
        $token = $this->tokenFor($user);

        $response = $this->withToken($token)->postJson(self::CHALLENGE)->assertStatus(202);

        self::assertTrue($response->json('data.challenge_sent'));
        self::assertSame(15, $response->json('data.expires_in_minutes'));
        self::assertIsString($response->json('meta.request_id'));

        Notification::assertSentTo($user, PasswordChallengeNotification::class,
            function (PasswordChallengeNotification $notification) use ($user): bool {
                $mail = $notification->toMail($user);

                self::assertInstanceOf(MailMessage::class, $mail);

                $body = '';

                foreach ($mail->introLines as $line) {
                    // introLines is array<mixed> to PHPStan: MailMessage
                    // accepts anything Stringable. Narrowing rather than
                    // casting — a cast on an array is the word "Array".
                    if (is_string($line)) {
                        $body .= $line.' ';
                    }
                }

                // The mail must actually carry a code the person can retype.
                self::assertSame(1, preg_match('/\b(\d{6})\b/', $body),
                    'The challenge mail carries no six-digit code.');

                return true;
            });
    }

    public function test_the_endpoint_takes_no_target_and_mails_only_the_caller(): void
    {
        Notification::fake();

        $caller = $this->user();
        $victim = $this->user('victim@example.test');

        // A `user_id` here would be a spam relay with the company's own address
        // on it. The endpoint reads the account from the guard and nothing else.
        $this->withToken($this->tokenFor($caller))
            ->postJson(self::CHALLENGE, ['user_id' => $victim->id, 'email' => $victim->email])
            ->assertStatus(202);

        Notification::assertSentTo($caller, PasswordChallengeNotification::class);
        Notification::assertNotSentTo($victim, PasswordChallengeNotification::class);

        self::assertNull($this->store()->find($victim->id));
    }

    public function test_an_unauthenticated_caller_cannot_request_a_code(): void
    {
        $this->user();

        $this->postJson(self::CHALLENGE)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'authentication_required');
    }

    public function test_the_issued_code_is_six_digits_from_the_csprng(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user);

        $seen = [];

        // Six requests would trip SEC-11's limit, so the codes are drawn
        // directly. What is under test here is the generator, not the endpoint.
        for ($i = 0; $i < 200; $i++) {
            $code = VerificationCode::issue()->value;

            self::assertSame(VerificationCode::LENGTH, strlen($code));
            self::assertSame(1, preg_match('/^[0-9]{6}$/D', $code));

            $seen[$code] = true;
        }

        // Not a randomness test — it is the test that catches a constant, a
        // counter, or a generator seeded once per process.
        self::assertGreaterThan(150, count($seen), 'The code generator repeats far too often.');

        self::assertNotSame('', $this->requestCode($token));
    }

    public function test_the_store_never_holds_the_plaintext_code(): void
    {
        $user = $this->user();
        $code = $this->requestCode($this->tokenFor($user));

        $challenge = $this->store()->find($user->id);

        self::assertInstanceOf(PasswordChallenge::class, $challenge);
        self::assertNotSame($code, $challenge->codeHash);

        // A password hash, not sha256: a six-digit code has a million
        // candidates, and a digest of it is reversible in under a second.
        self::assertNotSame(hash('sha256', $code), $challenge->codeHash);
        self::assertTrue(Hash::check($code, $challenge->codeHash));
        self::assertSame(1, preg_match('/^\$(2y|argon2)/', $challenge->codeHash),
            'The challenge is not stored under a password hash.');
    }

    public function test_the_audit_row_records_the_request_and_not_the_code(): void
    {
        $user = $this->user();
        $code = $this->requestCode($this->tokenFor($user));

        $rows = $this->auditRows(IdentityAuditEvents::PASSWORD_CHALLENGE_REQUESTED);

        self::assertCount(1, $rows, 'AUD-01: requesting a challenge left no audit row.');
        self::assertSame($user->id, $rows[0]['entity_id']);

        $serialised = json_encode($rows[0]);

        self::assertIsString($serialised);
        self::assertStringNotContainsString($code, $serialised, 'AUD-03: a live secret reached a permanent row.');
        self::assertStringNotContainsString('$2y$', $serialised);
        self::assertStringNotContainsString('$argon2', $serialised);
    }

    public function test_a_second_request_replaces_the_first_code(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user);

        $first = $this->requestCode($token);
        $second = $this->requestCode($token);

        $challenge = $this->store()->find($user->id);

        self::assertInstanceOf(PasswordChallenge::class, $challenge);
        self::assertTrue(Hash::check($second, $challenge->codeHash));

        // One live challenge per account: pressing "resend" must not leave the
        // previous code working, or the flow accumulates valid secrets.
        if ($first !== $second) {
            self::assertFalse(Hash::check($first, $challenge->codeHash));
        }
    }

    // ── SEC-11 ──────────────────────────────────────────────────────────────

    public function test_code_generation_is_rate_limited(): void
    {
        Notification::fake();

        $user = $this->user();
        $token = $this->tokenFor($user);

        foreach ([1, 2, 3] as $attempt) {
            $this->withToken($token)->postJson(self::CHALLENGE)
                ->assertStatus(202);
        }

        $refused = $this->withToken($token)->postJson(self::CHALLENGE);

        $refused->assertStatus(429);
        self::assertSame('rate_limit_exceeded', $refused->json('error.code'));

        // OpenAPI §5.1 makes Retry-After mandatory on a 429.
        self::assertTrue($refused->headers->has('Retry-After'));

        Notification::assertSentTimes(PasswordChallengeNotification::class, 3);
    }

    public function test_the_limit_is_per_account_and_not_shared(): void
    {
        $first = $this->user('first@example.test');
        $second = $this->user('second@example.test');

        $firstToken = $this->tokenFor($first);

        foreach ([1, 2, 3] as $attempt) {
            $this->withToken($firstToken)->postJson(self::CHALLENGE)->assertStatus(202);
        }

        $this->withToken($firstToken)->postJson(self::CHALLENGE)->assertStatus(429);

        // One person's bad morning must not stop a colleague behind the same
        // NAT address from changing their password.
        $this->withToken($this->tokenFor($second))->postJson(self::CHALLENGE)->assertStatus(202);
    }

    // ── the domain rules, directly ──────────────────────────────────────────

    public function test_a_challenge_expires_strictly_after_its_instant(): void
    {
        $now = new DateTimeImmutable('2026-08-25 12:00:00');

        $challenge = PasswordChallenge::issuedAt('hash', $now, 15);

        self::assertFalse($challenge->hasExpired($now));
        self::assertFalse($challenge->hasExpired($now->modify('+14 minutes 59 seconds')));
        self::assertFalse($challenge->hasExpired($now->modify('+15 minutes')), 'The boundary second is still valid.');
        self::assertTrue($challenge->hasExpired($now->modify('+15 minutes 1 second')));
    }

    public function test_a_challenge_with_no_lifetime_is_refused_rather_than_issued(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PasswordChallenge::issuedAt('hash', new DateTimeImmutable, 0);
    }

    public function test_attempts_accumulate_and_exhaust(): void
    {
        $challenge = PasswordChallenge::issuedAt('hash', new DateTimeImmutable, 15);

        self::assertSame(0, $challenge->failedAttempts);
        self::assertFalse($challenge->isExhausted(5));

        for ($i = 0; $i < 4; $i++) {
            $challenge = $challenge->withFailure();
        }

        self::assertSame(4, $challenge->failedAttempts);
        self::assertFalse($challenge->isExhausted(5));

        self::assertTrue($challenge->withFailure()->isExhausted(5));
    }

    /** @return array<string, array{string}> */
    public static function malformedCodes(): array
    {
        return [
            'too short' => ['12345'],
            'too long' => ['1234567'],
            'letters' => ['abcdef'],
            'mixed' => ['12a456'],
            'empty' => [''],
            'spaced' => ['123 45'],
            'trailing newline' => ["123456\n"],
        ];
    }

    #[DataProvider('malformedCodes')]
    public function test_a_malformed_code_is_refused_by_the_value_object(string $presented): void
    {
        $this->expectException(InvalidArgumentException::class);

        VerificationCode::fromPresented($presented);
    }

    // ── the store, against the driver production actually runs ──────────────

    public function test_the_challenge_survives_a_round_trip_through_redis(): void
    {
        // The suite runs on the `array` cache store (phpunit.xml), so every
        // other test in this file proves the store against a driver that never
        // serialises anything. Production is Redis (`CACHE_STORE=redis`,
        // §14.2), which does — and a store that only works on `array` is the
        // kind of gap this project has been bitten by before. REDIS_CACHE_DB is
        // forced to 15 in phpunit.xml, so this touches no development data.
        $redis = new CachePasswordChallengeStore(Cache::store('redis'));

        $accountId = 'round-trip-'.bin2hex(random_bytes(8));
        $code = VerificationCode::issue();
        $expiresAt = (new DateTimeImmutable)->modify('+15 minutes');

        try {
            $redis->put($accountId, new PasswordChallenge(Hash::make($code->value), $expiresAt, 2));

            $read = $redis->find($accountId);

            self::assertInstanceOf(PasswordChallenge::class, $read);
            self::assertTrue(Hash::check($code->value, $read->codeHash));
            self::assertSame($expiresAt->getTimestamp(), $read->expiresAt->getTimestamp());
            self::assertSame(2, $read->failedAttempts);

            $redis->forget($accountId);

            self::assertNull($redis->find($accountId));
        } finally {
            $redis->forget($accountId);
        }
    }

    public function test_a_partial_cache_entry_is_treated_as_no_challenge(): void
    {
        Cache::put('identity.password_challenge.someone', ['hash' => 'x'], 60);

        // Reconstructing a challenge from half a record would invent either an
        // expiry or an attempt count, and both inventions favour the attacker.
        self::assertNull($this->store()->find('someone'));
    }

    // ── i18n ────────────────────────────────────────────────────────────────

    public function test_the_challenge_mail_exists_in_both_languages(): void
    {
        foreach (['en', 'ar'] as $locale) {
            foreach (['subject', 'greeting', 'intro', 'expires', 'ignore'] as $key) {
                $line = __('identity.challenge_mail.'.$key, [], $locale);

                self::assertIsString($line);
                self::assertNotSame('identity.challenge_mail.'.$key, $line,
                    "The {$locale} translation for {$key} is missing.");
            }
        }
    }
}
