<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\Domain\Contracts;

/**
 * F-10 · 1.4 — the one thing another module may ask of Suppliers: which
 * suppliers a name means, and what ids are called. `D-86`'s catalog import
 * links an item to the supplier its `supplier` cell names; Catalog may not read
 * `suppliers` itself (`deptrac.modules.yaml`, `SuppliersContract`).
 *
 * Strings in, strings out, on `CatalogProductProvisionerInterface`'s terms: the
 * layer is a `classLike` collector on this one interface, which only holds
 * while the signature names no Suppliers class.
 *
 * Ruling A2: a name matches after trimming and ignoring case, and a deactivated
 * supplier (`is_active = false`) counts. A soft-deleted one does not (`DB-01`).
 * What 0 or 2+ ids mean is the caller's rule, not this lookup's.
 */
interface SupplierLookupInterface
{
    /** @return list<string> */
    public function idsNamed(string $name): array;

    /**
     * An id with no live supplier is left out.
     *
     * @param  list<string>  $ids
     * @return array<string, string> id => name
     */
    public function namesFor(array $ids): array;
}
