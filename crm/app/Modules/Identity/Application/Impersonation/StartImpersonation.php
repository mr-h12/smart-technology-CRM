<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Impersonation;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Identity\Domain\Administration\AdministeredUser;
use App\Modules\Identity\Domain\Authentication\IdentityAuditEvents;
use App\Modules\Identity\Domain\Authentication\SessionToken;
use App\Modules\Identity\Domain\Contracts\PermissionRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\SessionStoreInterface;
use App\Modules\Identity\Domain\Contracts\UserDirectoryInterface;
use App\Modules\Identity\Domain\Impersonation\Impersonation;
use App\Modules\Identity\Domain\Impersonation\ImpersonationRefusal;
use App\Modules\Identity\Domain\Impersonation\ImpersonationRefused;
use App\Modules\Identity\Domain\Rbac\Actor;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * `SEC-10` — "Login As restricted to Super Admin, with mandatory logging".
 *
 * ── The Super Admin check is here, not only on the route ───────────────────
 *
 * The route carries `permission:admin.login_as`, which §3.11 grants to exactly
 * one role. That is the **matrix** being enforced, and §3.12 rule 5 makes the
 * matrix configuration — an administrator can add a grant row without a
 * deployment. `SEC-10` is not a matrix cell; it is a sentence in §14.6 that
 * says this capability belongs to one role. So the sentence is enforced here,
 * against §3.1's `hasUnconditionalAccess()`, where no `INSERT` can reach it.
 *
 * ── What the new session is, and is not ────────────────────────────────────
 *
 * It **belongs to the target**: their id, their role, their permissions. That
 * is the point of Login As — a Super Admin reproducing what an employee sees.
 * It additionally records who is driving, and from that moment every audit row
 * the request writes names both (see the `impersonated_user_id` column added
 * with this point).
 *
 * The Super Admin's own session is left alone. Leaving the impersonation
 * revokes the new token and the original one is still there, which is what
 * makes "resume Super Admin context" a client-side switch rather than a second
 * login.
 *
 * ── Four refusals, and why each is not a technicality ──────────────────────
 *
 * A **hidden** target is a 404, so Login As cannot be used to enumerate the
 * accounts §3.12 rule 6 conceals. A **suspended** target is refused because
 * §10.1 blocks that account from signing in, and becoming them would be a way
 * around the one switch `D-34` provides. **Self** is the session the caller
 * already holds. And **nesting** is refused because `impersonated_user_id`
 * holds one value: a chain leaves "who was really acting" without a single
 * answer, which is the one property the whole feature exists to preserve.
 */
final readonly class StartImpersonation
{
    public function __construct(
        private ConnectionInterface $connection,
        private UserDirectoryInterface $users,
        private PermissionRepositoryInterface $permissions,
        private SessionStoreInterface $sessions,
        private AuditRecorderInterface $audit,
    ) {}

    /** @throws ImpersonationRefused */
    public function handle(
        string $callerId,
        string $targetId,
        ?string $ip,
        ?string $userAgent,
        DateTimeImmutable $now,
    ): Impersonation {
        $caller = $this->permissions->actorFor($callerId);

        if (! $caller instanceof Actor || ! $caller->hasUnconditionalAccess()) {
            throw ImpersonationRefused::because(ImpersonationRefusal::CallerIsNotSuperAdmin);
        }

        if ($callerId === $targetId) {
            throw ImpersonationRefused::because(ImpersonationRefusal::TargetIsSelf);
        }

        // find() applies §3.12 rule 6, so a hidden account is indistinguishable
        // from one that does not exist — which is the behaviour rule 6 asks for
        // and the reason this reuses the administration directory rather than
        // reading `users` again with a filter somebody could forget.
        $target = $this->users->find($targetId);

        if (! $target instanceof AdministeredUser) {
            throw ImpersonationRefused::because(ImpersonationRefusal::TargetNotFound);
        }

        if (! $target->isActive) {
            throw ImpersonationRefused::because(ImpersonationRefusal::TargetSuspended);
        }

        if ($this->sessions->impersonationsBy($callerId) > 0) {
            throw ImpersonationRefused::because(ImpersonationRefusal::AlreadyImpersonating);
        }

        $token = SessionToken::issue();

        return $this->connection->transaction(function () use (
            $callerId, $target, $token, $ip, $userAgent, $now,
        ): Impersonation {
            $sessionId = $this->sessions->openAs(
                $target->id,
                $callerId,
                // The digest, never the token (Coding Standards §9).
                $token->fingerprint(),
                $ip,
                $userAgent,
                $now,
            );

            // §3.12 rule 4's mandatory entry, and SEC-10's "mandatory logging".
            // Inside the transaction with the session it describes (DB-11): a
            // Login As that happened without a row is the one outcome this
            // requirement exists to make impossible.
            $this->audit->record(
                AuditEvent::of(IdentityAuditEvents::IMPERSONATION_STARTED),
                'user',
                $target->id,
                null,
                [
                    'impersonator_id' => $callerId,
                    'target_role' => $target->roleSlug,
                    'session_id' => $sessionId,
                ],
            );

            return new Impersonation(
                $callerId,
                $target->id,
                $target->name,
                $target->roleSlug,
                $token,
                $sessionId,
            );
        });
    }
}
