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

    /**
     * F-40 · 1.4 (`D-107`): each item's unit, as the `units` managed list
     * labels it in both languages. An item with no unit, or a code the list
     * never held, has no entry; an archived entry still names its unit.
     *
     * @param  list<string>  $catalogItemIds
     * @return array<string, array{en: string, ar: string}> id => labels
     */
    public function unitsOf(array $catalogItemIds): array;
}
