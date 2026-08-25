<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Authentication;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Identity\Domain\Authentication\Account;
use App\Modules\Identity\Domain\Authentication\IdentityAuditEvents;
use App\Modules\Identity\Domain\Authentication\PasswordChallenge;
use App\Modules\Identity\Domain\Authentication\PasswordChangeRefused;
use App\Modules\Identity\Domain\Authentication\PasswordRefusal;
use App\Modules\Identity\Domain\Authentication\VerificationCode;
use App\Modules\Identity\Domain\Contracts\AccountDirectoryInterface;
use App\Modules\Identity\Domain\Contracts\PasswordChallengeStoreInterface;
use App\Modules\Identity\Domain\Contracts\SessionStoreInterface;
use App\Modules\Identity\Domain\PasswordPolicy;
use DateTimeImmutable;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * §9 Flow 0's password change — now the whole of it.
 *
 * "Password change → **verification code by email** → new password → log in
 * again." Point 3.1 shipped steps one, three and four and recorded step two as
 * an open `SEC-04` gap. Point 3.3 closes it: {@see RequestPasswordChallenge}
 * issues the code, and this refuses without it.
 *
 * ── The order of the checks, and why the code is not first ─────────────────
 *
 * 1. **Current password**, so a hijacked session cannot change the credential
 *    it stole, and — the reason it comes before the code — so a caller guessing
 *    passwords cannot burn somebody else's challenge attempts as a side effect.
 *    Put the code first and a wrong password becomes a denial-of-service on the
 *    account's own recovery flow.
 * 2. **`SEC-04`'s code**, which proves the mailbox. This is the check that makes
 *    the two controls independent: one is something known, one is something
 *    received.
 * 3. **`D-28`'s policy**, before anything is written.
 * 4. **Not the same as the current one**, asked of the hash.
 *
 * ── The refusals happen before the transaction opens, deliberately ─────────
 *
 * A `PASSWORD_CHALLENGE_FAILED` row written inside `DB::transaction()` and then
 * thrown out of it is rolled back with everything else — Point 2.2 measured
 * exactly that: five wrong passwords left no audit trail at all. So every
 * refusal path here runs, and records, outside the transaction.
 *
 * ── Every session dies, including this one ─────────────────────────────────
 *
 * §9 Flow 0 ends with "log in again". Revoking only the *other* devices leaves
 * the session an attacker is most likely holding — the live one — so the
 * calling device goes too. The caller's token is dead when this returns 200.
 */
final readonly class ChangePassword
{
    public function __construct(
        private ConnectionInterface $connection,
        private AccountDirectoryInterface $accounts,
        private SessionStoreInterface $sessions,
        private PasswordChallengeStoreInterface $challenges,
        private Hasher $hasher,
        private AuditRecorderInterface $audit,
    ) {}

    /**
     * @return int how many sessions were revoked
     *
     * @throws PasswordChangeRefused
     */
    public function handle(
        string $accountId,
        #[SensitiveParameter] string $currentPassword,
        #[SensitiveParameter] string $newPassword,
        #[SensitiveParameter] string $verificationCode,
        DateTimeImmutable $now,
        int $maxCodeAttempts,
    ): int {
        $account = $this->accounts->findByIdForUpdate($accountId);

        if (! $account instanceof Account) {
            // The guard resolved this user a moment ago, so the row has gone
            // between two queries. Refusing on `current_password` is the honest
            // shape: there is nothing to compare against.
            throw PasswordChangeRefused::because(PasswordRefusal::CurrentPasswordIncorrect);
        }

        if (! $this->hasher->check($currentPassword, $account->passwordHash)) {
            throw PasswordChangeRefused::because(PasswordRefusal::CurrentPasswordIncorrect);
        }

        $this->assertChallengeSatisfied($account->id, $verificationCode, $now, $maxCodeAttempts);

        // D-28 · SEC-02, from the one class that owns the rule. The seeder and
        // this use case ask the same question of the same code — three copies
        // of "at least eight, with a digit" is how one of them ends up at seven.
        if (! PasswordPolicy::isSatisfiedBy($newPassword)) {
            throw PasswordChangeRefused::because(PasswordRefusal::PolicyNotMet);
        }

        // Checked against the *hash*, not by comparing the two submitted
        // strings: a caller could send a different string that hashes to the
        // same stored credential only if it is the same password, and asking
        // the hasher is the comparison that cannot be fooled by whitespace or
        // by a client that normalises one field and not the other.
        if ($this->hasher->check($newPassword, $account->passwordHash)) {
            throw PasswordChangeRefused::because(PasswordRefusal::SameAsCurrent);
        }

        return $this->connection->transaction(function () use ($account, $newPassword): int {
            // SEC-02: Argon2 or bcrypt, whichever `hashing.driver` names. The
            // use case never sees which, and never sees a plaintext column.
            $this->accounts->updatePassword($account->id, $this->hasher->make($newPassword));

            // Single use. Destroyed on the way through, not left to its TTL: a
            // code that still works after the password it authorised has
            // changed is a second change nobody asked for.
            $this->challenges->forget($account->id);

            $revoked = $this->sessions->revokeAllFor($account->id);

            // AUD-01 · AUD-02. Old and new values describe the *event*, never
            // the credential: AUD-03 makes this row permanent, and a permanent
            // record of a hash outlives the account it belongs to.
            $this->audit->record(
                AuditEvent::of(IdentityAuditEvents::PASSWORD_CHANGED),
                'user',
                $account->id,
                null,
                ['sessions_revoked' => $revoked],
            );

            return $revoked;
        });
    }

    /**
     * `SEC-04`, as the four ways it can fail.
     *
     * @throws PasswordChangeRefused
     */
    private function assertChallengeSatisfied(
        string $accountId,
        #[SensitiveParameter] string $presented,
        DateTimeImmutable $now,
        int $maxAttempts,
    ): void {
        $challenge = $this->challenges->find($accountId);

        if (! $challenge instanceof PasswordChallenge) {
            $this->recordFailure($accountId, 'no_challenge_outstanding', false);

            throw PasswordChangeRefused::because(PasswordRefusal::InvalidVerificationCode);
        }

        if ($challenge->hasExpired($now)) {
            // Cleared rather than left for the TTL, so the next attempt reads
            // "no challenge" instead of re-deriving the same expiry.
            $this->challenges->forget($accountId);
            $this->recordFailure($accountId, 'expired', true);

            throw PasswordChangeRefused::because(PasswordRefusal::ExpiredVerificationCode);
        }

        try {
            $code = VerificationCode::fromPresented($presented);
        } catch (InvalidArgumentException) {
            // A malformed code is a wrong code, and it costs an attempt. The
            // Form Request already rejects the shape; this is the second ask
            // that makes the rule true for any future entry point, and letting
            // a malformed value skip the counter would hand an attacker a free
            // way to keep a challenge alive.
            $this->burn($accountId, $challenge, $maxAttempts, 'malformed');

            throw PasswordChangeRefused::because(PasswordRefusal::InvalidVerificationCode);
        }

        if (! $this->hasher->check($code->value, $challenge->codeHash)) {
            $this->burn($accountId, $challenge, $maxAttempts, 'code_incorrect');

            throw PasswordChangeRefused::because(PasswordRefusal::InvalidVerificationCode);
        }
    }

    /** One wrong guess: count it, and destroy the challenge if that was the last one. */
    private function burn(string $accountId, PasswordChallenge $challenge, int $maxAttempts, string $reason): void
    {
        $failed = $challenge->withFailure();
        $exhausted = $failed->isExhausted($maxAttempts);

        if ($exhausted) {
            $this->challenges->forget($accountId);
        } else {
            $this->challenges->put($accountId, $failed);
        }

        $this->recordFailure($accountId, $exhausted ? $reason.'_exhausted' : $reason, $exhausted);
    }

    private function recordFailure(string $accountId, string $reason, bool $destroyed): void
    {
        // SEC-16 keeps a failed login log for the same reason this exists: the
        // 422 the caller sees says only "invalid code", and without this row an
        // attempt to guess a code leaves nothing behind for anyone to notice.
        $this->audit->record(
            AuditEvent::of(IdentityAuditEvents::PASSWORD_CHALLENGE_FAILED),
            'user',
            $accountId,
            null,
            ['reason' => $reason, 'challenge_destroyed' => $destroyed],
        );
    }
}
