<?php

declare(strict_types=1);

namespace App\Modules\SupplierQuotations\Application\Writing;

use App\Modules\Admin\Domain\Money\Decimal;
use App\Modules\SupplierQuotations\Domain\Writing\SupplierQuotationDraft;
use App\Support\Database\Precision;
use Illuminate\Validation\ValidationException;

/**
 * `D-118` (amending `D-105`): the rates a tax-inclusive offer is stripped by —
 * typed on the offer, never a system setting. The boundary checks each rate
 * (`TaxRates`); only here are the flag and the stored rates both known, so the
 * pairing is checked here, on `PricedLinesNeedCurrency`'s shape.
 */
final readonly class IncludedTaxRate
{
    /** The amounts a flagged offer divides — `DB-07`: decimal text or an integer, never a float. */
    private const AMOUNT = '/^\d+(\.\d+)?$/D';

    /**
     * The rates this save leaves on the offer: the ones typed, else — on an
     * edit that typed none — the ones already captured. None when unflagged.
     *
     * @param  array<string, mixed>  $validated
     * @param  list<string>  $stored
     * @return list<numeric-string>
     */
    public static function rates(bool $flagged, array $validated, array $stored): array
    {
        $typed = $validated['included_tax_percents'] ?? null;

        if (! $flagged) {
            if (is_array($typed) && $typed !== []) {
                throw ValidationException::withMessages(['included_tax_percents' => __('supplier_quotations.errors.rates_need_flag')]);
            }

            return [];
        }

        $rates = array_map(
            static fn (mixed $rate): string => bcadd(Decimal::of(is_string($rate) ? $rate : '', 'included_tax_percents'), '0', Precision::PERCENT_SCALE),
            array_values(is_array($typed) ? $typed : $stored),
        );

        if ($rates === []) {
            throw ValidationException::withMessages(['included_tax_percents' => __('supplier_quotations.errors.no_tax_rate')]);
        }

        return $rates;
    }

    /**
     * Before a flagged draft is divided: `numeric` at the boundary lets a JSON
     * float or `1e3` through, which is harmless stored as recorded and a 500
     * once divided. Refused per field instead.
     */
    public static function checkEntered(SupplierQuotationDraft $draft): void
    {
        $refused = [];

        if (! self::amount($draft->attributes['total_price'] ?? null)) {
            $refused['total_price'] = __('supplier_quotations.errors.not_decimal_text');
        }

        foreach ($draft->items ?? [] as $index => $line) {
            if (! self::amount($line['unit_price'] ?? null)) {
                $refused["items.{$index}.unit_price"] = __('supplier_quotations.errors.not_decimal_text');
            }
        }

        if ($refused !== []) {
            throw ValidationException::withMessages($refused);
        }
    }

    private static function amount(mixed $value): bool
    {
        return $value === null || is_int($value) || (is_string($value) && preg_match(self::AMOUNT, $value) === 1);
    }
}
