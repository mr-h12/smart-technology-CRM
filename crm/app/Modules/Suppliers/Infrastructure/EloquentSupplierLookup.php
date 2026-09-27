<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\Infrastructure;

use App\Modules\Suppliers\Domain\Contracts\SupplierLookupInterface;
use App\Modules\Suppliers\Infrastructure\Eloquent\Supplier;
use App\Support\Database\SameText;

/**
 * {@see SupplierLookupInterface} over `suppliers`.
 *
 * The name is compared by `SameText` (`D-94`'s rule, shared with Customers and
 * Catalog), never by `ILIKE`. A blank name needs no guard —
 * `suppliers_name_not_blank` means no row trims to ''.
 *
 * ponytail: no expression index on `lower(btrim(name))` — one query per import
 * row over a table of tens; add the index when an import is measured slow.
 */
final readonly class EloquentSupplierLookup implements SupplierLookupInterface
{
    public function idsNamed(string $name): array
    {
        return array_values(SameText::where(Supplier::query(), 'name', $name)
            ->orderBy('id')
            ->get(['id'])
            ->map(static fn (Supplier $supplier): string => $supplier->id)
            ->all());
    }

    public function namesFor(array $ids): array
    {
        return Supplier::query()
            ->whereKey($ids)
            ->get(['id', 'name'])
            ->mapWithKeys(static fn (Supplier $supplier): array => [$supplier->id => $supplier->name])
            ->all();
    }
}
