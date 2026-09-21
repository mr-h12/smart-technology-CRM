<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\Presentation;

use App\Modules\Suppliers\Application\Importing\ImportSuppliers;
use App\Modules\Suppliers\Application\Listing\ListSuppliers;
use App\Modules\Suppliers\Application\Writing\SaveSupplier;
use App\Modules\Suppliers\Domain\Listing\SupplierListCriteria;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * §8's Suppliers screen, as `OpenAPI §7.1`'s conventional routes.
 *
 * The methods do what `CLAUDE.md` allows a controller to do and nothing else:
 * validate, invoke a use case, serialise.
 *
 * ── Nothing is read off the authorisation decision, and that is not a slip ──
 *
 * `CustomerController` pulls the caller's scopes off the request because §3.3
 * gives customers five of them. §3.7 gives suppliers one — `All`, for every
 * role in both its columns — so there is no scope to read and reading one would
 * be a value this endpoint then had to pretend to use. `SEC-09` still holds:
 * the route's `permission:catalog.view` middleware is the enforcement, and
 * `SupplierListEndpointTest` withdraws that grant from the database and expects
 * a 403.
 */
final class SupplierController
{
    public function index(Request $request, ListSuppliers $suppliers): JsonResponse
    {
        // Parsed in Domain rather than by a Form Request: OpenAPI §6.1 and §6.2
        // require `400 invalid_request` for a bad page size or an unknown
        // filter, and a Form Request failure is a 422.
        $page = $suppliers->handle(SupplierListCriteria::fromQuery($request->query()));

        return ApiEnvelope::collection($request, SupplierPayload::many($page), SupplierPayload::pagination($page));
    }

    public function show(Request $request, string $supplier, ListSuppliers $suppliers): JsonResponse
    {
        return ApiEnvelope::single($request, SupplierPayload::of($suppliers->one($supplier)));
    }

    public function store(SaveSupplierRequest $request, SaveSupplier $suppliers): JsonResponse
    {
        return ApiEnvelope::single(
            $request,
            SupplierPayload::of($suppliers->create($request->validated(), self::actorId($request))),
            201,
        );
    }

    public function update(SaveSupplierRequest $request, string $supplier, SaveSupplier $suppliers): JsonResponse
    {
        return ApiEnvelope::single(
            $request,
            SupplierPayload::of($suppliers->update($supplier, $request->validated(), self::actorId($request))),
        );
    }

    /** `D-85` (F-09 · 1.4) — the counts of one import; the file itself is not kept. */
    public function import(ImportSuppliersRequest $request, ImportSuppliers $suppliers): JsonResponse
    {
        $upload = $request->upload();

        return ApiEnvelope::single($request, SupplierPayload::importBatch($suppliers->handle(
            $upload->getRealPath(),
            $upload->getClientOriginalName(),
            self::actorId($request),
        )), 201);
    }

    /**
     * The signed-in person, for `DB-02`'s `created_by`/`updated_by`.
     *
     * Taken from the request because `auth` has already resolved it. The reads
     * above need nothing from the caller — §3.7 has no scope — so this appears
     * only now, with the first write.
     */
    private static function actorId(Request $request): string
    {
        $id = $request->user()?->getAuthIdentifier();

        if (! is_string($id)) {
            // The route carries `auth`, so this cannot be reached. Answering
            // with a placeholder would put a wrong actor in the audit log,
            // which is worse than failing loudly.
            throw new RuntimeException('The supplier write routes require an authenticated caller.');
        }

        return $id;
    }
}
