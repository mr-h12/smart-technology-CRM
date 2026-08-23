<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Authentication;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Identity\Domain\Authentication\IdentityAuditEvents;
use App\Modules\Identity\Domain\Contracts\SessionStoreInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * Ends one device's session (`SEC-05`).
 *
 * Ends *one*, not all: `SEC-05` asks for an active device list and force
 * logout, which only means something if signing out of a laptop leaves the
 * phone signed in. Revoking every session is a different action and belongs to
 * the force-logout screen, not here.
 */
final readonly class SignOut
{
    public function __construct(
        private ConnectionInterface $connection,
        private SessionStoreInterface $sessions,
        private AuditRecorderInterface $audit,
    ) {}

    /** Idempotent: a session already gone is a logout that already happened. */
    public function handle(string $accountId, ?string $sessionId): void
    {
        $this->connection->transaction(function () use ($accountId, $sessionId): void {
            if ($sessionId !== null) {
                $this->sessions->revoke($sessionId, $accountId);
            }

            $this->audit->record(
                AuditEvent::of(IdentityAuditEvents::LOGOUT),
                'user',
                $accountId,
                ['session' => $sessionId],
                null,
            );
        });
    }
}
