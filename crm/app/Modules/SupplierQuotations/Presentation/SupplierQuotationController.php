<?php

declare(strict_types=1);

namespace App\Modules\SupplierQuotations\Presentation;

use App\Modules\SupplierQuotations\Application\Documents\AttachSupplierQuotationDocument;
use App\Modules\SupplierQuotations\Application\Listing\ListSupplierQuotations;
use App\Modules\SupplierQuotations\Application\Writing\CreateSupplierQuotation;
use App\Modules\SupplierQuotations\Application\Writing\UpdateSupplierQuotation;
use App\Modules\SupplierQuotations\Domain\Listing\SupplierQuotationListCriteria;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * §7.2's supplier quotation, as `OpenAPI §7.1`'s conventional routes.
 *
 * The method does what `CLAUDE.md` allows a controller to do and nothing else:
 * validate, invoke a use case, serialise.
 *
 * Nothing is read off the authorisation decision, for the reason
 * `CatalogItemController` gives and §3.6 repeats in stronger terms: every role
 * that may reach this endpoint holds `Scope::All` under "a shared screen — not
 * restricted by ownership", so there is no scope to read and no owner to file
 * under. `SEC-09` still holds — the route's `permission:supplier_quotation.create`
 * middleware is the enforcement, and the test withdraws that grant from the
 * database and expects a 403.
 */
final class SupplierQuotationController
{
    /**
     * Point 4.3 — §7.2's screen as a list.
     *
     * The criteria are parsed in Domain, not by a Form Request: `OpenAPI §6.1`
     * and §6.2 require `400 invalid_request` for a bad page size or an unknown
     * filter, and a Form Request failure is a 422. `SupplierController::index()`
     * records the same reading.
     *
     * Nothing is read off the authorisation decision, for this class's stated
     * reason — §3.6 gives every reader `Scope::All`.
     */
    public function index(Request $request, ListSupplierQuotations $quotations): JsonResponse
    {
        $page = $quotations->handle(SupplierQuotationListCriteria::fromQuery($request->query()));

        return ApiEnvelope::collection(
            $request,
            SupplierQuotationPayload::many($page),
            SupplierQuotationPayload::pagination($page),
        );
    }

    /**
     * Point 2.3. The 404 is not decided here — `ListSupplierQuotations::one()`
     * throws `OpenAPI §5.1`'s case and the renderer shapes it, so this method
     * stays the three things `CLAUDE.md` allows: invoke, serialise, answer.
     *
     * Nothing is read off the authorisation decision here either. §3.6 grants
     * `view` as `All` to every role that reaches this route, so there is no
     * scope to narrow the read by.
     */
    public function show(Request $request, string $supplierQuotation, ListSupplierQuotations $quotations): JsonResponse
    {
        $quotation = $quotations->one($supplierQuotation);

        return ApiEnvelope::single($request, SupplierQuotationPayload::detail($quotation, $quotations->dealCodeOf($quotation)));
    }

    public function store(SaveSupplierQuotationRequest $request, CreateSupplierQuotation $quotations): JsonResponse
    {
        return ApiEnvelope::single(
            $request,
            SupplierQuotationPayload::of(
                $quotations->create($request->validated(), self::actorId($request)),
            ),
            201,
        );
    }

    /**
     * Point 2.4. §3.6's write row is one cell — "create / edit" — so the route
     * carries the same `supplier_quotation.create` grant `store()` does, and a
     * caller with `view` alone (the CEO) is refused here.
     */
    public function update(SaveSupplierQuotationRequest $request, string $supplierQuotation, UpdateSupplierQuotation $quotations): JsonResponse
    {
        return ApiEnvelope::single(
            $request,
            SupplierQuotationPayload::of(
                $quotations->update($supplierQuotation, $request->validated(), self::actorId($request)),
            ),
        );
    }

    /**
     * Point 5.3 — §7.2's `pdf_file`, "Scan or PDF of the offer".
     *
     * §3.6 fills a third column beside `view` and `create / edit`, so the route
     * carries `permission:supplier_quotation.upload_attachment` and nothing
     * else: the CEO holds `view` as `All` and no cell here, and is refused.
     *
     * **No scope argument**, for this class's standing reason — §3.6 grants
     * `Scope::All` throughout, so `AttachSupplierQuotationDocument::handle()`
     * takes four parameters where `AttachDealDocument` takes five.
     *
     * The 404 for an absent or soft-deleted offer and the 422 for bytes §17
     * refuses are both thrown below this method and shaped by the renderer, so
     * this stays the three things `CLAUDE.md` allows: validate, invoke,
     * serialise.
     */
    public function uploadDocument(
        UploadSupplierQuotationDocumentRequest $request,
        string $supplierQuotation,
        AttachSupplierQuotationDocument $documents,
    ): JsonResponse {
        $file = $request->document();

        return ApiEnvelope::single($request, SupplierQuotationDocumentPayload::of($documents->handle(
            $supplierQuotation,
            $file->getPathname(),
            // The name the browser sent — display-only (§17); never the path.
            $file->getClientOriginalName(),
            self::actorId($request),
        )), 201);
    }

    /**
     * The signed-in person, for `DB-02`'s `created_by`/`updated_by`.
     *
     * Taken from the request because `auth` has already resolved it —
     * `CatalogItemController::actorId()`'s reasoning, verbatim.
     */
    private static function actorId(Request $request): string
    {
        $id = $request->user()?->getAuthIdentifier();

        if (! is_string($id)) {
            // The route carries `auth`, so this cannot be reached. Answering
            // with a placeholder would put a wrong actor in the audit log,
            // which is worse than failing loudly.
            throw new RuntimeException('The supplier quotation write routes require an authenticated caller.');
        }

        return $id;
    }
}
