<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure;

use App\Modules\Identity\Domain\Authentication\IdleTimeout;
use App\Modules\Identity\Domain\Authentication\LockoutPolicy;
use App\Modules\Identity\Domain\Authentication\SessionAttribute;
use App\Modules\Identity\Domain\Authentication\SessionToken;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Identity\Infrastructure\Eloquent\UserSession;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Turns `Authorization: Bearer …` into the person making the request, or into
 * nothing. This is the `api` guard's whole implementation
 * (`Auth::viaRequest('crm-bearer-session', …)`).
 *
 * ── Why Infrastructure and not Application ─────────────────────────────────
 *
 * It is an adapter between a framework contract that speaks `Authenticatable`
 * and a set of domain rules that do not. Application may not reach Eloquent
 * (`deptrac.layers.yaml`), and a guard callback that cannot return a model is
 * not a guard callback. The *decisions* it applies are all Domain —
 * {@see IdleTimeout}, {@see LockoutPolicy}, {@see SessionToken} — and none of
 * them is restated here.
 *
 * ── One answer for four failures ───────────────────────────────────────────
 *
 * `OpenAPI §3.1`: "Deactivated, locked, expired, or force-logged-out accounts
 * cannot call protected endpoints." All four return null, which becomes `401`.
 * A protected endpoint must not say *which*, because the explanation is only
 * useful to somebody holding a credential they should not have.
 *
 * ── The write on the read path ─────────────────────────────────────────────
 *
 * Resolving also touches `last_activity_at`, because `D-29` measures **idle**
 * time and idle is only observable by recording activity. One `UPDATE` on a
 * primary key, on a row already loaded, at most once per request —
 * `RequestGuard` memoises the resolved user.
 */
final class BearerSessionResolver
{
    public function forRequest(Request $request): ?User
    {
        $presented = $request->bearerToken();

        if ($presented === null || $presented === '') {
            return null;
        }

        try {
            $token = SessionToken::fromPresented($presented);
        } catch (InvalidArgumentException) {
            // Wrong shape is a wrong credential. Nothing is logged and nothing
            // is looked up: the value is attacker-controlled, and the only safe
            // thing to do with it is drop it.
            return null;
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        // Soft-deleted rows are excluded by the model's SoftDeletes scope, so
        // SEC-05's force-logout and `SignOut` both take effect by being a
        // `deleted_at`, with no second mechanism to keep in step.
        $session = UserSession::query()->where('session_id', $token->fingerprint())->first();

        if (! $session instanceof UserSession) {
            return null;
        }

        $lastActivity = $session->last_activity_at->toDateTimeImmutable()
            ->setTimezone(new DateTimeZone('UTC'));

        if (IdleTimeout::hasExpired($lastActivity, $now)) {
            // Retired rather than left to be re-checked on every later request:
            // D-29 says the session expired, and a row that answers "expired"
            // forever is a row SEC-05's device list would keep showing.
            $session->delete();

            return null;
        }

        $user = $session->user()->first();

        if (! $user instanceof User || $user->is_active !== true) {
            return null;
        }

        $lockedUntil = $user->locked_until;

        if ($lockedUntil !== null
            && LockoutPolicy::isLocked($lockedUntil->toDateTimeImmutable(), $now)) {
            return null;
        }

        $session->last_activity_at = Carbon::instance($now);
        $session->save();

        // The guard contract can only hand back a user, and logout needs to
        // know which of this person's devices is the one asking.
        $request->attributes->set(SessionAttribute::NAME, (string) $session->id);

        // SEC-10. Set only when this really is a Login As, so the presence of
        // the attribute is the whole question and there is no second state —
        // "present but null" — for a later reader to get wrong. The audit
        // context reads it to name both identities on every row the request
        // writes.
        if ($session->impersonator_id !== null) {
            $request->attributes->set(SessionAttribute::IMPERSONATOR, $session->impersonator_id);
        }

        return $user;
    }
}
