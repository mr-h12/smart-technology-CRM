<?php

declare(strict_types=1);

namespace App\Modules\SupplierQuotations\Presentation;

use App\Modules\SupplierQuotations\Application\Listing\ListSupplierQuotations;
use App\Modules\SupplierQuotations\Application\Writing\CreateSupplierQuotation;
use App\Modules\SupplierQuotations\Application\Writing\UpdateSupplierQuotation;
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
        return ApiEnvelope::single($request, SupplierQuotationPayload::detail($quotations->one($supplierQuotation)));
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
