<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation;

use App\Modules\Catalog\Domain\Listing\CatalogItemPage;
use App\Modules\Catalog\Domain\Listing\CatalogItemSummary;

/**
 * How a catalog item appears on the wire — §7.3's fields, in `OpenAPI §8`'s
 * shapes.
 *
 * `kind` is sent as its stored code, never a translated label: §7.3's tab names
 * are the SPA's to render, and a label here would make the field unusable as
 * the value `filter[kind]` needs it to be. `unit` and `service_type` are sent
 * as their `enum_lists` codes for the same reason.
 *
 * No price, no cost, no margin: §7.3 is "descriptive data only" and `D-21`
 * keeps all three on the supplier quotation, so `CatalogItemSummary` has no
 * such field to serialise.
 *
 * Timestamps in ISO-8601 UTC (`DB-08`).
 */
final class CatalogItemPayload
{
    /** @return array<string, mixed> */
    public static function of(CatalogItemSummary $item): array
    {
        return [
            'id' => $item->id,
            'kind' => $item->kind,
            'name' => $item->name,
            'product_code' => $item->productCode,
            'category' => $item->category,
            'unit' => $item->unit,
            'service_type' => $item->serviceType,
            'company' => $item->company,
            'description' => $item->description,
            'notes' => $item->notes,
            'is_active' => $item->isActive,
            'is_incomplete' => $item->isIncomplete,
            'created_at' => $item->createdAt->format(DATE_ATOM),
            'updated_at' => $item->updatedAt->format(DATE_ATOM),
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function many(CatalogItemPage $page): array
    {
        return array_map(static fn (CatalogItemSummary $i): array => self::of($i), $page->items);
    }

    /** @return array{page: int, per_page: int, total: int, total_pages: int, has_next_page: bool, has_previous_page: bool} */
    public static function pagination(CatalogItemPage $page): array
    {
        return [
            'page' => $page->page,
            'per_page' => $page->perPage,
            'total' => $page->total,
            'total_pages' => $page->totalPages(),
            'has_next_page' => $page->hasNextPage(),
            'has_previous_page' => $page->hasPreviousPage(),
        ];
    }
}
