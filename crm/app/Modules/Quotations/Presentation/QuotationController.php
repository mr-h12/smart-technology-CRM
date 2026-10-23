<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Presentation;

use App\Modules\Identity\Domain\Rbac\AuthorizationAttribute;
use App\Modules\Identity\Domain\Rbac\PermissionDecision;
use App\Modules\Quotations\Application\Documents\AttachPurchaseOrderDocument;
use App\Modules\Quotations\Application\Documents\RemovePurchaseOrderDocument;
use App\Modules\Quotations\Application\Listing\ApprovalWaiting;
use App\Modules\Quotations\Application\Listing\BadgeCounts;
use App\Modules\Quotations\Application\Listing\ListQuotations;
use App\Modules\Quotations\Application\Listing\ReadPurchaseOrders;
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
use App\Modules\Quotations\Domain\Listing\PurchaseOrderListCriteria;
use App\Modules\Quotations\Domain\Listing\PurchaseOrderRecord;
use App\Modules\Quotations\Domain\Listing\QuotationDetail;
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

        return ApiEnvelope::single($request, $this->detail($quotations, $detail, $actorId));
    }

    public function store(SaveQuotationRequest $request, CreateQuotation $quotations): JsonResponse
    {
        $created = $quotations->create($request->validated(), self::heldScopes($request), self::actorId($request));

        return ApiEnvelope::single($request, QuotationPayload::of($created->quotation), 201);
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

        return ApiEnvelope::single($request, $this->detail($reader, $updated->quotation, $actorId));
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

        return ApiEnvelope::single($request, $this->detail($reader, $submitted, $actorId));
    }

    /** Module 10 · 1.3. `submit()`'s shape; the answer's `status` is `sent` (`D-90`). */
    public function send(Request $request, string $quotation, SendQuotation $quotations, ShowQuotation $reader): JsonResponse
    {
        $actorId = self::actorId($request);

        $sent = $quotations->send($quotation, $request->headers->get('If-Match'), self::heldScopes($request), $actorId);

        return ApiEnvelope::single($request, $this->detail($reader, $sent, $actorId));
    }

    /**
     * Module 10 · 1.4–1.5. `send()`'s shape with a body; the answer is the
     * answered quotation with its new `etag`, plus `new_version` —
     * `newVersion()`'s fields for the draft to open (owner, C) — after
     * `partial` / `counter`, or `deal_lost` after `rejected` (owner, rule b).
     * After `accepted` the order is the detail's own `purchase_order` (2.2).
     */
    public function respond(RespondQuotationRequest $request, string $quotation, RespondToQuotation $quotations, ShowQuotation $reader): JsonResponse
    {
        $actorId = self::actorId($request);

        $recorded = $quotations->respond(
            $quotation,
            $request->customerResponse(),
            $request->reason(),
            $request->reasonCode(),
            $request->customerPoReference(),
            $request->poDate(),
            $request->headers->get('If-Match'),
            self::heldScopes($request),
            $actorId,
        );

        $answered = $recorded->quotation;
        $payload = $this->detail($reader, $answered, $actorId);

        if ($recorded->newVersion !== null) {
            $payload['new_version'] = [...QuotationPayload::of($recorded->newVersion), 'version' => $recorded->newVersion->version];
        }

        if ($recorded->dealLost !== null) {
            $payload['deal_lost'] = $recorded->dealLost;
        }

        return ApiEnvelope::single($request, $payload);
    }

    /**
     * Module 10 · 2.2 — `GET /purchase-orders` under `quotation.view` (Q10).
     * `index()`'s shape.
     */
    public function purchaseOrders(Request $request, ReadPurchaseOrders $orders): JsonResponse
    {
        $page = $orders->page(PurchaseOrderListCriteria::fromQuery($request->query()), self::heldScopes($request), self::actorId($request));

        return ApiEnvelope::collection(
            $request,
            array_map(static fn (PurchaseOrderRecord $order): array => QuotationPayload::purchaseOrder($order, $page->customerNames[$order->customerId] ?? null), $page->items),
            QuotationPayload::pagination($page),
        );
    }

    /**
     * Module 10 · 2.3 — `POST /purchase-orders/{id}/documents` under
     * `quotation.record_customer_response`; `DealController::uploadDocument()`'s shape.
     */
    public function uploadPurchaseOrderDocument(UploadPurchaseOrderDocumentRequest $request, string $purchaseOrder, AttachPurchaseOrderDocument $documents): JsonResponse
    {
        $file = $request->document();

        return ApiEnvelope::single($request, QuotationPayload::document($documents->handle(
            $purchaseOrder,
            $file->getPathname(),
            // The name the browser sent — display-only (§17); never the path.
            $file->getClientOriginalName(),
            self::heldScopes($request),
            self::actorId($request),
        )), 201);
    }

    /** F-47 · 3 (D-117) — `204`: nothing to serialise once the file is gone. */
    public function removePurchaseOrderDocument(Request $request, string $purchaseOrder, string $file, RemovePurchaseOrderDocument $documents): Response
    {
        $documents->handle($purchaseOrder, $file, self::heldScopes($request), self::actorId($request));

        return response()->noContent();
    }

    /** Module 10 · 2.2 — `GET /purchase-orders/{id}`; the 404 and the scope are `ReadPurchaseOrders::one()`'s. */
    public function purchaseOrder(Request $request, string $purchaseOrder, ReadPurchaseOrders $orders): JsonResponse
    {
        return ApiEnvelope::single($request, QuotationPayload::purchaseOrderDetail($orders->one($purchaseOrder, self::heldScopes($request), self::actorId($request))));
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

        return ApiEnvelope::single($request, $this->detail($reader, $approved, $actorId));
    }

    /**
     * Module 8 Point 1.3. `update()`'s body and answer,
     * `approve()`'s route permission; the re-read quotation is `approved`.
     */
    public function editAndApprove(SaveQuotationRequest $request, string $quotation, EditAndApproveQuotation $quotations, ShowQuotation $reader): JsonResponse
    {
        $actorId = self::actorId($request);

        $updated = $quotations->approve($quotation, $request->validated(), $request->headers->get('If-Match'), self::heldScopes($request), $actorId);

        return ApiEnvelope::single($request, $this->detail($reader, $updated->quotation, $actorId));
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

        return ApiEnvelope::single($request, $this->detail($reader, $returned, $actorId));
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
     * Module 8 Point 2.3 — `GET /badges` (Q5): the caller's sidebar
     * counters (7.5d added `reports`). `auth` only, so `heldScopes()` is not asked here; the use case
     * decides the `approve` reach itself and answers `0` where there is none.
     */
    public function badges(Request $request, BadgeCounts $badges): JsonResponse
    {
        return ApiEnvelope::single($request, $badges->for(self::actorId($request)));
    }

    /**
     * `GET /{id}`'s body, whichever action answers with the quotation: the cost viewer's extras
     * (the supplier names and the builder's `entry`) are read only for a caller who may see them.
     *
     * @return array<string, mixed>
     */
    private function detail(ShowQuotation $reader, QuotationDetail $quotation, string $actorId): array
    {
        $costs = $reader->revealsCosts($actorId);
        $lineNames = $reader->lineNames($quotation);
        $supplierNames = $costs ? $reader->supplierNames($quotation) : [];

        return QuotationPayload::detail(
            $quotation,
            $costs,
            $this->waiting,
            $lineNames,
            $supplierNames,
            $costs ? $reader->entry($quotation, $lineNames, $supplierNames) : null,
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
