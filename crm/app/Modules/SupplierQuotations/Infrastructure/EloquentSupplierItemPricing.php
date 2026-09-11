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
        /** @var object{unit_price: string, quantity: string, currency_id: string|null}|null $row */
        $row = $this->connection->table('supplier_quotation_items as i')
            ->join('supplier_quotations as o', 'o.id', '=', 'i.supplier_quotation_id')
            ->whereNull('i.deleted_at')
            ->whereNull('o.deleted_at')
            ->where('i.id', $supplierQuotationItemId)
            ->select('i.unit_price', 'i.quantity', 'o.currency_id')
            ->first();

        if ($row === null) {
            return null;
        }

        return new SupplierItemPrice(
            unitPrice: (string) $row->unit_price,
            currencyId: $row->currency_id === null ? null : (string) $row->currency_id,
            recordedQuantity: (string) $row->quantity,
        );
    }
}
