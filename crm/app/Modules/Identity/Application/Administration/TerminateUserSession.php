<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Administration;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Identity\Domain\Administration\AdministeredUser;
use App\Modules\Identity\Domain\Administration\AdministrationRefusal;
use App\Modules\Identity\Domain\Administration\UserAdministrationRefused;
use App\Modules\Identity\Domain\Authentication\IdentityAuditEvents;
use App\Modules\Identity\Domain\Authentication\SessionRefusal;
use App\Modules\Identity\Domain\Authentication\SessionRevocationRefused;
use App\Modules\Identity\Domain\Contracts\SessionStoreInterface;
use App\Modules\Identity\Domain\Contracts\UserDirectoryInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * §13 screen 2's force logout — `DELETE /users/{id}/sessions/{session}`.
 *
 * ── One device, and never the caller's own ─────────────────────────────────
 *
 * An administrator inspecting their **own** account sees their current session
 * in the list, and ending it here would return `200` to a client that keeps
 * using a dead token. Same refusal as `SEC-05`'s self-service endpoint —
 * `session_is_current`, pointing at `POST /auth/logout`, which writes the
 * `LOGOUT` event and lets the SPA drop what it is holding.
 *
 * ── The two 404s are the same 404 ──────────────────────────────────────────
 *
 * A hidden or archived target (§3.12 rule 6) and a session id that is not that
 * account's both answer `404` with no hint of which. `OpenAPI §5.1`: "does not
 * exist **or** is not visible to the caller. Do not reveal which case
 * applies." Without that, this endpoint becomes a way to test whether a guessed
 * user id is the hidden Super Admin's.
 *
 * ── The audit row is the point of the feature ──────────────────────────────
 *
 * Ending somebody else's session leaves no trace anywhere else: the row is soft
 * deleted (`DB-01`), the owner sees a device disappear, and nothing says who
 * did it. So the revocation and `AUD-01`'s row are one transaction, and the
 * event is its own name rather than the self-service one — see
 * {@see IdentityAuditEvents::ADMIN_SESSION_TERMINATED}.
 */
final readonly class TerminateUserSession
{
    public function __construct(
        private ConnectionInterface $connection,
        private UserDirectoryInterface $users,
        private SessionStoreInterface $sessions,
        private AuditRecorderInterface $audit,
    ) {}

    /**
     * @throws UserAdministrationRefused when the target is unknown, archived, or hidden
     * @throws SessionRevocationRefused when the session is the caller's own, or not the target's
     */
    public function handle(string $userId, string $sessionId, ?string $callerSessionId): void
    {
        if (! $this->users->find($userId) instanceof AdministeredUser) {
            throw UserAdministrationRefused::because(AdministrationRefusal::UserNotFound);
        }

        if ($callerSessionId !== null && $sessionId === $callerSessionId) {
            throw SessionRevocationRefused::because(SessionRefusal::SessionIsCurrent);
        }

        $this->connection->transaction(function () use ($userId, $sessionId): void {
            if (! $this->sessions->revokeDevice($sessionId, $userId)) {
                // Not that account's, already revoked, or a Login As row §3.1
                // hides — one answer, so the endpoint cannot be used to probe.
                throw SessionRevocationRefused::because(SessionRefusal::SessionNotFound);
            }

            $this->audit->record(
                AuditEvent::of(IdentityAuditEvents::ADMIN_SESSION_TERMINATED),
                'user',
                $userId,
                null,
                ['scope' => 'one_device', 'session' => $sessionId, 'sessions_revoked' => 1],
            );
        });
    }
}
