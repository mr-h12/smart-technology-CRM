<?php

declare(strict_types=1);

namespace App\Support\Http;

use App\Modules\Identity\Domain\Administration\InvalidListQuery;
use App\Modules\Identity\Domain\Administration\UserAdministrationRefused;
use App\Modules\Identity\Domain\Authentication\AuthenticationRefused;
use App\Modules\Identity\Domain\Authentication\PasswordChangeRefused;
use App\Modules\Identity\Domain\Authentication\SessionRevocationRefused;
use App\Modules\Identity\Domain\Impersonation\ImpersonationRefused;
use App\Modules\Identity\Domain\Rbac\AuthorizationRefused;
use App\Modules\Identity\Domain\RoleAdministration\RoleAdministrationRefused;
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

    /**
     * A refused password change — `OpenAPI §5.1`'s 422 `validation_failed`.
     *
     * Shaped exactly like a Form Request failure, `field` included, because
     * from the SPA's side it is one: a submitted field was not acceptable. The
     * caller should not have to handle two different envelopes depending on
     * whether the rule lived in a validator or in a use case.
     */
    public static function passwordChange(PasswordChangeRefused $exception, Request $request): JsonResponse
    {
        $reason = $exception->reason;

        return ApiEnvelope::error(
            $request,
            422,
            'validation_failed',
            (string) __('identity.errors.validation_failed'),
            [[
                'field' => $reason->field(),
                'code' => $reason->value,
                'message' => (string) __($reason->messageKey()),
            ]],
        );
    }

    /**
     * A refused device revocation — `SEC-05`.
     *
     * Two `OpenAPI §5.1` rows, chosen by the refusal: a session that is not
     * this account's is `404 resource_not_found` and deliberately does not say
     * whether it exists, while revoking the calling session is `422
     * business_rule_blocked` — a documented rule blocking the action, with
     * `POST /auth/logout` as the thing to do instead.
     */
    public static function sessionRevocation(SessionRevocationRefused $exception, Request $request): JsonResponse
    {
        $reason = $exception->reason;

        return ApiEnvelope::error(
            $request,
            $reason->status(),
            $reason->errorCode(),
            (string) __($reason->status() === 404
                ? 'identity.errors.resource_not_found'
                : $reason->messageKey()),
            [['code' => $reason->value, 'message' => (string) __($reason->messageKey())]],
        );
    }

    /**
     * A refused user-administration command — §3.11's endpoints.
     *
     * The status and the top-level code come from the refusal itself, because
     * `OpenAPI §5.1` maps them differently: an unassignable role is a `422
     * validation_failed` about the `role_id` field, while an unknown or hidden
     * user is a `404 resource_not_found` that deliberately does not say which.
     */
    public static function administration(UserAdministrationRefused $exception, Request $request): JsonResponse
    {
        $reason = $exception->reason;
        $detail = ['code' => $reason->value, 'message' => (string) __($reason->messageKey())];

        $field = $reason->field();

        if ($field !== null) {
            $detail = ['field' => $field] + $detail;
        }

        return ApiEnvelope::error(
            $request,
            $reason->status(),
            $reason->errorCode(),
            (string) __($reason->status() === 404
                ? 'identity.errors.resource_not_found'
                : 'identity.errors.validation_failed'),
            [$detail],
        );
    }

    /**
     * A refused permission-matrix edit — §3.11's role · permissions row.
     *
     * Three statuses off one exception, for the reason
     * {@see self::administration} needs them too: `OpenAPI §5.1` maps a missing
     * role to `404 resource_not_found`, an unresolvable id to a `422
     * validation_failed` about `permission_ids`, and both §3.12 rule 3 and the
     * Super Admin exception to `422 business_rule_blocked` — a documented rule
     * blocking the action rather than a field the caller typed wrong.
     */
    public static function roleAdministration(RoleAdministrationRefused $exception, Request $request): JsonResponse
    {
        $reason = $exception->reason;
        $detail = ['code' => $reason->value, 'message' => (string) __($reason->messageKey())];

        $field = $reason->field();

        if ($field !== null) {
            $detail = ['field' => $field] + $detail;
        }

        return ApiEnvelope::error(
            $request,
            $reason->status(),
            $reason->errorCode(),
            (string) __(match ($reason->errorCode()) {
                'resource_not_found' => 'identity.errors.resource_not_found',
                'validation_failed' => 'identity.errors.validation_failed',
                default => $reason->messageKey(),
            }),
            [$detail],
        );
    }

    /**
     * `OpenAPI §6.1`/`§6.2` — a list query that cannot be honoured is `400
     * invalid_request`, not a 422.
     *
     * The distinction is load-bearing for the SPA: 422 means the person typed
     * something wrong in a form, 400 means the client built a URL this API does
     * not offer. §6.2 also forbids the third option — ignoring the parameter.
     */
    public static function invalidListQuery(InvalidListQuery $exception, Request $request): JsonResponse
    {
        return ApiEnvelope::error(
            $request,
            400,
            InvalidListQuery::ERROR_CODE,
            (string) __('identity.errors.invalid_request'),
            [[
                'field' => $exception->parameter,
                'code' => $exception->detailCode,
                'message' => (string) __($exception->messageKey()),
            ]],
        );
    }

    /**
     * A refused Login As — `SEC-10`.
     *
     * Three different `OpenAPI §5.1` rows, chosen by the refusal itself: 403
     * `permission_denied` for a caller who is not the Super Admin, 404
     * `resource_not_found` for a target that does not exist **or is hidden**
     * (§5.1 forbids saying which), and 422 `business_rule_blocked` for the
     * rest — "a documented rule blocks the action", which is exactly what
     * `D-34`'s suspension is.
     */
    public static function impersonation(ImpersonationRefused $exception, Request $request): JsonResponse
    {
        $reason = $exception->reason;

        return ApiEnvelope::error(
            $request,
            $reason->status(),
            $reason->errorCode(),
            (string) __($reason->messageKey()),
            [['code' => $reason->value, 'message' => (string) __($reason->messageKey())]],
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
