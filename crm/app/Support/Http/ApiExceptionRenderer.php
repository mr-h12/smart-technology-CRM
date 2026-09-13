<?php

declare(strict_types=1);

namespace App\Support\Http;

use App\Modules\Admin\Domain\Listing\InvalidListingQuery;
use App\Modules\Catalog\Domain\Listing\CatalogItemNotFound;
use App\Modules\Catalog\Domain\Listing\InvalidCatalogItemListQuery;
use App\Modules\Customers\Domain\Listing\CustomerNotFound;
use App\Modules\Customers\Domain\Listing\InvalidCustomerListQuery;
use App\Modules\Deals\Domain\Approval\DealApprovalRefused;
use App\Modules\Deals\Domain\Approval\DealStatusTransitionRefused;
use App\Modules\Deals\Domain\Listing\DealNotFound;
use App\Modules\Deals\Domain\Listing\InvalidDealListQuery;
use App\Modules\Idempotency\Domain\IdempotencyRefused;
use App\Modules\Identity\Domain\Administration\InvalidListQuery;
use App\Modules\Identity\Domain\Administration\UserAdministrationRefused;
use App\Modules\Identity\Domain\Authentication\AuthenticationRefused;
use App\Modules\Identity\Domain\Authentication\PasswordChangeRefused;
use App\Modules\Identity\Domain\Authentication\SessionRevocationRefused;
use App\Modules\Identity\Domain\Impersonation\ImpersonationRefused;
use App\Modules\Identity\Domain\Rbac\AuthorizationRefused;
use App\Modules\Identity\Domain\RoleAdministration\RoleAdministrationRefused;
use App\Modules\Quotations\Domain\Listing\InvalidQuotationListQuery;
use App\Modules\Quotations\Domain\Listing\QuotationNotFound;
use App\Modules\Quotations\Domain\Pricing\QuotationNotPriceable;
use App\Modules\Quotations\Domain\Writing\QuotationWriteRefused;
use App\Modules\Storage\Domain\Exceptions\UploadRejected;
use App\Modules\SupplierQuotations\Domain\Listing\InvalidSupplierQuotationListQuery;
use App\Modules\SupplierQuotations\Domain\Listing\SupplierQuotationNotFound;
use App\Modules\Suppliers\Domain\Listing\InvalidSupplierListQuery;
use App\Modules\Suppliers\Domain\Listing\SupplierNotFound;
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
     * Module 2's rate history, refusing the same three query mistakes for the
     * same two reasons — `OpenAPI §6.1` and `§6.2`.
     *
     * A separate method because it is a separate exception: Admin may not
     * import Identity's, and this class may import both because it lives
     * outside `./app/Modules` and therefore outside deptrac's boundary. The
     * shape it renders is identical on purpose — one envelope for one contract,
     * whichever module produced it.
     */
    public static function invalidListingQuery(InvalidListingQuery $exception, Request $request): JsonResponse
    {
        return ApiEnvelope::error(
            $request,
            400,
            InvalidListingQuery::ERROR_CODE,
            (string) __('admin.errors.invalid_request'),
            [[
                'field' => $exception->parameter,
                'code' => $exception->detailCode,
                'message' => (string) __($exception->messageKey()),
            ]],
        );
    }

    /**
     * Module 3's list query, on the same two contract rows as the two above.
     *
     * A third method for a third exception, because all three live in their own
     * module's Domain and Domain may depend on nothing — probed, not assumed.
     * The rendered shape is identical on purpose: one envelope for one contract,
     * whichever module produced it.
     */
    public static function invalidCustomerListQuery(InvalidCustomerListQuery $exception, Request $request): JsonResponse
    {
        return ApiEnvelope::error(
            $request,
            400,
            InvalidCustomerListQuery::ERROR_CODE,
            (string) __('customers.errors.invalid_request'),
            [[
                'field' => $exception->parameter,
                'code' => $exception->detailCode,
                'message' => (string) __($exception->messageKey()),
            ]],
        );
    }

    /**
     * `OpenAPI §5.1` — 404 for a customer that is absent **or** out of reach.
     *
     * One response for both, because §5.1 forbids revealing which case applies.
     * `SEC-08` is why the second case exists at all: a row outside the caller's
     * scope must be indistinguishable from a row that is not there.
     */
    public static function customerNotFound(CustomerNotFound $exception, Request $request): JsonResponse
    {
        return ApiEnvelope::error(
            $request,
            404,
            'resource_not_found',
            (string) __($exception->messageKey()),
        );
    }

    /**
     * Module 4's list query, on the same two contract rows as the three above.
     *
     * A fourth method for a fourth exception, for the reason the third one
     * gives: each lives in its own module's Domain, and Domain may depend on
     * nothing — probed, not assumed. The rendered shape is identical on
     * purpose: one envelope for one contract, whichever module produced it.
     */
    public static function invalidSupplierListQuery(InvalidSupplierListQuery $exception, Request $request): JsonResponse
    {
        return ApiEnvelope::error(
            $request,
            400,
            InvalidSupplierListQuery::ERROR_CODE,
            (string) __('suppliers.errors.invalid_request'),
            [[
                'field' => $exception->parameter,
                'code' => $exception->detailCode,
                'message' => (string) __($exception->messageKey()),
            ]],
        );
    }

    /**
     * Module 6's list query, on the same two contract rows as the four above.
     *
     * A fifth method for a fifth exception, for the reason the third and fourth
     * give: each lives in its own module's Domain, and Domain may depend on
     * nothing. The rendered shape is identical on purpose — one envelope for
     * one contract, whichever module produced it.
     */
    public static function invalidSupplierQuotationListQuery(InvalidSupplierQuotationListQuery $exception, Request $request): JsonResponse
    {
        return ApiEnvelope::error(
            $request,
            400,
            InvalidSupplierQuotationListQuery::ERROR_CODE,
            (string) __('supplier_quotations.errors.invalid_request'),
            [[
                'field' => $exception->parameter,
                'code' => $exception->detailCode,
                'message' => (string) __($exception->messageKey()),
            ]],
        );
    }

    /**
     * `OpenAPI §5.1` — 404 for a supplier that is not there.
     *
     * Identical in shape to {@see self::customerNotFound()} though only one of
     * §5.1's two cases can produce it: §3.7 grants every role `Scope::All`, so
     * a supplier is never merely invisible. Shaped for both anyway, so that a
     * future scope arrives at a handler that already cannot leak.
     */
    public static function supplierNotFound(SupplierNotFound $exception, Request $request): JsonResponse
    {
        return ApiEnvelope::error(
            $request,
            404,
            'resource_not_found',
            (string) __($exception->messageKey()),
        );
    }

    /**
     * `OpenAPI §5.1` — 404 for a supplier quotation that is not there.
     *
     * The third of these, and identical to the two above on purpose: one
     * envelope for one contract, whichever module produced the exception. §3.6
     * makes only §5.1's "does not exist" case reachable — every reader holds
     * `Scope::All` — and an absent row and a `DB-01` soft-deleted one answer
     * the same, because §5.1 forbids revealing which.
     */
    public static function supplierQuotationNotFound(SupplierQuotationNotFound $exception, Request $request): JsonResponse
    {
        return ApiEnvelope::error(
            $request,
            404,
            'resource_not_found',
            (string) __($exception->messageKey()),
        );
    }

    /**
     * Module 4's catalog list query, on the same two contract rows as the four
     * above — and the first to reject a `group_by`.
     *
     * A fifth method for a fifth exception, for the reason the third one gives:
     * each lives in its own module's Domain, and Domain may depend on nothing
     * — probed, not assumed. The rendered shape is identical on purpose: one
     * envelope for one contract, whichever module produced it.
     */
    public static function invalidCatalogItemListQuery(InvalidCatalogItemListQuery $exception, Request $request): JsonResponse
    {
        return ApiEnvelope::error(
            $request,
            400,
            InvalidCatalogItemListQuery::ERROR_CODE,
            (string) __('catalog.errors.invalid_request'),
            [[
                'field' => $exception->parameter,
                'code' => $exception->detailCode,
                'message' => (string) __($exception->messageKey()),
            ]],
        );
    }

    /**
     * `OpenAPI §5.1` — 404 for a catalog item that is not there.
     *
     * Identical in shape to {@see self::supplierNotFound()} and for the same
     * reason: §3.7 grants every role `Scope::All`, so only one of §5.1's two
     * cases can produce it, and the handler is shaped for both anyway so that a
     * future scope arrives somewhere that already cannot leak.
     */
    public static function catalogItemNotFound(CatalogItemNotFound $exception, Request $request): JsonResponse
    {
        return ApiEnvelope::error(
            $request,
            404,
            'resource_not_found',
            (string) __($exception->messageKey()),
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

    /**
     * Module 5's list query, on the same two contract rows as the five above.
     *
     * A sixth method for a sixth exception, for the reason the third one
     * gives: each lives in its own module's Domain, and Domain may depend on
     * nothing — probed, not assumed.
     */
    public static function invalidDealListQuery(InvalidDealListQuery $exception, Request $request): JsonResponse
    {
        return ApiEnvelope::error(
            $request,
            400,
            InvalidDealListQuery::ERROR_CODE,
            (string) __('deals.errors.invalid_request'),
            [[
                'field' => $exception->parameter,
                'code' => $exception->detailCode,
                'message' => (string) __($exception->messageKey()),
            ]],
        );
    }

    /**
     * `OpenAPI §5.1` — 404 for a deal that is absent **or** out of reach.
     *
     * Identical in shape to {@see self::customerNotFound()} and for the same
     * reason: §3.4 gives several roles a narrower-than-`All` scope, so both of
     * §5.1's two cases are real here, unlike the two catalog-side handlers
     * above.
     */
    public static function dealNotFound(DealNotFound $exception, Request $request): JsonResponse
    {
        return ApiEnvelope::error(
            $request,
            404,
            'resource_not_found',
            (string) __($exception->messageKey()),
        );
    }

    /** `OpenAPI §6.1`/`§6.2`'s 400 for `GET /quotations` (Point 5.2) — {@see self::invalidDealListQuery()}'s shape. */
    public static function invalidQuotationListQuery(InvalidQuotationListQuery $exception, Request $request): JsonResponse
    {
        return ApiEnvelope::error(
            $request,
            400,
            InvalidQuotationListQuery::ERROR_CODE,
            (string) __('quotations.errors.invalid_request'),
            [[
                'field' => $exception->parameter,
                'code' => $exception->detailCode,
                'message' => (string) __($exception->messageKey()),
            ]],
        );
    }

    /**
     * `OpenAPI §5.1` — 404 for a quotation that is absent **or** out of reach.
     * Identical in shape to {@see self::dealNotFound()}: §3.5's `view` row is
     * the widest spread of scopes in the document, so both of §5.1's cases
     * are real here.
     */
    public static function quotationNotFound(QuotationNotFound $exception, Request $request): JsonResponse
    {
        return ApiEnvelope::error(
            $request,
            404,
            'resource_not_found',
            (string) __($exception->messageKey()),
        );
    }

    /**
     * `OpenAPI §5.1`'s 400 / 409 / 422 for `PATCH /quotations/{id}` — the
     * status and code come from the exception's own table. The 409 entry
     * carries `current_etag`, §5.1's "current version metadata needed to
     * refresh" and §9.2's "safe refresh reference", beside the standard
     * `{field, code, message}` triple.
     */
    public static function quotationWriteRefused(QuotationWriteRefused $exception, Request $request): JsonResponse
    {
        $message = (string) __('quotations.errors.'.$exception->reason);
        $detail = ['field' => $exception->field, 'code' => $exception->reason, 'message' => $message];

        if ($exception->currentEtag !== null) {
            $detail['current_etag'] = $exception->currentEtag;
        }

        return ApiEnvelope::error($request, $exception->status, $exception->errorCode, $message, [$detail]);
    }

    /**
     * `OpenAPI §5.1`'s 400 / 409 for `Idempotency-Key` (Module 7 Point 3.7) —
     * the status and code come from the exception's own table, on
     * `quotationWriteRefused`'s terms, the header named as the detail's field.
     */
    public static function idempotencyRefused(IdempotencyRefused $exception, Request $request): JsonResponse
    {
        $message = (string) __('idempotency.errors.'.$exception->reason);

        return ApiEnvelope::error($request, $exception->status, $exception->errorCode, $message, [
            ['field' => $exception->field, 'code' => $exception->reason, 'message' => $message],
        ]);
    }

    /**
     * `OpenAPI §5.1` — 409 `state_transition_invalid`, this codebase's first
     * use of that row: "Requested state change violates the documented
     * workflow." Flow 3 gives approval exactly one decision point, and
     * deciding a deal that is not `pending` is exactly that violation.
     */
    public static function dealApprovalRefused(DealApprovalRefused $exception, Request $request): JsonResponse
    {
        return ApiEnvelope::error(
            $request,
            409,
            DealApprovalRefused::ERROR_CODE,
            (string) __($exception->messageKey()),
        );
    }

    /**
     * `OpenAPI §5.1` — 409 `state_transition_invalid`, on
     * {@see self::dealApprovalRefused()}'s precedent: same code, a different
     * domain rule (§4.4's graph rather than Flow 3's one-time decision).
     */
    public static function dealStatusTransitionRefused(DealStatusTransitionRefused $exception, Request $request): JsonResponse
    {
        return ApiEnvelope::error(
            $request,
            409,
            DealStatusTransitionRefused::ERROR_CODE,
            (string) __($exception->messageKey()),
        );
    }

    /**
     * `OpenAPI §5.1` — 422 `business_rule_blocked`, "a documented rule blocks
     * the action, such as missing supplier price": §5.6's block, with the
     * exception's `$reason` as the stable detail code (`supplier_price_missing`,
     * or `fx_rate_missing` — the owner's 2026-09-11 addition). `field` names the
     * request's line the way the validator would (0-based), so the SPA points at
     * the line rather than matching a supplier item id; the envelope message is
     * the detail's, on {@see self::roleAdministration()}'s precedent — one
     * reason, one sentence.
     */
    public static function quotationNotPriceable(QuotationNotPriceable $exception, Request $request): JsonResponse
    {
        $message = (string) __('quotations.errors.'.$exception->reason);

        return ApiEnvelope::error(
            $request,
            422,
            'business_rule_blocked',
            $message,
            [[
                'field' => 'lines.'.($exception->lineNo - 1).'.supplier_quotation_item_id',
                'code' => $exception->reason,
                'message' => $message,
            ]],
        );
    }

    /**
     * `OpenAPI §5.1` — 422 `validation_failed`, for a §17 bytes-level refusal.
     *
     * Shaped exactly like {@see self::passwordChange()}: from the SPA's side,
     * a rejected upload is the same thing as a Form Request failure on the
     * `document` field, and `field` says so even though no Form Request rule
     * produced it — `UploadValidatorInterface::validate()` did, after the
     * boundary had already let a syntactically valid file through.
     */
    public static function uploadRejected(UploadRejected $exception, Request $request): JsonResponse
    {
        return ApiEnvelope::error(
            $request,
            422,
            'validation_failed',
            (string) __('identity.errors.validation_failed'),
            [[
                'field' => 'document',
                'code' => $exception->reason->value,
                'message' => (string) __($exception->translationKey()),
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
