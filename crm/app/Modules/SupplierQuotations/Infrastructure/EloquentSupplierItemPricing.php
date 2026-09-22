<?php

declare(strict_types=1);

namespace App\Modules\SupplierQuotations\Infrastructure;

use App\Modules\SupplierQuotations\Domain\Contracts\SupplierItemPricingInterface;
use App\Modules\SupplierQuotations\Domain\Pricing\SupplierItemPrice;
use Illuminate\Database\ConnectionInterface;

/**
 * {@see SupplierItemPricingInterface} over `supplier_quotation_items`.
 *
 * A read through the query builder, not the `SupplierQuotation` model: the
 * price, the recorded quantity and the parent's currency are three scalars from
 * two tables, and hydrating an offer with all its lines to answer "what does
 * this one line cost?" would be the work `find()` does for a screen that needs
 * it. `DB-01` is honoured on **both** tables — a soft-deleted offer hides its
 * live lines too, because a quotation may not be priced from an archived offer.
 *
 * No cast and no float: `unit_price` and `quantity` arrive from PostgreSQL as
 * the NUMERIC strings `DB-07` requires, and they are handed on as strings.
 */
final readonly class EloquentSupplierItemPricing implements SupplierItemPricingInterface
{
    public function __construct(private ConnectionInterface $connection) {}

    public function priceFor(string $supplierQuotationItemId): ?SupplierItemPrice
    {
        /** @var object{unit_price: string, consumed_quantity: string, available_quantity: string, currency_id: string|null, catalog_item_id: string}|null $row */
        $row = $this->connection->table('supplier_quotation_items as i')
            ->join('supplier_quotations as o', 'o.id', '=', 'i.supplier_quotation_id')
            ->whereNull('i.deleted_at')
            ->whereNull('o.deleted_at')
            ->where('i.id', $supplierQuotationItemId)
            // `D-81`: the balance is subtracted here, where NUMERIC(14,4)
            // arithmetic is exact and comes back as decimal text — not in
            // PHP, which this module cannot do without `Admin`'s `Decimal`.
            ->select('i.unit_price', 'i.consumed_quantity', 'o.currency_id', 'i.catalog_item_id')
            ->selectRaw('i.quantity - i.consumed_quantity as available_quantity')
            ->first();

        if ($row === null) {
            return null;
        }

        return new SupplierItemPrice(
            unitPrice: (string) $row->unit_price,
            currencyId: $row->currency_id === null ? null : (string) $row->currency_id,
            consumedQuantity: (string) $row->consumed_quantity,
            availableQuantity: (string) $row->available_quantity,
            catalogItemId: (string) $row->catalog_item_id,
        );
    }
}
