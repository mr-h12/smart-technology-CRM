<?php

declare(strict_types=1);

namespace App\Modules\SupplierQuotations\Application\Writing;

use App\Modules\Catalog\Domain\Contracts\CatalogProductProvisionerInterface;

/**
 * `D-22` applied to a submitted set of lines: every product as an id, adding
 * the ones the catalog lacks.
 *
 * ── Why it is a class and not a private method ─────────────────────────────
 *
 * Point 3.4 wrote this loop as a private method of `CreateSupplierQuotation`,
 * which was the smallest thing that worked while there was one caller. Point
 * 3.5 is the second — `UpdateSupplierQuotation`'s replacement set is lines like
 * any other — and a second copy of a loop that decides what a line's product is
 * would be the duplicate `CLAUDE.md`'s waste audit names: correct in both
 * places, and wrong the moment one of them changes.
 *
 * ── It does not open a transaction, and that is deliberate ─────────────────
 *
 * It writes to the catalog through Catalog's own audited writer, so it has to
 * run inside the caller's transaction (`DB-11`): a product added for line one
 * must not outlive an offer that line two destroyed. Both callers invoke it
 * inside theirs, and each proves it with a rollback test that reddens when the
 * call is moved out.
 */
final readonly class ResolveLineProducts
{
    public function __construct(private CatalogProductProvisionerInterface $catalog) {}

    /**
     * The name is replaced rather than kept beside the id: `catalog_item_id` is
     * the column a line is stored in, and a leftover `product_name` would reach
     * an insert that has no such column. The boundary has already refused a
     * line carrying both (Point 3.3), so a line here holds one or the other.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public function resolve(array $items, string $actorId): array
    {
        foreach ($items as $index => $line) {
            $name = $line['product_name'] ?? null;

            if (! is_string($name)) {
                continue;
            }

            unset($items[$index]['product_name']);

            $items[$index]['catalog_item_id'] = $this->catalog->productIdFor($name, $actorId);
        }

        return $items;
    }
}
