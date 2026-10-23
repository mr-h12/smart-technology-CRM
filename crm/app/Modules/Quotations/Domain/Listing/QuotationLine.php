<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Domain\Listing;

/**
 * One `quotation_items` row as `GET /{id}` reads it (Module 7 Point 3.5) —
 * every column Point 1.3 built, QS.1's `catalog_item_id` and QS.2a's `supplier_id`, `unit_code`, `entered_unit_price`
 * and `tax_percent` (`D-155`), as the decimal text PostgreSQL returns (`DB-07`).
 *
 * The four `unit_cost*` fields, `marginPercent` and `lineCost` are §3.5's
 * "view cost & margin" ({@see self::COST_FIELDS}) — carried here
 * unconditionally, and dropped by the serialiser for a caller without that grant. The domain object is the row;
 * what a caller may see of it is the presentation's decision, made on a
 * permission the use case resolved.
 */
final readonly class QuotationLine
{
    /**
     * §3.5's "view cost & margin" — the keys of {@see self::asRow()} that grant hides, and the supplier's name beside its
     * id. `tax_percent` is not one: the customer's PDF prints the rate (owner, 2026-10-23).
     */
    public const COST_FIELDS = [
        'unit_cost', 'unit_cost_currency', 'unit_cost_fx_rate_at_time', 'unit_cost_base', 'margin_percent', 'line_cost',
        'supplier_id', 'supplier_name', 'entered_unit_price',
    ];

    public function __construct(
        public string $id,
        public int $lineNo,
        /** Provenance only: null on a line saved from QS.2a on (`D-155`). */
        public ?string $supplierQuotationItemId,
        public string $supplierId,
        public string $catalogItemId,
        public ?string $unitCode,
        public string $enteredUnitPrice,
        public ?string $taxPercent,
        public string $unitCost,
        public string $unitCostCurrency,
        public string $unitCostFxRateAtTime,
        public string $unitCostBase,
        public ?string $marginPercent,
        public string $unitPrice,
        public string $quantity,
        public string $lineTotal,
        public string $lineCost,
    ) {}

    /**
     * The row as `quotation_items` stores it, keyed by column — the one list
     * of a line's fields, read by the serialiser (Point 3.5) and by the edit's
     * audit values (Point 3.6) so the two cannot drift.
     *
     * @return array<string, mixed>
     */
    public function asRow(): array
    {
        return [
            'supplier_quotation_item_id' => $this->supplierQuotationItemId,
            'supplier_id' => $this->supplierId,
            'catalog_item_id' => $this->catalogItemId,
            'unit_code' => $this->unitCode,
            'entered_unit_price' => $this->enteredUnitPrice,
            'tax_percent' => $this->taxPercent,
            'quantity' => $this->quantity,
            'unit_price' => $this->unitPrice,
            'line_total' => $this->lineTotal,
            'unit_cost' => $this->unitCost,
            'unit_cost_currency' => $this->unitCostCurrency,
            'unit_cost_fx_rate_at_time' => $this->unitCostFxRateAtTime,
            'unit_cost_base' => $this->unitCostBase,
            'margin_percent' => $this->marginPercent,
            'line_cost' => $this->lineCost,
        ];
    }
}
