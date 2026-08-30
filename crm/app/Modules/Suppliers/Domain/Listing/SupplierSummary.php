<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\Domain\Listing;

use DateTimeImmutable;

/**
 * A supplier as a reader sees it — §7.1's fields, and no price.
 *
 * `D-21` puts every price on the supplier quotation, so there is no amount to
 * carry here and this class is where that stays true as Module 6 adds joins: a
 * field that does not exist here cannot be serialised by accident.
 *
 * `linkedQuotations` is absent for the same reason it is not a column — §7.1
 * marks it "Automatic" and Module 6 derives it from `supplier_quotations`.
 */
final readonly class SupplierSummary
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $type,
        public string $colorRating,
        public ?string $phone,
        public ?string $contactPerson,
        public bool $hasOpenAccount,
        public bool $isActive,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
