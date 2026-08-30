<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Listing;

use DateTimeImmutable;

/**
 * A catalog item as a reader sees it — §7.3's fields, and no price.
 *
 * §7.3 opens "Descriptive data only — **no prices**" and `D-21` puts every
 * price on the supplier quotation. Point 1.2 kept the table free of any numeric
 * column; this class is where that stays true as Module 6 adds joins, because a
 * field that does not exist here cannot be serialised by accident.
 *
 * Both tabs' columns sit on one object because they sit in one table: a product
 * carries a null `serviceType`, a service a null `unit`. The conditional rules
 * — a product needs a unit, a service needs a type — are Point 3.2's Form
 * Request, where an error message can reach a person.
 */
final readonly class CatalogItemSummary
{
    public function __construct(
        public string $id,
        public string $kind,
        public ?string $name,
        public ?string $productCode,
        public ?string $category,
        public ?string $unit,
        public ?string $serviceType,
        public ?string $company,
        public ?string $description,
        public ?string $notes,
        public bool $isActive,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
