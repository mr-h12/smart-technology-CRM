<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation;

use App\Modules\Catalog\Application\Listing\ListCatalogItems;
use App\Modules\Catalog\Application\Writing\SaveCatalogItem;
use App\Modules\Catalog\Domain\Listing\CatalogItemListCriteria;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * §8's Catalog screen, as `OpenAPI §7.1`'s conventional routes.
 *
 * The methods do what `CLAUDE.md` allows a controller to do and nothing else:
 * validate, invoke a use case, serialise.
 *
 * Nothing is read off the authorisation decision, for the reason
 * `SupplierController` gives: §3.7 grants `Scope::All` to every role in both
 * its columns, so there is no scope to read. `SEC-09` still holds — the route's
 * `permission:catalog.view` middleware is the enforcement, and
 * `CatalogItemListEndpointTest` withdraws that grant from the database and
 * expects a 403.
 */
final class CatalogItemController
{
    public function index(Request $request, ListCatalogItems $items): JsonResponse
    {
        // Parsed in Domain rather than by a Form Request: OpenAPI §6.1 and §6.2
        // require `400 invalid_request` for a bad page size or an unknown
        // filter, sort or group, and a Form Request failure is a 422.
        $page = $items->handle(CatalogItemListCriteria::fromQuery($request->query()));

        return ApiEnvelope::collection($request, CatalogItemPayload::many($page), CatalogItemPayload::pagination($page));
    }

    public function show(Request $request, string $catalogItem, ListCatalogItems $items): JsonResponse
    {
        return ApiEnvelope::single($request, CatalogItemPayload::of($items->one($catalogItem)));
    }

    public function store(SaveCatalogItemRequest $request, SaveCatalogItem $items): JsonResponse
    {
        return ApiEnvelope::single(
            $request,
            CatalogItemPayload::of($items->create($request->validated(), self::actorId($request))),
            201,
        );
    }

    public function update(SaveCatalogItemRequest $request, string $catalogItem, SaveCatalogItem $items): JsonResponse
    {
        return ApiEnvelope::single(
            $request,
            CatalogItemPayload::of($items->update($catalogItem, $request->validated(), self::actorId($request))),
        );
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
            throw new RuntimeException('The catalog write routes require an authenticated caller.');
        }

        return $id;
    }
}
