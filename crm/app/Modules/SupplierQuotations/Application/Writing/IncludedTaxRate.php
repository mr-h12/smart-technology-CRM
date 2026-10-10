<?php

declare(strict_types=1);

namespace App\Modules\SupplierQuotations\Application\Writing;

use App\Modules\Admin\Domain\Contracts\SettingsRepositoryInterface;
use App\Modules\Admin\Domain\Settings\SystemSetting;
use App\Modules\SupplierQuotations\Domain\Writing\SupplierQuotationDraft;
use Illuminate\Validation\ValidationException;

/**
 * `D-105`: the rate a tax-inclusive offer is stripped at — the system setting
 * `defaults.tax_percent` (`AP-08`). "Saving with the flag on while the setting
 * is empty is refused with a validation error", on `prices_include_tax`,
 * `PricedLinesNeedCurrency`'s shape.
 */
final readonly class IncludedTaxRate
{
    /** A percent that fits `included_tax_percent`, NUMERIC(6,3), and divides: no sign, no exponent. */
    private const PERCENT = '/^\d{1,3}(\.\d{1,3})?$/D';

    /** The amounts a flagged offer divides — `DB-07`: decimal text or an integer, never a float. */
    private const AMOUNT = '/^\d+(\.\d+)?$/D';

    public function __construct(private SettingsRepositoryInterface $settings) {}

    /** @return numeric-string */
    public function current(): string
    {
        $rate = $this->settings->all()[SystemSetting::DefaultTaxPercent->value] ?? null;

        // A stored value that is no percent is treated as no setting at all:
        // `-100` would divide by zero, and `1e2` is not decimal text.
        if (! is_string($rate) || preg_match(self::PERCENT, $rate) !== 1 || ! is_numeric($rate)) {
            throw ValidationException::withMessages([
                'prices_include_tax' => __('supplier_quotations.errors.no_tax_rate'),
            ]);
        }

        return $rate;
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
