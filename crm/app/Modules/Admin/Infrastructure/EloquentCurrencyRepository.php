<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure;

use App\Modules\Admin\Domain\Contracts\CurrencyRepositoryInterface;
use App\Modules\Admin\Domain\Money\Currency;
use App\Modules\Admin\Domain\Money\CurrencyCode;
use App\Modules\Admin\Domain\Money\RoundingRule;
use App\Modules\Admin\Infrastructure\Eloquent\Currency as CurrencyRow;

/**
 * `currencies`, mapped back into the domain objects the rest of the system
 * already speaks.
 *
 * The mapping is the whole class. `RoundingRule::to()` and `::disabled()` carry
 * the `D-65` distinction, and `Decimal::positive()` inside them re-validates
 * what the database's CHECK already guarantees — the belt is cheap and the
 * braces are what make the domain object safe to hand around.
 *
 * A row whose code is not one of `CurrencyCode`'s cases is skipped rather than
 * mapped to a guess. The seeder cannot produce one; a hand-written row can.
 */
final readonly class EloquentCurrencyRepository implements CurrencyRepositoryInterface
{
    public function all(): array
    {
        $currencies = [];

        foreach (CurrencyRow::query()->orderBy('code')->get() as $row) {
            $currency = $this->map($row);

            if ($currency instanceof Currency) {
                $currencies[] = $currency;
            }
        }

        return $currencies;
    }

    public function base(): ?Currency
    {
        $row = CurrencyRow::query()->where('is_base', true)->first();

        return $row instanceof CurrencyRow ? $this->map($row) : null;
    }

    public function find(CurrencyCode $code): ?Currency
    {
        $row = CurrencyRow::query()->where('code', $code->value)->first();

        return $row instanceof CurrencyRow ? $this->map($row) : null;
    }

    public function findById(string $id): ?Currency
    {
        $row = CurrencyRow::query()->whereKey($id)->first();

        return $row instanceof CurrencyRow ? $this->map($row) : null;
    }

    public function replaceRounding(CurrencyCode $code, RoundingRule $rounding): array
    {
        $row = CurrencyRow::query()->where('code', $code->value)->firstOrFail();

        $previous = $row->rounding_enabled
            ? RoundingRule::to($row->rounding_unit)
            : RoundingRule::disabled($row->rounding_unit);

        $row->rounding_unit = $rounding->unit();
        $row->rounding_enabled = $rounding->isEnabled();
        $row->save();

        return ['id' => $row->id, 'previous' => $previous];
    }

    private function map(CurrencyRow $row): ?Currency
    {
        $code = CurrencyCode::tryFrom($row->code);

        if (! $code instanceof CurrencyCode) {
            return null;
        }

        $unit = $row->rounding_unit;

        return new Currency(
            $code,
            $row->rounding_enabled ? RoundingRule::to($unit) : RoundingRule::disabled($unit),
            $row->is_base,
            $row->id,
        );
    }
}
