<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation;

use App\Modules\Identity\Application\Authentication\SignOut;
use App\Modules\Identity\Domain\Authentication\SessionAttribute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/auth/logout`.
 *
 * Behind the `auth` middleware, so an unauthenticated call is a `401` before it
 * arrives — logging out is not something an anonymous caller does.
 */
final class LogoutController
{
    public function __invoke(Request $request, SignOut $signOut): JsonResponse
    {
        $user = $request->user();

        // Belt and braces against a route that loses its middleware, on an
        // endpoint whose job is revoking a credential.
        abort_if($user === null, 401);

        $sessionId = $request->attributes->get(SessionAttribute::NAME);

        $accountId = $user->getAuthIdentifier();

        // D-61 makes it a UUID string. An identifier that is neither a string
        // nor an int is not somebody this endpoint can sign out, and guessing
        // would mean revoking "Array".
        abort_if(! is_string($accountId) && ! is_int($accountId), 401);

        $signOut->handle(
            (string) $accountId,
            is_string($sessionId) ? $sessionId : null,
        );

        return ApiEnvelope::single($request, ['signed_out' => true]);
    }
}
