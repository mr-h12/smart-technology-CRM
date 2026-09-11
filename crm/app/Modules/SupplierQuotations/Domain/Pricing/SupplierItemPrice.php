<?php

declare(strict_types=1);

namespace App\Modules\SupplierQuotations\Domain\Pricing;

/**
 * One supplier line's price, as a customer quotation needs to read it (Module 7
 * Point 3.3). The three facts §5.1's `unit_cost` line needs from the supplier,
 * and nothing the caller could have sent itself: §5.6 puts the price on the
 * supplier side and forbids the client being its source.
 *
 * ── The currency is an id, and it is nullable ──────────────────────────────
 *
 * `supplier_quotation_items` has no currency of its own — §5.1 says the price
 * is "in the supplier currency", the currency being the parent's
 * (`supplier_quotations.currency_id`). That column is nullable, paired with
 * `total_price` by a CHECK, so a line can carry a price whose currency is
 * unknown. This object reports that honestly as a `null` `currencyId`; the
 * caller (Module 7) applies §5.6 — a price that cannot be converted is a price
 * missing at the supplier, and the save blocks. Resolving the id to a
 * `CurrencyCode` is Admin's job, not this module's: SupplierQuotations holds no
 * `AdminContract` and must not learn one to answer a read.
 */
final readonly class SupplierItemPrice
{
    /**
     * A plain decimal `string`, not a `numeric-string`, on purpose: the values
     * come straight off NUMERIC columns and the caller (Module 7) narrows them
     * with `Decimal::of()` at its own boundary. Proving them here would make
     * SupplierQuotations depend on `Admin`'s `Decimal`, an `AdminContract` this
     * module deliberately does not hold.
     *
     * @param  string  $unitPrice  the supplier's `unit_price`, in the currency `$currencyId` names
     * @param  string|null  $currencyId  the parent offer's currency, or null when it recorded none
     * @param  string  $recordedQuantity  what the supplier quoted (§5.6's over-quantity warning compares to this)
     */
    public function __construct(
        public string $unitPrice,
        public ?string $currencyId,
        public string $recordedQuantity,
    ) {}
}
