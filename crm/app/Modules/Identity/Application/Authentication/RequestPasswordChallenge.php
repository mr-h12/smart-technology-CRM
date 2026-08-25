<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Authentication;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Identity\Domain\Authentication\Account;
use App\Modules\Identity\Domain\Authentication\IdentityAuditEvents;
use App\Modules\Identity\Domain\Authentication\PasswordChallenge;
use App\Modules\Identity\Domain\Authentication\PasswordChallengeIssued;
use App\Modules\Identity\Domain\Authentication\VerificationCode;
use App\Modules\Identity\Domain\Contracts\AccountDirectoryInterface;
use App\Modules\Identity\Domain\Contracts\PasswordChallengeStoreInterface;
use DateTimeImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Hashing\Hasher;

/**
 * `SEC-04` step one — §9 Flow 0's "**verification code by email**".
 *
 * ── This closes the gap Point 3.1 recorded ─────────────────────────────────
 *
 * 3.1 shipped the current-password challenge and said in as many words that it
 * did **not** satisfy `SEC-04`, because knowing the old password is not holding
 * the mailbox. This is the second control, and only with both does §9 Flow 0
 * read the way it is written.
 *
 * ── The code is generated once, hashed, and never seen again ───────────────
 *
 * The plaintext exists in exactly two places: the event that carries it to the
 * mailer, and the inbox. The store gets a hash. Nothing here logs it, and the
 * audit row records only that a challenge happened.
 *
 * ── No account, no error ───────────────────────────────────────────────────
 *
 * The caller is authenticated — the guard resolved them a moment ago — so a
 * missing row means the account was archived between two queries. Returning
 * quietly rather than throwing keeps this endpoint from becoming a probe: it
 * answers the same 202 whatever it finds, and the person simply never receives
 * a mail.
 */
final readonly class RequestPasswordChallenge
{
    public function __construct(
        private AccountDirectoryInterface $accounts,
        private PasswordChallengeStoreInterface $challenges,
        private Hasher $hasher,
        private Dispatcher $events,
        private AuditRecorderInterface $audit,
    ) {}

    public function handle(string $accountId, DateTimeImmutable $now, int $ttlMinutes): void
    {
        $account = $this->accounts->findById($accountId);

        if (! $account instanceof Account) {
            return;
        }

        $code = VerificationCode::issue();

        // A password hash, not sha256 — see PasswordChallenge for the million
        // candidates that makes the difference.
        $challenge = PasswordChallenge::issuedAt(
            $this->hasher->make($code->value),
            $now,
            $ttlMinutes,
        );

        // Stored before the event is dispatched. The other order mails a code
        // the store does not yet know, and a failure in between produces the
        // one outcome a person cannot recover from on their own: a code in
        // their inbox that the system refuses.
        $this->challenges->put($account->id, $challenge);

        $this->audit->record(
            AuditEvent::of(IdentityAuditEvents::PASSWORD_CHALLENGE_REQUESTED),
            'user',
            $account->id,
            null,
            // AUD-03: permanent. The expiry is a fact about the flow; the code
            // is a secret and is not here.
            ['expires_at' => $challenge->expiresAt->format(DATE_ATOM)],
        );

        $this->events->dispatch(new PasswordChallengeIssued(
            $account->id,
            $account->name,
            $account->email,
            $code->value,
            $challenge->expiresAt,
        ));
    }
}
