<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\SessionManagement;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Identity\Domain\Authentication\IdentityAuditEvents;
use App\Modules\Identity\Domain\Authentication\SessionRefusal;
use App\Modules\Identity\Domain\Authentication\SessionRevocationRefused;
use App\Modules\Identity\Domain\Contracts\SessionStoreInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * `DELETE /api/v1/auth/sessions/{id}` — `SEC-05`'s force logout, one device.
 *
 * ── The caller's own session is refused, not quietly signed out ────────────
 *
 * §9 Flow 0 ends a session by signing out, and `POST /auth/logout` is that:
 * it writes a `LOGOUT` row and the SPA drops the token it is holding. Letting
 * this endpoint kill the calling credential would return `200` to a client
 * that then keeps using it, and would file the event under the wrong name.
 * So the refusal is `SessionIsCurrent`, and it points at the door rather than
 * pretending there is none.
 *
 * ── Transaction ────────────────────────────────────────────────────────────
 *
 * The revocation and its `AUD-01` row are one unit. A device that is signed
 * out with no record of who did it is exactly the gap this event exists to
 * close — and the reverse, a permanent `AUD-03` row for a revocation that
 * rolled back, is a log that lies.
 */
final readonly class RevokeDeviceSession
{
    public function __construct(
        private ConnectionInterface $connection,
        private SessionStoreInterface $sessions,
        private AuditRecorderInterface $audit,
    ) {}

    /** @throws SessionRevocationRefused */
    public function handle(string $accountId, string $sessionId, ?string $currentSessionId): void
    {
        if ($currentSessionId !== null && $sessionId === $currentSessionId) {
            throw SessionRevocationRefused::because(SessionRefusal::SessionIsCurrent);
        }

        $this->connection->transaction(function () use ($accountId, $sessionId): void {
            if (! $this->sessions->revokeDevice($sessionId, $accountId)) {
                // Somebody else's device, an id that never existed, one already
                // revoked, or a Login As row §3.1 hides — all one answer, so
                // this endpoint cannot be used to test whether an id is live.
                throw SessionRevocationRefused::because(SessionRefusal::SessionNotFound);
            }

            // The id of the revoked row, never its `session_id`: `D-74` makes
            // that column the digest a live credential is matched against, and
            // `AUD-03` makes this row permanent.
            $this->audit->record(
                AuditEvent::of(IdentityAuditEvents::SESSION_REVOKED),
                'user',
                $accountId,
                null,
                ['scope' => 'one_device', 'session' => $sessionId, 'sessions_revoked' => 1],
            );
        });
    }
}
