<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Domain\Pricing;

use RuntimeException;

/**
 * §5.6: "Product or price missing at the supplier → **block save**." The line
 * cannot be priced, so the whole quotation is refused — `CreateQuotation` throws
 * this inside `DB-11`'s transaction and nothing is written.
 *
 * ── Two reasons, one documented and one its honest neighbour ───────────────
 *
 * `supplier_price_missing` is §5.6's own case: the supplier line is gone,
 * soft-deleted, or carries a price whose currency the offer never recorded — a
 * number that cannot be converted, which the specification (`supplier_quotations`
 * "a price with no currency cannot be compared, converted or printed") treats as
 * no usable price at all.
 *
 * `fx_rate_missing` is the case §5.6 does not name: the supplier priced the line
 * in a currency the quotation does not use, and no `fx_rates` row converts the
 * pair at creation. `D-09` requires the rate be captured at creation, so a pair
 * with no rate cannot be captured and the quotation cannot be priced. It blocks
 * for the same reason and is a **distinct** code so the person is told to record
 * the rate, not to fix a supplier price that is fine. This code is beyond §5.6's
 * literal wording and is flagged for the owner (`CHECKLIST.md`).
 *
 * The HTTP mapping to `422 business_rule_blocked` with `$reason` as the detail
 * code is `POST /quotations`' (Step 3 Point 3.4); this is the domain refusal it
 * renders.
 */
final class QuotationNotPriceable extends RuntimeException
{
    public const SUPPLIER_PRICE_MISSING = 'supplier_price_missing';

    public const FX_RATE_MISSING = 'fx_rate_missing';

    private function __construct(
        public readonly string $reason,
        public readonly string $supplierQuotationItemId,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function priceMissing(string $supplierQuotationItemId): self
    {
        return new self(
            self::SUPPLIER_PRICE_MISSING,
            $supplierQuotationItemId,
            "Supplier line {$supplierQuotationItemId} has no usable price (§5.6).",
        );
    }

    public static function fxRateMissing(string $supplierQuotationItemId): self
    {
        return new self(
            self::FX_RATE_MISSING,
            $supplierQuotationItemId,
            "No exchange rate to convert supplier line {$supplierQuotationItemId} at creation (D-09).",
        );
    }
}
