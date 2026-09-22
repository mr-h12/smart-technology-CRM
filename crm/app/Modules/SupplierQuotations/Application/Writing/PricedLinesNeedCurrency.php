<?php

declare(strict_types=1);

namespace App\Modules\SupplierQuotations\Application\Writing;

use Illuminate\Validation\ValidationException;

/**
 * §5.6: "Every amount stores: amount · currency · …". A line's `unit_price` is
 * an amount and its currency is the header's, so an offer with lines and no
 * `currency_id` has prices in no currency at all — the dead end `D-80` names:
 * Module 7's `supplier_price_missing` then refuses every quotation built on it.
 *
 * Not a Form Request rule, because the fact is about the offer that **results**
 * — a `PATCH` naming only `items` is fine while the stored currency stands, and
 * a `PATCH` blanking the pair is not while the stored lines stand. So both
 * write use cases ask here, with the merged state, before they write.
 *
 * `ValidationException::withMessages()` on `RecordFxRate`'s precedent: it lands
 * as the same 422 the boundary would give, on the field the form already
 * renders. The DB CHECK `(total_price IS NULL) = (currency_id IS NULL)` makes
 * the total mandatory alongside — `required_with:currency_id` says so next.
 */
final class PricedLinesNeedCurrency
{
    /**
     * @param  mixed  $currencyId  the validated header value — a uuid string, or null/absent
     * @param  list<mixed>  $lines  the lines the offer will hold
     *
     * @throws ValidationException
     */
    public static function check(mixed $currencyId, array $lines): void
    {
        if (! is_string($currencyId) && $lines !== []) {
            throw ValidationException::withMessages([
                'currency_id' => __('supplier_quotations.errors.lines_need_currency'),
            ]);
        }
    }
}
