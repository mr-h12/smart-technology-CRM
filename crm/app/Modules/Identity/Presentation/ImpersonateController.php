<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation;

use App\Modules\Identity\Application\Impersonation\StartImpersonation;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/auth/impersonate/{user}` — `SEC-10`'s Login As.
 *
 * ── Two authorisation checks, and they are not the same check twice ────────
 *
 * The route carries `permission:admin.login_as`, which enforces §3.11's matrix
 * row. {@see StartImpersonation} then asks §3.1 whether the caller is the
 * Super Admin. §3.12 rule 5 makes the matrix configuration an administrator can
 * change without a deployment; `SEC-10` is a requirement they cannot. The
 * middleware guards the first, the use case the second.
 *
 * ── 201, and what the body is for ──────────────────────────────────────────
 *
 * A session was created, so `OpenAPI §7.1`'s creation semantics apply and the
 * shape matches `POST /auth/login`, which the SPA already handles. The body
 * names the account being impersonated so the client can put it in its own
 * chrome — a Super Admin who cannot tell they are impersonating is the failure
 * mode this whole feature is one keystroke away from.
 */
final class ImpersonateController
{
    public function __invoke(Request $request, string $user, StartImpersonation $start): JsonResponse
    {
        $caller = $request->user();

        abort_if($caller === null, 401);

        $callerId = $caller->getAuthIdentifier();

        abort_if(! is_string($callerId) && ! is_int($callerId), 401);

        $impersonation = $start->handle(
            (string) $callerId,
            $user,
            $request->getClientIp(),
            $request->userAgent(),
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );

        return ApiEnvelope::single($request, [
            // The one and only time this value exists in plaintext.
            'token' => $impersonation->token->value,
            'impersonating' => [
                'id' => $impersonation->targetId,
                'name' => $impersonation->targetName,
                'role' => $impersonation->targetRoleSlug,
            ],
            'impersonator_id' => $impersonation->impersonatorId,
        ], 201);
    }
}
