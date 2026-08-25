<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation;

use App\Modules\Identity\Application\Impersonation\StopImpersonation;
use App\Modules\Identity\Domain\Authentication\SessionAttribute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/auth/impersonate/leave`.
 *
 * ── No permission middleware, and that is the correct reading ──────────────
 *
 * While impersonating, `$request->user()` is the **target** — an Indoor Sales
 * employee who holds no `admin.*` grant at all. A `permission:admin.login_as`
 * on this route would make the impersonation impossible to leave through the
 * API, and the only exit would be waiting out `D-29`'s eight idle hours. The
 * authorisation is being in an impersonation session, which only the guard can
 * establish: it sets {@see SessionAttribute::IMPERSONATOR}, and a client cannot.
 *
 * ── The route order matters ────────────────────────────────────────────────
 *
 * This is registered **before** `impersonate/{user}`, or `leave` is matched as
 * a user id — measured with the two swapped, the answer is **403** rather than
 * the obvious 404, because the wildcard route's permission middleware refuses
 * the impersonated session first. `routes/api.php` says so and a test pins it.
 */
final class LeaveImpersonationController
{
    public function __invoke(Request $request, StopImpersonation $stop): JsonResponse
    {
        $user = $request->user();

        abort_if($user === null, 401);

        $targetId = $user->getAuthIdentifier();

        abort_if(! is_string($targetId) && ! is_int($targetId), 401);

        $impersonator = $request->attributes->get(SessionAttribute::IMPERSONATOR);
        $sessionId = $request->attributes->get(SessionAttribute::NAME);

        $stop->handle(
            is_string($impersonator) ? $impersonator : null,
            (string) $targetId,
            is_string($sessionId) ? $sessionId : null,
        );

        return ApiEnvelope::single($request, [
            'impersonation_ended' => true,
            // The impersonation token is dead; the Super Admin's own session
            // was never touched, so the client switches back to the token it
            // still holds rather than signing in again.
            'resume_with_original_token' => true,
        ]);
    }
}
