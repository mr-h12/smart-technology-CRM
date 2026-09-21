<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\Infrastructure;

use App\Modules\Suppliers\Domain\Contracts\SupplierLookupInterface;
use App\Modules\Suppliers\Infrastructure\Eloquent\Supplier;

/**
 * {@see SupplierLookupInterface} over `suppliers`.
 *
 * `lower(btrim(name)) = lower(?)` rather than `ILIKE`, for the reason
 * `EloquentCatalogItemDirectory::findProductIdByName` gives: a literal name
 * with `%` or `_` in it must not become a wildcard. A blank name needs no
 * guard — `suppliers_name_not_blank` means no row trims to ''.
 *
 * ponytail: no expression index on `lower(btrim(name))` — one query per import
 * row over a table of tens; add the index when an import is measured slow.
 */
final readonly class EloquentSupplierLookup implements SupplierLookupInterface
{
    public function idsNamed(string $name): array
    {
        return array_values(Supplier::query()
            ->whereRaw('lower(btrim(name)) = lower(?)', [trim($name)])
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
