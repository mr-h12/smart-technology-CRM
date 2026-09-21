<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\Presentation;

use App\Modules\Suppliers\Domain\Importing\ImportSummary;
use App\Modules\Suppliers\Domain\Listing\SupplierPage;
use App\Modules\Suppliers\Domain\Listing\SupplierSummary;

/**
 * How a supplier appears on the wire — §7.1's fields, in `OpenAPI §8`'s shapes.
 *
 * `color_rating` is sent as its stored code, never a translated label or a hex
 * value. §7.1 gives the four colours meanings and Design System §6.4 requires
 * the chip to carry an icon and text rather than colour alone — both of which
 * are the SPA's to render. A label here would also make the field unusable as a
 * filter value, which is exactly what `filter[color_rating]` needs it to be.
 *
 * No price, no cost, no margin: `D-21` keeps all three on the supplier
 * quotation, and `SupplierSummary` has no such field to serialise.
 *
 * Timestamps in ISO-8601 UTC (`DB-08`).
 */
final class SupplierPayload
{
    /** @return array<string, mixed> */
    public static function of(SupplierSummary $supplier): array
    {
        return [
            'id' => $supplier->id,
            'name' => $supplier->name,
            'type' => $supplier->type,
            'color_rating' => $supplier->colorRating,
            'phone' => $supplier->phone,
            'contact_person' => $supplier->contactPerson,
            'has_open_account' => $supplier->hasOpenAccount,
            'is_active' => $supplier->isActive,
            'is_incomplete' => $supplier->isIncomplete,
            'created_at' => $supplier->createdAt->format(DATE_ATOM),
            'updated_at' => $supplier->updatedAt->format(DATE_ATOM),
        ];
    }

    /**
     * `D-85` (F-09 · 1.4) — the customers' `ImportBatchPayload` shape, so the
     * SPA reads one import result the same way whichever list it came from.
     *
     * @return array<string, mixed>
     */
    public static function importBatch(ImportSummary $batch): array
    {
        return [
            'id' => $batch->id,
            'original_filename' => $batch->originalFilename,
            'row_count' => $batch->rowCount,
            'imported_count' => $batch->importedCount,
            'incomplete_count' => $batch->incompleteCount,
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function many(SupplierPage $page): array
    {
        return array_map(static fn (SupplierSummary $s): array => self::of($s), $page->items);
    }

    /** @return array{page: int, per_page: int, total: int, total_pages: int, has_next_page: bool, has_previous_page: bool} */
    public static function pagination(SupplierPage $page): array
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
