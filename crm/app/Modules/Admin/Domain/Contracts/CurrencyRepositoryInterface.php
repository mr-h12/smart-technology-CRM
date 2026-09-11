<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain\Contracts;

use App\Modules\Admin\Domain\Money\Currency;
use App\Modules\Admin\Domain\Money\CurrencyCode;
use App\Modules\Admin\Domain\Money\RoundingRule;

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

    /** One live currency by its code, or null — archived rows are not offered (`D-34`). */
    public function find(CurrencyCode $code): ?Currency;

    /**
     * One live currency by its `currencies` row id, or null.
     *
     * The inverse of {@see find()}, for a caller that holds the id rather than
     * the code. Module 7 needs both directions: a customer quotation's request
     * names its own currency by code, but each line's supplier currency arrives
     * as the id `SupplierItemPricingInterface` returns, and `effectiveRate()`
     * and `quotation_items.unit_cost_currency` both need it as a `CurrencyCode`.
     * Archived rows are not offered, for {@see find()}'s reason (`D-34`).
     */
    public function findById(string $id): ?Currency;

    /**
     * Replace one currency's rounding rule, returning the row and what it was.
     *
     * The identifier comes back because `audit_log.entity_id` is a `UUID`
     * column, and the previous rule because `AUD-01` wants the old and the new
     * in one entry — a second read is a second chance for them to disagree.
     *
     * @return array{id: string, previous: RoundingRule}
     */
    public function replaceRounding(CurrencyCode $code, RoundingRule $rounding): array;
}
