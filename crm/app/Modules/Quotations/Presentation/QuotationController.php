<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Presentation;

use App\Modules\Identity\Domain\Rbac\AuthorizationAttribute;
use App\Modules\Identity\Domain\Rbac\PermissionDecision;
use App\Modules\Quotations\Application\Listing\ShowQuotation;
use App\Modules\Quotations\Application\Writing\CreateQuotation;
use App\Modules\Quotations\Application\Writing\CreateQuotationVersion;
use App\Modules\Quotations\Application\Writing\SubmitQuotation;
use App\Modules\Quotations\Application\Writing\UpdateQuotation;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * `OpenAPI §7.1`'s `/quotations` — Module 7 Step 3. Thin, as `CLAUDE.md`
 * requires: validate, invoke a use case, serialise.
 *
 * `heldScopes()` and `actorId()` are the third copies of `DealController`'s
 * (and `CustomerController`'s) — recorded in `CHECKLIST.md`'s debt register by
 * Point 3.4 rather than extracted here, because a shared helper is a change to
 * two other modules' controllers and this point's approved scope is one route.
 */
final class QuotationController
{
    /**
     * Point 3.5. The 404 and the scope are decided in `ShowQuotation`; whether
     * the costs are in the body is a second grant the use case resolves and
     * this method only relays — the SPA never owns a permission decision.
     */
    public function show(Request $request, string $quotation, ShowQuotation $quotations): JsonResponse
    {
        $actorId = self::actorId($request);

        return ApiEnvelope::single($request, QuotationPayload::detail(
            $quotations->one($quotation, self::heldScopes($request), $actorId),
            $quotations->revealsCosts($actorId),
        ));
    }

    public function store(SaveQuotationRequest $request, CreateQuotation $quotations): JsonResponse
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
     * Point 3.6. `If-Match` is read here and handed down as text: whether it
     * is present, well-formed and current is `UpdateQuotation`'s to decide,
     * because those are `OpenAPI §5.1` rows (400 / 409), not routing. The
     * answer is the re-read quotation with its new `etag`, shaped exactly as
     * `show()` shapes it — including the cost gate, which is the same grant.
     */
    public function update(SaveQuotationRequest $request, string $quotation, UpdateQuotation $quotations, ShowQuotation $reader): JsonResponse
    {
        $actorId = self::actorId($request);

        $updated = $quotations->update(
            $quotation,
            $request->validated(),
            $request->headers->get('If-Match'),
            self::heldScopes($request),
            $actorId,
        );

        $warnings = QuotationPayload::warnings($updated->quantityWarnings);

        return ApiEnvelope::single(
            $request,
            QuotationPayload::detail($updated->quotation, $reader->revealsCosts($actorId)),
            200,
            $warnings === [] ? [] : ['warnings' => $warnings],
        );
    }

    /**
     * Point 4.2. No body — `OpenAPI §7.2`'s action is the verb and the path;
     * `If-Match` is handed down as `update()` hands it. The answer is the
     * re-read quotation, `show()`'s shape, its `status` now `pending`.
     */
    public function submit(Request $request, string $quotation, SubmitQuotation $quotations, ShowQuotation $reader): JsonResponse
    {
        $actorId = self::actorId($request);

        $submitted = $quotations->submit($quotation, $request->headers->get('If-Match'), self::heldScopes($request), $actorId);

        return ApiEnvelope::single($request, QuotationPayload::detail($submitted, $reader->revealsCosts($actorId)));
    }

    /**
     * Point 4.3. No body; the answer is `store()`'s shape plus the copy's
     * `version`, because the copy is a create, not an edit of `{id}` — and
     * the number is the one thing the caller cannot know before asking.
     * `store()`'s own answer stays `{id, code}` (3.4's contract).
     */
    public function newVersion(Request $request, string $quotation, CreateQuotationVersion $versions): JsonResponse
    {
        $copy = $versions->create($quotation, self::heldScopes($request), self::actorId($request));

        return ApiEnvelope::single($request, [...QuotationPayload::of($copy), 'version' => $copy->version], 201);
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
            // Every route here carries a `permission:` middleware, so the attribute
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
