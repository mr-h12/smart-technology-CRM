<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Presentation;

use App\Modules\Identity\Domain\Rbac\AuthorizationAttribute;
use App\Modules\Identity\Domain\Rbac\PermissionDecision;
use App\Modules\Quotations\Application\Listing\ApprovalWaiting;
use App\Modules\Quotations\Application\Listing\BadgeCounts;
use App\Modules\Quotations\Application\Listing\ListQuotations;
use App\Modules\Quotations\Application\Listing\ShowQuotation;
use App\Modules\Quotations\Application\Writing\ApproveQuotation;
use App\Modules\Quotations\Application\Writing\CreateQuotation;
use App\Modules\Quotations\Application\Writing\CreateQuotationVersion;
use App\Modules\Quotations\Application\Writing\DeleteQuotation;
use App\Modules\Quotations\Application\Writing\EditAndApproveQuotation;
use App\Modules\Quotations\Application\Writing\RespondToQuotation;
use App\Modules\Quotations\Application\Writing\ReturnQuotation;
use App\Modules\Quotations\Application\Writing\SendQuotation;
use App\Modules\Quotations\Application\Writing\SubmitQuotation;
use App\Modules\Quotations\Application\Writing\TermSuggestions;
use App\Modules\Quotations\Application\Writing\UpdateQuotation;
use App\Modules\Quotations\Domain\Listing\QuotationListCriteria;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
    /** Module 8 Point 2.1 — every answer that carries a status carries `D-11`'s waiting fields. */
    public function __construct(private readonly ApprovalWaiting $waiting) {}

    /**
     * Point 5.4. Parsed in Domain rather than by a Form Request: `OpenAPI §6.1`
     * and `§6.2` want `400 invalid_request` for a bad page size or an unknown
     * filter, and a Form Request failure is a 422 (`DealController::index()`).
     */
    public function index(Request $request, ListQuotations $quotations): JsonResponse
    {
        $criteria = QuotationListCriteria::fromQuery($request->query());
        $page = $quotations->handle($criteria, self::heldScopes($request), self::actorId($request));

        // Point 5.5 — `group_by` reshapes `data` and nothing else; the
        // pagination still counts quotations.
        $data = $criteria->groupBy === null
            ? QuotationPayload::many($page, $this->waiting)
            : QuotationPayload::groups($quotations->grouped($page, $criteria->groupBy), $this->waiting, $page->customerNames);

        return ApiEnvelope::collection($request, $data, QuotationPayload::pagination($page));
    }

    /**
     * Point 3.5. The 404 and the scope are decided in `ShowQuotation`; whether
     * the costs are in the body is a second grant the use case resolves and
     * this method only relays — the SPA never owns a permission decision.
     */
    public function show(Request $request, string $quotation, ShowQuotation $quotations): JsonResponse
    {
        $actorId = self::actorId($request);
        $detail = $quotations->one($quotation, self::heldScopes($request), $actorId);

        // Point 4.5 — `D-36`'s warning rides in `meta`, absent when nothing
        // moved, on `store()`'s convention.
        $warnings = QuotationPayload::warnings($quotations->movedLines($detail), 'supplier_price_changed', 'unit_cost');

        return ApiEnvelope::single(
            $request,
            QuotationPayload::detail($detail, $quotations->revealsCosts($actorId), $this->waiting, $quotations->lineNames($detail)),
            200,
            $warnings === [] ? [] : ['warnings' => $warnings],
        );
    }

    public function store(SaveQuotationRequest $request, CreateQuotation $quotations): JsonResponse
    {
        $created = $quotations->create($request->validated(), self::heldScopes($request), self::actorId($request));

        // The key is absent rather than an empty list when nothing is over:
        // a client checking `meta.warnings` for truthiness and one checking for
        // the key both get the same answer (`CustomerController::saved()`).
        $warnings = QuotationPayload::warnings($created->quantityWarnings, 'quantity_exceeds_recorded', 'quantity');

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

        $warnings = QuotationPayload::warnings($updated->quantityWarnings, 'quantity_exceeds_recorded', 'quantity');

        return ApiEnvelope::single(
            $request,
            QuotationPayload::detail($updated->quotation, $reader->revealsCosts($actorId), $this->waiting, $reader->lineNames($updated->quotation)),
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

        return ApiEnvelope::single($request, QuotationPayload::detail($submitted, $reader->revealsCosts($actorId), $this->waiting, $reader->lineNames($submitted)));
    }

    /** Module 10 · 1.3. `submit()`'s shape; the answer's `status` is `sent` (`D-90`). */
    public function send(Request $request, string $quotation, SendQuotation $quotations, ShowQuotation $reader): JsonResponse
    {
        $actorId = self::actorId($request);

        $sent = $quotations->send($quotation, $request->headers->get('If-Match'), self::heldScopes($request), $actorId);

        return ApiEnvelope::single($request, QuotationPayload::detail($sent, $reader->revealsCosts($actorId), $this->waiting, $reader->lineNames($sent)));
    }

    /**
     * Module 10 · 1.4–1.5. `send()`'s shape with a body; the answer is the
     * answered quotation with its new `etag`, plus `new_version` —
     * `newVersion()`'s fields for the draft to open (owner, C) — after
     * `partial` / `counter`, or `deal_lost` after `rejected` (owner, rule b).
     */
    public function respond(RespondQuotationRequest $request, string $quotation, RespondToQuotation $quotations, ShowQuotation $reader): JsonResponse
    {
        $actorId = self::actorId($request);

        $recorded = $quotations->respond(
            $quotation,
            $request->customerResponse(),
            $request->reason(),
            $request->headers->get('If-Match'),
            self::heldScopes($request),
            $actorId,
        );

        $answered = $recorded->quotation;
        $payload = QuotationPayload::detail($answered, $reader->revealsCosts($actorId), $this->waiting, $reader->lineNames($answered));

        if ($recorded->newVersion !== null) {
            $payload['new_version'] = [...QuotationPayload::of($recorded->newVersion), 'version' => $recorded->newVersion->version];
        }

        if ($recorded->dealLost !== null) {
            $payload['deal_lost'] = $recorded->dealLost;
        }

        return ApiEnvelope::single($request, $payload);
    }

    /**
     * Module 8 Point 1.1. `submit()`'s shape: no body, `If-Match`, the
     * re-read quotation with its `status` now `approved` and
     * `is_self_approved` as §6.5 decided it.
     */
    public function approve(Request $request, string $quotation, ApproveQuotation $quotations, ShowQuotation $reader): JsonResponse
    {
        $actorId = self::actorId($request);

        $approved = $quotations->approve($quotation, $request->headers->get('If-Match'), self::heldScopes($request), $actorId);

        return ApiEnvelope::single($request, QuotationPayload::detail($approved, $reader->revealsCosts($actorId), $this->waiting, $reader->lineNames($approved)));
    }

    /**
     * Module 8 Point 1.3. `update()`'s body and answer (warnings included),
     * `approve()`'s route permission; the re-read quotation is `approved`.
     */
    public function editAndApprove(SaveQuotationRequest $request, string $quotation, EditAndApproveQuotation $quotations, ShowQuotation $reader): JsonResponse
    {
        $actorId = self::actorId($request);

        $updated = $quotations->approve($quotation, $request->validated(), $request->headers->get('If-Match'), self::heldScopes($request), $actorId);

        $warnings = QuotationPayload::warnings($updated->quantityWarnings, 'quantity_exceeds_recorded', 'quantity');

        return ApiEnvelope::single(
            $request,
            QuotationPayload::detail($updated->quotation, $reader->revealsCosts($actorId), $this->waiting, $reader->lineNames($updated->quotation)),
            200,
            $warnings === [] ? [] : ['warnings' => $warnings],
        );
    }

    /**
     * Module 8 Point 1.2. `approve()`'s shape with one body field, the
     * mandatory note; the answer is the re-read quotation, its `status` now
     * `draft` again.
     */
    public function return(ReturnQuotationRequest $request, string $quotation, ReturnQuotation $quotations, ShowQuotation $reader): JsonResponse
    {
        $actorId = self::actorId($request);

        $returned = $quotations->return($quotation, $request->note(), $request->headers->get('If-Match'), self::heldScopes($request), $actorId);

        return ApiEnvelope::single($request, QuotationPayload::detail($returned, $reader->revealsCosts($actorId), $this->waiting, $reader->lineNames($returned)));
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
     * Point 4.4. `204` (the owner's Q5 ruling): nothing to serialise once the
     * row is gone. `OpenAPI §3.3`'s request id still travels — it is the
     * `X-Request-Id` header, which a `204` carries like any other answer.
     */
    public function destroy(Request $request, string $quotation, DeleteQuotation $quotations): Response
    {
        $quotations->delete($quotation, $request->headers->get('If-Match'), self::heldScopes($request), self::actorId($request));

        return response()->noContent();
    }

    /**
     * Point 6.8 — `GET /user-term-suggestions?field=`: the caller's own terms
     * for one of the three term fields (Step 6 Q5). `OpenAPI §4.2` allows no
     * unpaginated collection, so the cap is written as what it is: the first
     * and only page of twenty.
     */
    public function termSuggestions(Request $request, TermSuggestions $suggestions): JsonResponse
    {
        $data = $suggestions->forField($request->query('field'), self::actorId($request));

        return ApiEnvelope::collection($request, $data, [
            'page' => 1,
            'per_page' => TermSuggestions::LIMIT,
            'total' => count($data),
            'total_pages' => 1,
            'has_next_page' => false,
            'has_previous_page' => false,
        ]);
    }

    /**
     * Module 8 Point 2.3 — `GET /badges` (Q5): the caller's two sidebar
     * counters. `auth` only, so `heldScopes()` is not asked here; the use case
     * decides the `approve` reach itself and answers `0` where there is none.
     */
    public function badges(Request $request, BadgeCounts $badges): JsonResponse
    {
        return ApiEnvelope::single($request, $badges->for(self::actorId($request)));
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
