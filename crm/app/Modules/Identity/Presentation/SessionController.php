<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation;

use App\Modules\Identity\Application\SessionManagement\ListDeviceSessions;
use App\Modules\Identity\Application\SessionManagement\RevokeDeviceSession;
use App\Modules\Identity\Application\SessionManagement\RevokeOtherDeviceSessions;
use App\Modules\Identity\Domain\Authentication\SessionAttribute;
use App\Modules\Identity\Domain\RoleAdministration\ReferenceListCriteria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `SEC-05` — "active device list + force logout", for the caller's own account.
 *
 * ── No target and no permission ────────────────────────────────────────────
 *
 * Every method reads the account from the guard. There is no `user_id` here
 * and there will not be one: somebody else's devices are §13 screen 2, an
 * administrative screen with its own permission, and giving these endpoints a
 * target would make them that screen without the check it needs — the same
 * reasoning {@see ChangePasswordController} and
 * {@see PasswordChallengeController} already carry.
 *
 * The methods do what `CLAUDE.md` allows a controller to do and nothing else:
 * validate, invoke a use case, serialise.
 */
final class SessionController
{
    public function index(Request $request, ListDeviceSessions $sessions): JsonResponse
    {
        $accountId = self::accountId($request);

        // Parsed in Domain rather than by a Form Request: OpenAPI §6.1 and §6.2
        // require `400 invalid_request` for a bad page size or an unknown
        // filter, and a Form Request failure is a 422.
        $page = $sessions->handle(
            $accountId,
            self::currentSessionId($request),
            ReferenceListCriteria::forSessions($request->query()),
        );

        return ApiEnvelope::collection(
            $request,
            SessionPayload::many($page),
            SessionPayload::pagination($page),
        );
    }

    public function destroy(Request $request, string $session, RevokeDeviceSession $revoke): JsonResponse
    {
        $revoke->handle(self::accountId($request), $session, self::currentSessionId($request));

        // 200 with a body rather than 204. `OpenAPI §3.3` puts a request id on
        // every response and §4.1 puts it in `meta`; a 204 has no body to carry
        // one, so a support report about a revocation would have nothing to
        // quote.
        return ApiEnvelope::single($request, ['revoked' => true, 'session_id' => $session]);
    }

    public function destroyOthers(Request $request, RevokeOtherDeviceSessions $revoke): JsonResponse
    {
        $revoked = $revoke->handle(self::accountId($request), self::currentSessionId($request));

        return ApiEnvelope::single($request, [
            'revoked' => $revoked,
            // Said outright, because the button's whole promise is that it does
            // not sign the caller out — a client should not have to infer that
            // from the absence of a 401 on its next request.
            'current_session_kept' => true,
        ]);
    }

    private static function accountId(Request $request): string
    {
        $user = $request->user();

        // Belt and braces against a route that loses its middleware, on
        // endpoints whose job is revoking credentials.
        abort_if($user === null, 401);

        $accountId = $user->getAuthIdentifier();

        // D-61 makes it a UUID string. An identifier that is neither a string
        // nor an int is not somebody whose devices this endpoint can read.
        abort_if(! is_string($accountId) && ! is_int($accountId), 401);

        return (string) $accountId;
    }

    /** The device the caller is holding, as the guard resolved it. */
    private static function currentSessionId(Request $request): ?string
    {
        $sessionId = $request->attributes->get(SessionAttribute::NAME);

        return is_string($sessionId) ? $sessionId : null;
    }
}
