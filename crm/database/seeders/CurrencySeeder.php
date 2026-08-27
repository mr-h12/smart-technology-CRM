<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Admin\Domain\Money\Currencies;
use App\Modules\Admin\Infrastructure\Eloquent\Currency as CurrencyRow;
use App\Support\Seeding\GuardedSeeder;
use Illuminate\Support\Facades\DB;

/**
 * §5.3's three currencies and their rounding units, as rows.
 *
 * **This is not test data.** `DEV-08` lists currencies beside roles and
 * permissions, and `AP-08` puts configuration in the database: a production
 * system with no currencies cannot price anything, so `seedsTestData()` is
 * false and this runs there too.
 *
 * **It creates and never overwrites.** `IdempotentSeeder` defines repeatable as
 * not duplicating a row, not bumping a counter, and **not overwriting an edit
 * somebody made deliberately** — and §5.3 makes the unit editable under System
 * Settings, with `D-65` adding the on/off switch beside it. `updateOrCreate`,
 * which `RolePermissionSeeder` uses correctly for a matrix the document owns,
 * would here undo an administrator's documented action on the next deployment.
 * So the write is create-if-absent, and a row that exists is left exactly as it
 * is.
 *
 * **No exchange rate is seeded.** `Currencies::seededRates()` offers exactly one
 * and calls it what it is — a tautology, EGP against itself — and `fx_rates`
 * refuses it: Point 1.2's `fx_rates_distinct_currencies` CHECK exists because a
 * currency priced against itself is not a rate. The identity belongs in the
 * conversion code, not in a row, and every real rate is entered by a human
 * under §3.11's `FX rates` permission. Recorded here rather than worked around.
 */
final class CurrencySeeder extends GuardedSeeder
{
    public function seedsTestData(): bool
    {
        // DEV-08 and AP-08: production needs these rows on day one.
        return false;
    }

    protected function seed(): void
    {
        // DB-11: three writes that only make sense together — a half-seeded
        // currency table is one where a quotation can be priced in EGP but not
        // converted, with nothing to say why.
        DB::transaction(function (): void {
            foreach (Currencies::all() as $currency) {
                CurrencyRow::query()->firstOrCreate(
                    ['code' => $currency->code()->value],
                    [
                        'rounding_unit' => $currency->rounding()->unit(),
                        'rounding_enabled' => $currency->rounding()->isEnabled(),
                        'is_base' => $currency->isBase(),
                    ],
                );
            }
        });
    }
}
