<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation;

use App\Modules\Identity\Application\Authentication\AuthenticateUser;
use App\Modules\Identity\Domain\Authentication\IdleTimeout;
use App\Modules\Identity\Domain\Authentication\Profile;
use App\Modules\Identity\Domain\Contracts\ProfileReaderInterface;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/v1/auth/login`.
 *
 * Thin, as Coding Standards requires: the Form Request has validated, the use
 * case decides, this serialises. It holds no rule about locking, idling or
 * suspension — a refusal leaves here as an exception the shared handler renders
 * into `OpenAPI §5`'s envelope.
 */
final class LoginController
{
    /**
     * Dependencies arrive per call, not through the constructor.
     *
     * `Route::getController()` memoises the controller on the Route object for
     * the life of the process, so a constructor-injected service outlives the
     * request that built it — Point 5.4 measured exactly that going wrong on an
     * authorisation path.
     */
    public function __invoke(
        LoginRequest $request,
        AuthenticateUser $authenticate,
        ProfileReaderInterface $profiles,
    ): JsonResponse {
        $issued = $authenticate->handle(
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            $request->getClientIp(),
            $request->userAgent(),
        );

        $profile = $profiles->for($issued->account->id);

        return ApiEnvelope::single($request, [
            // The only time the token exists outside the client. `user_sessions`
            // holds its SHA-256 digest and nothing else.
            'token' => $issued->token->value,
            'token_type' => 'Bearer',
            // D-29, told to the client rather than assumed by it. Seconds, so a
            // PWA can schedule a warning without parsing a duration.
            'idle_timeout_seconds' => IdleTimeout::HOURS * 3600,
            'user' => $profile instanceof Profile ? $profile->toArray() : null,
        ], 201);
    }
}
