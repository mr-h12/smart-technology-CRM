<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\SessionManagement;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Identity\Domain\Authentication\IdentityAuditEvents;
use App\Modules\Identity\Domain\Contracts\SessionStoreInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * `DELETE /api/v1/auth/sessions` — `SEC-05`'s force logout, every other device.
 *
 * ── Every *other* device, and the calling one stays ────────────────────────
 *
 * The opposite of {@see \App\Modules\Identity\Application\Authentication\ChangePassword},
 * which takes down the caller too because §9 Flow 0 ends "log in again". This
 * is the different action: somebody who lost a phone wants it evicted without
 * being thrown out of the screen they are doing it from. Signing this device
 * out as well is `POST /auth/logout`, one control away.
 *
 * ── Idempotent, and a no-op is still recorded ──────────────────────────────
 *
 * Running it twice revokes nothing the second time and answers `200` with a
 * count of zero. The `AUD-01` row is written either way: "somebody pressed
 * sign out everywhere and there was nothing to sign out" is a fact about the
 * account, and an audit that only records successful outcomes cannot answer
 * when the button was pressed.
 */
final readonly class RevokeOtherDeviceSessions
{
    public function __construct(
        private ConnectionInterface $connection,
        private SessionStoreInterface $sessions,
        private AuditRecorderInterface $audit,
    ) {}

    /** @return int how many devices were revoked */
    public function handle(string $accountId, ?string $currentSessionId): int
    {
        return $this->connection->transaction(function () use ($accountId, $currentSessionId): int {
            // A caller with no resolvable session id cannot be excluded from
            // its own sweep, so nothing is swept. The alternative — treating
            // "unknown" as "exclude nothing" — signs the caller out through an
            // endpoint documented not to.
            $revoked = $currentSessionId === null
                ? 0
                : $this->sessions->revokeOtherDevices($accountId, $currentSessionId);

            $this->audit->record(
                AuditEvent::of(IdentityAuditEvents::SESSION_REVOKED),
                'user',
                $accountId,
                null,
                ['scope' => 'other_devices', 'sessions_revoked' => $revoked],
            );

            return $revoked;
        });
    }
}
