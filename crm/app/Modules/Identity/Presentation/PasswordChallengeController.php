<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation;

use App\Modules\Identity\Application\Authentication\RequestPasswordChallenge;
use DateTimeImmutable;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/auth/change-password/challenge` — `SEC-04` step one.
 *
 * ── It takes no body and no target ─────────────────────────────────────────
 *
 * The account is the caller's, read from the guard. A `user_id` here would let
 * anyone with a session mail a verification code to anyone else's inbox, which
 * is a spam relay with the company's own address on it.
 *
 * ── 202, not 200 ───────────────────────────────────────────────────────────
 *
 * `OpenAPI §4.3` — the work the caller cares about is a mail on its way. The
 * response is not evidence the mail arrived, and a 200 with a body would imply
 * it was.
 */
final class PasswordChallengeController
{
    public function __invoke(
        Request $request,
        RequestPasswordChallenge $challenge,
        ConfigRepository $config,
    ): JsonResponse {
        $user = $request->user();

        abort_if($user === null, 401);

        $accountId = $user->getAuthIdentifier();

        abort_if(! is_string($accountId) && ! is_int($accountId), 401);

        $ttl = $config->integer('identity.password_challenge.ttl_minutes');

        $challenge->handle((string) $accountId, new DateTimeImmutable, $ttl);

        return ApiEnvelope::single($request, [
            'challenge_sent' => true,
            // The lifetime, so the SPA can show a countdown without hard-coding
            // a number that lives in configuration (AP-08, D-75's precedent).
            'expires_in_minutes' => $ttl,
        ], 202);
    }
}
