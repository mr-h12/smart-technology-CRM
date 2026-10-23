<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Application\Writing;

use App\Modules\Admin\Domain\Contracts\CurrencyRepositoryInterface;
use App\Modules\Admin\Domain\Money\Currency;
use App\Modules\Admin\Domain\Money\CurrencyCode;
use App\Modules\Admin\Domain\Money\Decimal;
use App\Modules\Quotations\Domain\Pricing\PricedLine;
use App\Modules\Quotations\Domain\Pricing\PricingMode;
use App\Modules\Quotations\Domain\Pricing\QuotationTotals;
use App\Support\Database\Precision;
use InvalidArgumentException;

/**
 * §5 applied to one submitted quotation body — the block `CreateQuotation`
 * carried inline, lifted out because the edit re-prices exactly the same way.
 * One implementation of the pricing rules, called from two use cases; a second
 * copy is the defect the waste audit names first.
 *
 * ── What it computes, and why the caller could not ─────────────────────────
 *
 * Per line (`D-156`): the line is priced from what was typed — the entered
 * price, its own tax and its own margin — by {@see PricedLine::fromEntered()}.
 * The line is typed in the quotation's currency (`D-164`): no supplier price,
 * no FX rate, no quotation margin. Per quotation (§5.2): {@see QuotationTotals}
 * totals to the tax base, taxing each line at its own rate (`D-157`), and
 * {@see \App\Modules\Admin\Domain\Money\RoundingRule} finishes the last two
 * lines. §5 puts all of this in the backend and lets the UI "preview but never
 * be the source of truth", so none of it is lifted from the request —
 * {@see \App\Modules\Quotations\Domain\Writing\QuotationDraft::withComputed()}
 * is where it joins the draft.
 *
 * ── `D-157`: the toggle is the preparer's ──────────────────────────────────
 *
 * `pricing_mode` is `tax_included` (each line carries its rate, taken out of
 * the typed price) or `tax_exempt` (the typed price is used as is and the
 * quotation has no tax line at all, `D-63`). It is not forced from the
 * customer's exemption. A new save writes no quotation margin and no quotation
 * tax rate (`D-165`).
 */
final readonly class PriceQuotation
{
    public function __construct(
        private CurrencyRepositoryInterface $currencies,
    ) {}

    /**
     * @param  array<string, mixed>  $validated  the Form Request's output
     */
    public function price(array $validated): PricedQuotation
    {
        $currency = $this->quotationCurrency($validated['currency'] ?? null);
        $mode = PricingMode::from(self::string($validated['pricing_mode'] ?? null, 'pricing_mode'));

        /** @var list<PricedLine> $pricedLines */
        $pricedLines = [];
        /** @var list<array<string, mixed>> $itemRows */
        $itemRows = [];

        foreach (self::rows($validated, 'lines') as $line) {
            $quantity = self::decimal($line['quantity'] ?? null, 'quantity');
            $entered = self::decimal($line['entered_unit_price'] ?? null, 'entered_unit_price');
            $margin = bcadd(self::decimal($line['margin_percent'] ?? null, 'margin_percent'), '0', Precision::PERCENT_SCALE);
            $tax = $mode === PricingMode::TaxIncluded
                ? bcadd(self::decimal($line['tax_percent'] ?? null, 'tax_percent'), '0', Precision::PERCENT_SCALE)
                : null;

            $priced = PricedLine::fromEntered($entered, $tax, $margin, $quantity);
            $pricedLines[] = $priced;

            $itemRows[] = [
                // `D-155`: the line owns its facts; the offer line is provenance only.
                'supplier_quotation_item_id' => null,
                'supplier_id' => self::string($line['supplier_id'] ?? null, 'supplier_id'),
                'catalog_item_id' => self::string($line['catalog_item_id'] ?? null, 'catalog_item_id'),
                'unit_code' => self::string($line['unit_code'] ?? null, 'unit_code'),
                'entered_unit_price' => bcadd($entered, '0', PricedLine::SCALE),
                'tax_percent' => $tax,
                // The stored unit cost is the net, in the quotation's own currency at rate 1 (`D-164`).
                'unit_cost' => $priced->unitCostBase(),
                'unit_cost_currency' => $currency->code()->value,
                'unit_cost_fx_rate_at_time' => '1',
                'unit_cost_base' => $priced->unitCostBase(),
                'margin_percent' => $margin,
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

        $totals = QuotationTotals::from(
            $pricedLines,
            $additionalAmounts,
            self::decimal($validated['discount_percent'] ?? null, 'discount_percent'),
        );

        $rounded = $currency->rounding()->apply($totals->totalBeforeRound());

        return new PricedQuotation(
            computed: [
                'currency_id' => $currency->id(),
                'pricing_mode' => $mode->value,
                // `D-165`: a new mode carries no quotation margin and no quotation tax rate.
                'default_margin' => null,
                'tax_percent' => null,
                'tax_percents' => [],
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
