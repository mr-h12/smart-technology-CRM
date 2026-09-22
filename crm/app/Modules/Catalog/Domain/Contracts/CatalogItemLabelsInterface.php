<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Contracts;

/**
 * F-16 · 1.1 — what a catalog item is called, for a module that holds only its
 * id. A customer quotation line names its supplier line, which names a catalog
 * item; Quotations may not read `catalog_items` itself (`deptrac.modules.yaml`,
 * `CatalogContract`).
 *
 * Strings in, strings out, on `CatalogProductProvisionerInterface`'s terms. §7.3
 * keeps a product's label in `name` and a service's in `service_type`. No
 * `deleted_at` filter, on `CustomerNamesInterface`'s reasoning: a quotation
 * already written keeps naming what it priced.
 */
interface CatalogItemLabelsInterface
{
    /**
     * An id with no row is left out.
     *
     * @param  list<string>  $catalogItemIds
     * @return array<string, string> id => label
     */
    public function labelsOf(array $catalogItemIds): array;
}
