<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Application\Writing;

use App\Modules\Admin\Domain\Contracts\CurrencyRepositoryInterface;
use App\Modules\Admin\Domain\Contracts\FxRateRepositoryInterface;
use App\Modules\Admin\Domain\Money\Currency;
use App\Modules\Admin\Domain\Money\CurrencyCode;
use App\Modules\Admin\Domain\Money\Decimal;
use App\Modules\Customers\Domain\Contracts\CustomerTaxStatusInterface;
use App\Modules\Quotations\Domain\Pricing\PricedLine;
use App\Modules\Quotations\Domain\Pricing\QuotationNotPriceable;
use App\Modules\Quotations\Domain\Pricing\QuotationTotals;
use App\Modules\SupplierQuotations\Domain\Contracts\SupplierItemPricingInterface;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * §5 applied to one submitted quotation body — the block `CreateQuotation`
 * (Point 3.3) carried inline, lifted out at Point 3.6 because the edit
 * re-prices exactly the same way. One implementation of the pricing rules,
 * called from two use cases; a second copy is the defect the waste audit
 * names first.
 *
 * ── What it computes, and why the caller could not ─────────────────────────
 *
 * Per line (§5.1): the supplier's price and the offer's currency come from
 * {@see SupplierItemPricingInterface}; the FX rate is captured at `$at`
 * (`D-09` — creation, or the edit that re-prices a Draft) from
 * {@see FxRateRepositoryInterface::effectiveRate()}; and {@see PricedLine}
 * applies the margin. Per quotation (§5.2): {@see QuotationTotals} totals to
 * the tax base and {@see \App\Modules\Admin\Domain\Money\RoundingRule}
 * finishes the last two lines. §5 puts all of this in the backend and lets the
 * UI "preview but never be the source of truth", so none of it is lifted from
 * the request — {@see QuotationDraft::withComputed()} is where it joins the draft.
 *
 * ── §5.6's two outcomes ────────────────────────────────────────────────────
 *
 * A price missing at the supplier **blocks** ({@see QuotationNotPriceable}); a
 * requested quantity above what the offer has left — its available balance,
 * `D-81` — **warns and does not** ({@see PricedQuotation::$quantityWarnings}).
 *
 * ── `D-63`: tax is derived, not trusted ────────────────────────────────────
 *
 * A tax-exempt customer renders **no tax line at all** — `tax_percent` is forced
 * to null regardless of what the request sent. A taxed customer keeps the
 * request's per-quotation rate (which may itself be null). The preparer's
 * override of an exempt customer is an approval-time edit (Module 8).
 */
final readonly class PriceQuotation
{
    public function __construct(
        private SupplierItemPricingInterface $supplierPrices,
        private CurrencyRepositoryInterface $currencies,
        private FxRateRepositoryInterface $fxRates,
        private CustomerTaxStatusInterface $customerTax,
    ) {}

    /**
     * @param  array<string, mixed>  $validated  the Form Request's output
     * @param  string  $customerId  whose tax status decides `D-63`
     *
     * @throws QuotationNotPriceable
     */
    public function price(array $validated, string $customerId, DateTimeImmutable $at): PricedQuotation
    {
        $currency = $this->quotationCurrency($validated['currency'] ?? null);
        $defaultMargin = self::decimal($validated['default_margin'] ?? null, 'default_margin');

        /** @var list<PricedLine> $pricedLines */
        $pricedLines = [];
        /** @var list<array<string, mixed>> $itemRows */
        $itemRows = [];
        /** @var list<int> $warnings */
        $warnings = [];

        foreach (self::rows($validated, 'lines') as $index => $line) {
            $itemId = self::string($line['supplier_quotation_item_id'] ?? null, 'supplier_quotation_item_id');
            $quantity = self::decimal($line['quantity'] ?? null, 'quantity');
            $lineMargin = array_key_exists('margin_percent', $line) && $line['margin_percent'] !== null
                ? self::decimal($line['margin_percent'], 'margin_percent')
                : null;

            $price = $this->supplierPrices->priceFor($itemId);

            // §5.6: a gone or soft-deleted line, or a price whose currency
            // the offer never recorded, is no usable price — block.
            if ($price === null || $price->currencyId === null) {
                throw QuotationNotPriceable::priceMissing($itemId, $index + 1);
            }

            $supplierCurrency = $this->currencies->findById($price->currencyId);
            if ($supplierCurrency === null) {
                throw QuotationNotPriceable::priceMissing($itemId, $index + 1);
            }

            // `D-09`: the rate is captured at creation. No rate for the pair
            // means the line cannot be converted, so it cannot be priced.
            $rate = $this->fxRates->effectiveRate($supplierCurrency->code(), $currency->code(), $at);
            if ($rate === null) {
                throw QuotationNotPriceable::fxRateMissing($itemId, $index + 1);
            }

            $priced = PricedLine::from(
                Decimal::of($price->unitPrice, 'unit_cost'),
                $rate->rate(),
                $lineMargin,
                $defaultMargin,
                $quantity,
            );
            $pricedLines[] = $priced;

            // §5.6: requested above what the offer has left → warn, never
            // block. `D-81` (F-05 · 1.4): the ceiling is the available balance
            // (`quantity − consumed_quantity`, computed by Module 6), not the
            // recorded offer.
            if (bccomp($quantity, Decimal::of($price->availableQuantity, 'available_quantity'), PricedLine::SCALE) > 0) {
                $warnings[] = $index + 1;
            }

            $itemRows[] = [
                'supplier_quotation_item_id' => $itemId,
                'unit_cost' => $price->unitPrice,
                'unit_cost_currency' => $supplierCurrency->code()->value,
                'unit_cost_fx_rate_at_time' => $rate->rate(),
                'unit_cost_base' => $priced->unitCostBase(),
                'margin_percent' => $lineMargin,
                'unit_price' => $priced->unitPrice(),
                'quantity' => $quantity,
                'line_total' => $priced->lineTotal(),
                'line_cost' => $priced->lineCost(),
            ];
        }

        /** @var list<numeric-string> $additionalAmounts */
        $additionalAmounts = [];
        /** @var list<array<string, mixed>> $additionalRows */
        $additionalRows = [];

        foreach (self::rows($validated, 'additional_items') as $additional) {
            $amount = self::decimal($additional['amount'] ?? null, 'amount');
            $additionalAmounts[] = $amount;
            $additionalRows[] = [
                'description' => self::string($additional['description'] ?? null, 'description'),
                'amount' => $amount,
            ];
        }

        // `D-63`: exempt forces no tax line; else the request's per-quotation rate.
        $taxPercent = $this->customerTax->isExempt($customerId)
            ? null
            : (array_key_exists('tax_percent', $validated) && $validated['tax_percent'] !== null
                ? self::decimal($validated['tax_percent'], 'tax_percent')
                : null);

        $totals = QuotationTotals::from(
            $pricedLines,
            $additionalAmounts,
            self::decimal($validated['discount_percent'] ?? null, 'discount_percent'),
            $taxPercent,
        );

        $rounded = $currency->rounding()->apply($totals->totalBeforeRound());

        return new PricedQuotation(
            computed: [
                'currency_id' => $currency->id(),
                'tax_percent' => $taxPercent,
                'rounding_unit' => $currency->rounding()->unit(),
                'rounding_enabled' => $currency->rounding()->isEnabled(),
                'subtotal' => $totals->subtotal(),
                'additional_total' => $totals->additionalTotal(),
                'discount_amount' => $totals->discountAmount(),
                'tax_base' => $totals->taxBase(),
                'tax_amount' => $totals->taxAmount(),
                'net_amount' => $totals->netAmount(),
                'total_before_round' => $totals->totalBeforeRound(),
                'final_total' => $rounded->finalTotal(),
                'rounding_diff' => $rounded->roundingDiff(),
            ],
            items: $itemRows,
            additionalItems: $additionalRows,
            quantityWarnings: $warnings,
        );
    }

    /**
     * The quotation's currency, by the code the request carries.
     *
     * `find()` and not `findById()`: §6 names the currency by code and the
     * column it is stored under is resolved here (`D-09` gives the quotation one
     * currency). An unoffered code is a precondition the Form Request (Point 3.4)
     * refuses before this runs; the guard is the belt to that.
     */
    private function quotationCurrency(mixed $code): Currency
    {
        $currencyCode = is_string($code) ? CurrencyCode::tryFrom($code) : null;
        $currency = $currencyCode === null ? null : $this->currencies->find($currencyCode);

        if ($currency === null || $currency->id() === null) {
            throw new InvalidArgumentException('The quotation currency must be an offered currency code.');
        }

        return $currency;
    }

    /**
     * The submitted rows under $key, re-keyed to `string` keys — the narrowing
     * `SupplierQuotationDraft::lines()` uses, because a key off a `mixed` payload
     * is `array-key` and PHPStan level 10 reads that as an error, not a cast to
     * reach for.
     *
     * @param  array<string, mixed>  $validated
     * @return list<array<string, mixed>>
     */
    private static function rows(array $validated, string $key): array
    {
        $submitted = $validated[$key] ?? [];

        if (! is_array($submitted)) {
            return [];
        }

        $rows = [];

        foreach ($submitted as $row) {
            if (! is_array($row)) {
                continue;
            }

            $keyed = [];

            foreach ($row as $k => $value) {
                $keyed[(string) $k] = $value;
            }

            $rows[] = $keyed;
        }

        return $rows;
    }

    /**
     * A `mixed` payload value as a checked decimal string. `DB-07` forbids float
     * near a price, so a float is refused outright rather than stringified; an
     * integer quantity is exact and kept. `Decimal::of()` is the narrowing
     * {@see PricedLine} names as belonging at the Application boundary.
     *
     * @return numeric-string
     */
    private static function decimal(mixed $value, string $what): string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException("DB-07: {$what} must be a decimal string.");
        }

        return Decimal::of($value, $what);
    }

    private static function string(mixed $value, string $what): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException("{$what} must be a string.");
        }

        return $value;
    }
}
