<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Authentication;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Identity\Domain\Authentication\Account;
use App\Modules\Identity\Domain\Authentication\IdentityAuditEvents;
use App\Modules\Identity\Domain\Authentication\PasswordChangeRefused;
use App\Modules\Identity\Domain\Authentication\PasswordRefusal;
use App\Modules\Identity\Domain\Contracts\AccountDirectoryInterface;
use App\Modules\Identity\Domain\Contracts\SessionStoreInterface;
use App\Modules\Identity\Domain\PasswordPolicy;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\ConnectionInterface;
use SensitiveParameter;

/**
 * §9 Flow 0's password change, minus the step it cannot yet take.
 *
 * ── ⚠️ `SEC-04` is NOT satisfied by this class ─────────────────────────────
 *
 * `SEC-04` reads "Mandatory email verification for password changes", and §9
 * Flow 0 spells the flow out: "Password change → **verification code by email**
 * → new password → log in again." This implements the first, third and fourth
 * steps and **not the second**. What ships here is a current-password
 * challenge, which is a different control: it proves the caller knows the old
 * password, not that they hold the mailbox. Recorded as an open gap rather than
 * quietly redefined — a requirement that says "mandatory" is not closed by
 * something adjacent.
 *
 * ── The order of the checks ────────────────────────────────────────────────
 *
 * 1. **Current password**, so a hijacked session cannot change the credential
 *    it stole. This is the whole reason the field exists.
 * 2. **`D-28`'s policy**, before anything is written.
 * 3. **Not the same as the current one**, because a change that changes nothing
 *    is a user who believes they have rotated a leaked password.
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
}
