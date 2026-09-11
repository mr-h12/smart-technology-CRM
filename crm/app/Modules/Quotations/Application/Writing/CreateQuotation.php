<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Application\Writing;

use App\Modules\Admin\Domain\Contracts\CurrencyRepositoryInterface;
use App\Modules\Admin\Domain\Contracts\FxRateRepositoryInterface;
use App\Modules\Admin\Domain\Money\Currency;
use App\Modules\Admin\Domain\Money\CurrencyCode;
use App\Modules\Admin\Domain\Money\Decimal;
use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Customers\Domain\Contracts\CustomerTaxStatusInterface;
use App\Modules\Deals\Domain\Contracts\DealFactsInterface;
use App\Modules\Identity\Domain\Rbac\AuthorizationRefused;
use App\Modules\Quotations\Domain\Access\QuotationRowScope;
use App\Modules\Quotations\Domain\Contracts\QuotationDirectoryInterface;
use App\Modules\Quotations\Domain\Pricing\PricedLine;
use App\Modules\Quotations\Domain\Pricing\QuotationNotPriceable;
use App\Modules\Quotations\Domain\Pricing\QuotationTotals;
use App\Modules\Quotations\Domain\Writing\QuotationDraft;
use App\Modules\SupplierQuotations\Domain\Contracts\SupplierItemPricingInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * §6's create, and §5's pricing made real — `CreateSupplierQuotation`'s shape
 * (Module 6 Point 2.1), with the one difference §5 forces: every line price is
 * the **backend's**, read from the supplier and converted, never the caller's.
 *
 * ── `DB-11`: the whole quotation, or none of it ────────────────────────────
 *
 * The header, the `document_sequences` allocation its code comes from, both
 * child tables and the `QUOTATION_CREATED` audit row commit together. A line
 * that cannot be priced does not leave a half-written quotation: the block is a
 * throw, before the first write, and the transaction rolls back.
 *
 * ── What it computes, and why the caller could not ─────────────────────────
 *
 * Per line (§5.1): the supplier's price and the offer's currency come from
 * {@see SupplierItemPricingInterface}; the FX rate is captured at creation
 * (`D-09`) from {@see FxRateRepositoryInterface::effectiveRate()}; and
 * {@see PricedLine} applies the margin. Per quotation (§5.2): {@see QuotationTotals}
 * totals to the tax base and {@see \App\Modules\Admin\Domain\Money\RoundingRule}
 * finishes the last two lines. §5 puts all of this in the backend and lets the
 * UI "preview but never be the source of truth", so none of it is lifted from
 * the request — {@see QuotationDraft::withComputed()} is where it joins the draft.
 *
 * ── §5.6's two outcomes ────────────────────────────────────────────────────
 *
 * A price missing at the supplier **blocks** ({@see QuotationNotPriceable}); a
 * requested quantity above the supplier's recorded amount **warns and does not**
 * ({@see QuotationCreated::$quantityWarnings}).
 *
 * ── §3.5's create scope is a constraint on the deal (Point 3.4) ────────────
 *
 * `create` is `All · Team · Own` and a quotation has no owner column, so —
 * on `SaveDeal`'s reading that "a scope on `create` is not a `WHERE`" — the
 * only thing it can constrain is **which deal** is quoted. The owner ruled
 * (2026-09-11) that a quotation's "own" is its deal's `owner_id`, not the
 * tracking field `created_by`: an `own` caller quotes their own deals, `all`
 * quotes anybody's, `team` permits nothing until a team entity exists (the
 * gap {@see QuotationRowScope} records) and fails closed. The deal's owner and
 * customer come from {@see DealFactsInterface}; the same ruling holds the
 * request's `customer_id` to the deal's, since §6.2 carries both and a
 * quotation addressed to somebody other than its deal's customer is a defect.
 * Both checks run inside the transaction, before any line is priced.
 *
 * ── `D-63`: tax is derived, not trusted ────────────────────────────────────
 *
 * A tax-exempt customer renders **no tax line at all** — `tax_percent` is forced
 * to null regardless of what the request sent. A taxed customer keeps the
 * request's per-quotation rate (which may itself be null). The preparer's
 * override of an exempt customer is an approval-time edit (Module 8), not a
 * create.
 */
final readonly class CreateQuotation
{
    public function __construct(
        private QuotationDirectoryInterface $quotations,
        private SupplierItemPricingInterface $supplierPrices,
        private CurrencyRepositoryInterface $currencies,
        private FxRateRepositoryInterface $fxRates,
        private CustomerTaxStatusInterface $customerTax,
        private DealFactsInterface $deals,
        private AuditRecorderInterface $audit,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @param  array<string, mixed>  $validated  already validated at the boundary
     * @param  list<string>  $heldScopes  §3.2 codes, as the authorisation decision reports them
     */
    public function create(array $validated, array $heldScopes, string $actorId): QuotationCreated
    {
        $scope = QuotationRowScope::resolve($heldScopes, $actorId);

        return $this->connection->transaction(function () use ($validated, $scope, $actorId): QuotationCreated {
            $this->guardDeal($validated, $scope);

            $currency = $this->quotationCurrency($validated['currency'] ?? null);
            $at = now()->toDateTimeImmutable();
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

                // §5.6: requested above the recorded amount → warn, never block.
                if (bccomp($quantity, Decimal::of($price->recordedQuantity, 'recorded_quantity'), PricedLine::SCALE) > 0) {
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
            $taxPercent = $this->customerTax->isExempt(self::string($validated['customer_id'] ?? null, 'customer_id'))
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

            $draft = QuotationDraft::forCreate($validated)
                ->withComputed([
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
                ])
                ->withLines($itemRows, $additionalRows);

            $quotation = $this->quotations->create($draft, $actorId);

            // No old values — absent on a create. The lines go beside the header
            // for `CreateSupplierQuotation`'s reason: "what was created?" answered
            // by the header alone is half the answer.
            $this->audit->record(
                AuditEvent::of('QUOTATION_CREATED'),
                'quotation',
                $quotation->id,
                null,
                [...$draft->attributes, 'items' => $itemRows, 'additional_items' => $additionalRows],
            );

            return new QuotationCreated($quotation, $warnings);
        });
    }

    /**
     * §3.5's create scope, applied to the one thing a create can be scoped by.
     *
     * Order matters and is the fail-closed order: a scope that permits no deal
     * refuses before the deal is even looked up (`team`, and any code the
     * resolver does not know); an unknown deal is the request's fault
     * (`deal_id`), not a permission; an owned deal outside the caller's reach
     * is a refusal that names nothing about the deal (§5.1's "do not reveal
     * which"); and only then is the addressee checked against the deal's.
     *
     * @param  array<string, mixed>  $validated
     */
    private function guardDeal(array $validated, QuotationRowScope $scope): void
    {
        if ($scope->permitsNothing()) {
            throw AuthorizationRefused::of('quotation', 'create');
        }

        $facts = $this->deals->factsOf(self::string($validated['deal_id'] ?? null, 'deal_id'));

        if ($facts === null) {
            throw ValidationException::withMessages([
                'deal_id' => [(string) __('quotations.validation.unknown_deal')],
            ]);
        }

        // `own`: the deal's owner must be one the caller may reach.
        if (! $scope->reaches($facts->ownerId)) {
            throw AuthorizationRefused::of('quotation', 'create');
        }

        if ($facts->customerId !== self::string($validated['customer_id'] ?? null, 'customer_id')) {
            throw ValidationException::withMessages([
                'customer_id' => [(string) __('quotations.validation.customer_not_the_deals')],
            ]);
        }
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
