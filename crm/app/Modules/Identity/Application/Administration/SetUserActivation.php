<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Administration;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Identity\Domain\Administration\AdministeredUser;
use App\Modules\Identity\Domain\Administration\AdministrationRefusal;
use App\Modules\Identity\Domain\Administration\UserAdministrationRefused;
use App\Modules\Identity\Domain\Authentication\IdentityAuditEvents;
use App\Modules\Identity\Domain\Contracts\SessionStoreInterface;
use App\Modules\Identity\Domain\Contracts\UserDirectoryInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * `D-34` and §10.1 — "Accounts are **deactivated, never deleted**".
 *
 * ── Deactivation revokes the sessions, and that is not decoration ──────────
 *
 * §10.1 says login is "Blocked". Flipping `is_active` alone blocks the *next*
 * login and leaves every bearer token already issued working until `D-29`'s
 * eight idle hours expire it — so a dismissed employee keeps their access for
 * the rest of the working day, which is the one moment the switch exists for.
 * The revocation is `SEC-05`'s force-logout applied by the administrator
 * instead of by the user.
 *
 * Reactivation revokes nothing: there is nothing to revoke, and the person
 * signs in again.
 *
 * ── Idempotent, and silent when nothing changed ────────────────────────────
 *
 * Deactivating an already-inactive account succeeds and writes **no** audit
 * row. `AUD-03` makes rows permanent, and a log that records a click rather
 * than a change is a log that has to be filtered before it can be read.
 *
 * ── ⚠️ Self-deactivation is not blocked ────────────────────────────────────
 *
 * A Manager may deactivate their own account and will be signed out by this
 * call. Nothing in §3.11, §3.12 or §10.1 forbids it, and inventing the rule
 * would be inventing an authorisation constraint the document does not state.
 * It is recoverable — the Super Admin reactivates — and it is flagged as an
 * owner question rather than decided here.
 */
final readonly class SetUserActivation
{
    public function __construct(
        private ConnectionInterface $connection,
        private UserDirectoryInterface $users,
        private SessionStoreInterface $sessions,
        private AuditRecorderInterface $audit,
    ) {}

    /**
     * @return array{user: AdministeredUser, sessions_revoked: int, changed: bool}
     *
     * @throws UserAdministrationRefused
     */
    public function handle(string $userId, bool $isActive): array
    {
        $user = $this->users->find($userId);

        if (! $user instanceof AdministeredUser) {
            throw UserAdministrationRefused::because(AdministrationRefusal::UserNotFound);
        }

        if ($user->isActive === $isActive) {
            return ['user' => $user, 'sessions_revoked' => 0, 'changed' => false];
        }

        return $this->connection->transaction(function () use ($user, $isActive): array {
            $this->users->setActive($user->id, $isActive);

            $revoked = $isActive ? 0 : $this->sessions->revokeAllFor($user->id);

            // §3.12 rule 4 — "account deactivation" is one of the nine
            // mandatory entries, so this write is not optional and shares the
            // transaction with the change it describes (DB-11).
            $this->audit->record(
                AuditEvent::of($isActive
                    ? IdentityAuditEvents::USER_ACTIVATED
                    : IdentityAuditEvents::USER_DEACTIVATED),
                'user',
                $user->id,
                ['is_active' => $user->isActive],
                ['is_active' => $isActive, 'sessions_revoked' => $revoked],
            );

            $refreshed = $this->users->find($user->id);

            return [
                // find() cannot answer null here — the row was located above and
                // this runs inside the same transaction — but Domain has no way
                // to say so, and asserting it with a fallback is cheaper than a
                // nullable return every caller would have to unwrap.
                'user' => $refreshed instanceof AdministeredUser ? $refreshed : $user,
                'sessions_revoked' => $revoked,
                'changed' => true,
            ];
        });
    }
}
