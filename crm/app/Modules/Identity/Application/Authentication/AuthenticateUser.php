<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Authentication;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Identity\Domain\Authentication\Account;
use App\Modules\Identity\Domain\Authentication\AccountLocked;
use App\Modules\Identity\Domain\Authentication\AuthenticationRefused;
use App\Modules\Identity\Domain\Authentication\IdentityAuditEvents;
use App\Modules\Identity\Domain\Authentication\IssuedSession;
use App\Modules\Identity\Domain\Authentication\LockoutPolicy;
use App\Modules\Identity\Domain\Authentication\RefusalReason;
use App\Modules\Identity\Domain\Authentication\SessionToken;
use App\Modules\Identity\Domain\Contracts\AccountDirectoryInterface;
use App\Modules\Identity\Domain\Contracts\SessionStoreInterface;
use App\Support\Settings\SettingReader;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;

/**
 * §9 Flow 0 — the login, end to end.
 *
 * ── The order of the checks, and why it is not the obvious one ─────────────
 *
 * 1. **Lock first** (`SEC-03`). A locked account is told it is locked whatever
 *    it presents, so five wrong guesses followed by the right one still stops.
 * 2. **Then the password.**
 * 3. **Then `is_active`** (`D-34`).
 *
 * Putting `is_active` *after* the password is deliberate, and is the one place
 * this departs from a literal reading of "immediately reject". Checking it
 * first turns an unauthenticated endpoint into an oracle: anyone could submit
 * an address with any password and learn from the status code whether that
 * person still works here. Coding Standards §9 and `OpenAPI §5.1`'s rule for
 * 404 — "do not reveal which case applies" — point the same way. **The
 * documented behaviour is unchanged** for the case §10.1 actually describes:
 * the deactivated employee, who knows their own password, still gets "Account
 * suspended, please contact administration".
 *
 * ── Transactions ───────────────────────────────────────────────────────────
 *
 * Every path that writes does so inside one transaction (`DB-11`), audit row
 * included (`AUD-01`). `SEC-03`'s notification is dispatched **after** the
 * transaction closes: a mail server that is down must not roll back a lock.
 */
final readonly class AuthenticateUser
{
    /** `AP-08` — a limit is configuration, not a constant. Module 2's Point 2.3 moved it to `system_limits`. */
    public const LOCKOUT_MINUTES_KEY = 'identity.lockout_minutes';

    public function __construct(
        private ConnectionInterface $connection,
        private AccountDirectoryInterface $accounts,
        private SessionStoreInterface $sessions,
        private Hasher $hasher,
        private AuditRecorderInterface $audit,
        private Dispatcher $events,
        // D-75: the lockout duration is a row an administrator may edit, with
        // config/identity.php as its documented default. Read through
        // App\Support's contract rather than Admin's — deptrac.modules.yaml
        // gives every module an empty ruleset, and Identity learning Admin's
        // name is a crossing that needs its own decision.
        private SettingReader $settings,
    ) {}

    /**
     * @throws AuthenticationRefused on every refusal — there is no other answer
     */
    public function handle(
        string $email,
        #[SensitiveParameter] string $password,
        ?string $ip,
        ?string $userAgent,
    ): IssuedSession {
        // DB-08. One instant for the whole attempt, so the lock expiry, the
        // audit row and `last_activity_at` cannot disagree by a round trip.
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        /** @var AccountLocked|null $locked assigned by reference inside the transaction */
        $locked = null;

        /**
         * The transaction **returns** the refusal instead of throwing it, and
         * that is the whole reason this method is shaped the way it is.
         *
         * Throwing from inside `transaction()` rolls the transaction back — and
         * on the failure path the transaction contains `SEC-03`'s incremented
         * counter and `SEC-16`'s audit row. Measured here before it was fixed:
         * five wrong passwords left `failed_login_attempts = 0` and no audit
         * rows at all, so the account never locked and nothing recorded that
         * anybody had tried. The refusal has to survive its own commit.
         *
         * @var IssuedSession|RefusalReason $outcome
         */
        $outcome = $this->connection->transaction(
            // A full closure with `use (&$locked)`, not an arrow function: an
            // arrow function captures by value, so the out-parameter was
            // written to a copy and the SEC-03 notification was silently never
            // dispatched — the lock happened, the mail did not.
            function () use ($email, $password, $ip, $userAgent, $now, &$locked): IssuedSession|RefusalReason {
                return $this->attempt($email, $password, $ip, $userAgent, $now, $locked);
            },
        );

        // After the commit: a mail server that is down must not undo the lock.
        if ($locked instanceof AccountLocked) {
            $this->events->dispatch($locked);
        }

        if ($outcome instanceof RefusalReason) {
            throw AuthenticationRefused::because($outcome);
        }

        return $outcome;
    }

    /**
     * @param  AccountLocked|null  $locked  out-parameter: set when this attempt locked the account
     */
    private function attempt(
        string $email,
        #[SensitiveParameter] string $password,
        ?string $ip,
        ?string $userAgent,
        DateTimeImmutable $now,
        ?AccountLocked &$locked,
    ): IssuedSession|RefusalReason {
        $account = $this->accounts->findByEmailForUpdate($email);

        if (! $account instanceof Account) {
            // No audit row is possible: `audit_log.entity_id` is UUID NOT NULL
            // (D-72's schema) and there is no entity to name. `SEC-16`'s
            // failed-login log is served by the structured line instead
            // (AUD-05) — a known gap, recorded rather than silent.
            Log::channel('audit')->info('audit', [
                'event' => IdentityAuditEvents::LOGIN_FAILED,
                'entity_type' => 'user',
                'entity_id' => null,
                'ip' => $ip,
                'reason' => RefusalReason::InvalidCredentials->value,
            ]);

            return RefusalReason::InvalidCredentials;
        }

        if (LockoutPolicy::isLocked($account->lockedUntil, $now)) {
            return RefusalReason::AccountLocked;
        }

        if (! $this->hasher->check($password, $account->passwordHash)) {
            $locked = $this->recordFailure($account, $now, $ip);

            // The attempt that reaches five is told it locked the account,
            // which is what §9 Flow 0's "after 5 failures → account locked"
            // describes from the user's side.
            return $locked === null ? RefusalReason::InvalidCredentials : RefusalReason::AccountLocked;
        }

        // D-34 · §10.1. The password was right, so this is the employee, and
        // telling them their account is suspended reveals nothing they do not
        // already know about themselves.
        if (! $account->isActive) {
            $this->audit->record(
                AuditEvent::of(IdentityAuditEvents::LOGIN_BLOCKED),
                'user',
                $account->id,
                null,
                ['reason' => RefusalReason::AccountSuspended->value],
            );

            return RefusalReason::AccountSuspended;
        }

        return $this->issue($account, $now, $ip, $userAgent);
    }

    /**
     * `SEC-03`: count the failure, and lock on the fifth.
     *
     * @return AccountLocked|null the event to dispatch, when this failure locked the account
     */
    private function recordFailure(Account $account, DateTimeImmutable $now, ?string $ip): ?AccountLocked
    {
        $before = $account->failedLoginAttempts;
        $after = $before + 1;

        $event = null;

        if (LockoutPolicy::shouldLock($after)) {
            $until = LockoutPolicy::lockedUntil($now, $this->settings->integer(self::LOCKOUT_MINUTES_KEY));

            $event = new AccountLocked(
                userId: $account->id,
                userName: $account->name,
                userEmail: $account->email,
                lockedUntil: $until,
                ip: $ip,
            );
        }

        $this->accounts->recordFailure($account->id, $after, $event?->lockedUntil);

        // AUD-02 wants old and new. Neither contains the presented password.
        $this->audit->record(
            AuditEvent::of(IdentityAuditEvents::LOGIN_FAILED),
            'user',
            $account->id,
            ['failed_login_attempts' => $before],
            ['failed_login_attempts' => $after],
        );

        if ($event instanceof AccountLocked) {
            $this->audit->record(
                AuditEvent::of(IdentityAuditEvents::ACCOUNT_LOCKED),
                'user',
                $account->id,
                ['locked_until' => $account->lockedUntil?->format(DATE_ATOM)],
                ['locked_until' => $event->lockedUntil->format(DATE_ATOM)],
            );
        }

        return $event;
    }

    /** The success path: clear the counters, open a session, record it. */
    private function issue(
        Account $account,
        DateTimeImmutable $now,
        ?string $ip,
        ?string $userAgent,
    ): IssuedSession {
        $this->accounts->clearFailures($account->id);

        $token = SessionToken::issue();

        $sessionId = $this->sessions->open(
            $account->id,
            // The digest, never the token. Coding Standards §9.
            $token->fingerprint(),
            $ip,
            $userAgent,
            $now,
        );

        $this->audit->record(
            AuditEvent::of(IdentityAuditEvents::LOGIN_SUCCEEDED),
            'user',
            $account->id,
            null,
            // The session's identity, so SEC-05's force-logout can be traced
            // back to the login it ended. Not the token, and not its digest.
            ['session' => $sessionId],
        );

        return new IssuedSession($account, $token, $sessionId);
    }
}
