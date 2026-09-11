<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Presentation;

use App\Modules\Identity\Domain\Rbac\AuthorizationAttribute;
use App\Modules\Identity\Domain\Rbac\PermissionDecision;
use App\Modules\Quotations\Application\Writing\CreateQuotation;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * `OpenAPI §7.1`'s `/quotations` — the write half, Module 7 Step 3. Thin, as
 * `CLAUDE.md` requires: validate, invoke a use case, serialise.
 *
 * `heldScopes()` and `actorId()` are the third copies of `DealController`'s
 * (and `CustomerController`'s) — recorded in `CHECKLIST.md`'s debt register by
 * Point 3.4 rather than extracted here, because a shared helper is a change to
 * two other modules' controllers and this point's approved scope is one route.
 */
final class QuotationController
{
    public function store(CreateQuotationRequest $request, CreateQuotation $quotations): JsonResponse
    {
        $created = $quotations->create($request->validated(), self::heldScopes($request), self::actorId($request));

        // The key is absent rather than an empty list when nothing is over:
        // a client checking `meta.warnings` for truthiness and one checking for
        // the key both get the same answer (`CustomerController::saved()`).
        $warnings = QuotationPayload::warnings($created->quantityWarnings);

        return ApiEnvelope::single(
            $request,
            QuotationPayload::of($created->quotation),
            201,
            $warnings === [] ? [] : ['warnings' => $warnings],
        );
    }

    /**
     * §3.2's scope codes for this caller, as the middleware left them.
     *
     * @return list<string>
     */
    private static function heldScopes(Request $request): array
    {
        $decision = $request->attributes->get(AuthorizationAttribute::NAME);

        if (! $decision instanceof PermissionDecision) {
            // The route carries `permission:quotation.create`, so the attribute
            // is always there. Reaching here means the route lost its
            // middleware — a configuration error, not an empty scope.
            throw new RuntimeException('The quotation routes require the permission middleware.');
        }

        return $decision->scopeValues();
    }

    private static function actorId(Request $request): string
    {
        $user = $request->user();

        if ($user === null) {
            throw new RuntimeException('The quotation routes require authentication.');
        }

        $id = $user->getAuthIdentifier();

        if (! is_string($id) && ! is_int($id)) {
            throw new RuntimeException('The authenticated user has no usable identifier.');
        }

        return (string) $id;
    }
}
