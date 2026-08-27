<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain\Contracts;

use App\Modules\Admin\Domain\Money\Currency;

/**
 * The currencies the system offers, read from the database.
 *
 * **No implementation may answer from `Currencies`.** That class is the
 * canonical source the seeder loads once; §5.3 makes the rounding unit editable
 * under System Settings and `AP-08` puts configuration in the database, so a
 * repository that re-derived its answer from code would report the value an
 * administrator changed away from — and the change would appear to have been
 * lost. The same argument `PermissionRepositoryInterface` makes for §3.12
 * rule 5.
 *
 * Archived rows are not currencies the system offers (`DB-01`, `D-34`): a
 * currency is deactivated rather than deleted, and deactivated means absent
 * from every list that offers a choice.
 */
interface CurrencyRepositoryInterface
{
    /** @return list<Currency> */
    public function all(): array;

    /** The one currency `DB-06`'s `base_amount` is an amount of, or null before seeding. */
    public function base(): ?Currency;
}
