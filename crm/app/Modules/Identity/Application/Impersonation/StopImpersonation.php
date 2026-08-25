<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Impersonation;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Identity\Domain\Authentication\IdentityAuditEvents;
use App\Modules\Identity\Domain\Contracts\SessionStoreInterface;
use App\Modules\Identity\Domain\Impersonation\ImpersonationRefusal;
use App\Modules\Identity\Domain\Impersonation\ImpersonationRefused;
use Illuminate\Database\ConnectionInterface;

/**
 * The other end of `SEC-10`.
 *
 * ── This endpoint carries no permission check, deliberately ────────────────
 *
 * While impersonating, the authenticated user **is the target** — an Indoor
 * Sales employee, who does not hold `admin.login_as`. Guarding the leave
 * endpoint with that permission would make the impersonation impossible to
 * exit through the API, and the only way out would be waiting eight hours for
 * `D-29`. The authorisation here is *being in an impersonation session*, which
 * the guard has already established: it set the impersonator attribute, and
 * nothing else can.
 *
 * ── Revoke, then record ────────────────────────────────────────────────────
 *
 * One session and only this one. The Super Admin's original session was never
 * touched when the impersonation started, so it is still live and the client
 * resumes with the token it already has.
 */
final readonly class StopImpersonation
{
    public function __construct(
        private ConnectionInterface $connection,
        private SessionStoreInterface $sessions,
        private AuditRecorderInterface $audit,
    ) {}

    /** @throws ImpersonationRefused */
    public function handle(?string $impersonatorId, string $targetId, ?string $sessionId): void
    {
        if ($impersonatorId === null || $sessionId === null) {
            // An ordinary session asking to leave an impersonation it is not
            // in. Refused rather than answered 200, because a client that
            // believes it left something it never entered will show the wrong
            // identity in its own chrome.
            throw ImpersonationRefused::because(ImpersonationRefusal::NotImpersonating);
        }

        $this->connection->transaction(function () use ($impersonatorId, $targetId, $sessionId): void {
            // Scoped to the target, which is who the session belongs to — the
            // store refuses to revoke another account's device.
            $this->sessions->revoke($sessionId, $targetId);

            $this->audit->record(
                AuditEvent::of(IdentityAuditEvents::IMPERSONATION_ENDED),
                'user',
                $targetId,
                null,
                ['impersonator_id' => $impersonatorId, 'session_id' => $sessionId],
            );
        });
    }
}
