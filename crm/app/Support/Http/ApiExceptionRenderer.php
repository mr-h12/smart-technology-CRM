<?php

declare(strict_types=1);

namespace App\Support\Http;

use App\Modules\Identity\Domain\Authentication\AuthenticationRefused;
use App\Modules\Identity\Domain\Rbac\AuthorizationRefused;
use App\Modules\Identity\Presentation\ApiEnvelope;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * `OpenAPI §5` — "All non-2xx responses use this shape. Never return
 * framework-default HTML or unstructured error objects."
 *
 * ── Why this is not in a module ────────────────────────────────────────────
 *
 * Because it is wired from `bootstrap/app.php`, and rendering an exception is
 * the framework's job rather than a module's. `deptrac` analyses `./app/Modules`
 * only, so a class here may reach *into* a module while nothing in a module
 * reaches out to it — which is the direction that keeps the modules extractable
 * (`ERP-01`).
 *
 * ── What it covers, and what it does not ───────────────────────────────────
 *
 * The five shapes Module 1 can produce: an authentication refusal, an
 * authorisation refusal, a validation failure, an unauthenticated call, and a
 * throttled one. §5.1's table has thirteen rows; the remaining eight belong to
 * the modules that can raise them, and pre-building them here would be eight
 * handlers with no caller and no test.
 */
final class ApiExceptionRenderer
{
    /** `SEC-13`/`§5`: only API traffic gets the JSON envelope; the SPA shell is HTML. */
    public static function applies(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }

    public static function refusal(AuthenticationRefused $exception, Request $request): JsonResponse
    {
        $reason = $exception->reason;

        return ApiEnvelope::error(
            $request,
            $reason->status(),
            $reason->errorCode(),
            (string) __($reason->messageKey()),
            // §5.1: "Use specific stable codes inside `details` for expected
            // rules". The HTTP code table is closed; this is where the actual
            // reason survives, which matters most for 403, whose only code is
            // the generic `permission_denied`.
            [['code' => $reason->value, 'message' => (string) __($reason->messageKey())]],
        );
    }

    /**
     * `SEC-09` · §3.12 rule 1 — 403 for an authenticated caller who may not do
     * this.
     *
     * The message names the action and nothing else. `OpenAPI §5.1` allows one
     * 403 code, and a refusal that explained *why* — which role holds it, what
     * scope was needed — would be a description of the permission matrix handed
     * to the one caller who has just proved they should not see it.
     */
    public static function authorization(AuthorizationRefused $exception, Request $request): JsonResponse
    {
        return ApiEnvelope::error(
            $request,
            403,
            AuthorizationRefused::ERROR_CODE,
            (string) __('identity.refusal.permission_denied'),
            [[
                'code' => AuthorizationRefused::DETAIL_CODE,
                'message' => (string) __('identity.refusal.unauthorized_action', [
                    'ability' => $exception->ability(),
                ]),
            ]],
        );
    }

    public static function validation(ValidationException $exception, Request $request): JsonResponse
    {
        $details = [];

        foreach ($exception->errors() as $field => $messages) {
            // errors() is documented as array<string, array<int, string>> and
            // is not typed as such. Narrowing rather than trusting: the values
            // end up in a response body, and a nested array cast to string is
            // the word "Array" shown to a user.
            if (! is_array($messages)) {
                continue;
            }

            foreach ($messages as $message) {
                if (! is_string($message)) {
                    continue;
                }

                $details[] = [
                    'field' => (string) $field,
                    'code' => 'invalid',
                    'message' => $message,
                ];
            }
        }

        return ApiEnvelope::error(
            $request,
            422,
            'validation_failed',
            (string) __('identity.errors.validation_failed'),
            $details,
        );
    }

    /**
     * `OpenAPI §5.1`: 401 `authentication_required` — "Missing, expired,
     * locked, or invalid authentication."
     *
     * One answer for all four. `ResolveSessionUser` has already collapsed the
     * distinction, and §3.1's rule about deactivated, locked, expired and
     * force-logged-out accounts is that none of them may call a protected
     * endpoint — not that each gets its own explanation.
     */
    public static function unauthenticated(AuthenticationException $exception, Request $request): JsonResponse
    {
        return ApiEnvelope::error(
            $request,
            401,
            'authentication_required',
            (string) __('identity.refusal.session_invalid'),
        );
    }

    /** `SEC-11` · §5.1: 429 with `Retry-After`, which the table makes mandatory. */
    public static function throttled(ThrottleRequestsException $exception, Request $request): JsonResponse
    {
        $response = ApiEnvelope::error(
            $request,
            429,
            'rate_limit_exceeded',
            (string) __('identity.errors.rate_limited'),
        );

        $retryAfter = $exception->getHeaders()['Retry-After'] ?? null;

        // Copied from the framework's own header rather than recomputed: a
        // Retry-After that disagrees with the limiter is worse than none,
        // because a client obeys it and is refused again.
        $response->headers->set('Retry-After', is_scalar($retryAfter) ? (string) $retryAfter : '60');

        return $response;
    }
}
