<?php

declare(strict_types=1);

namespace App\Modules\Customers\Domain\Listing;

use DateTimeImmutable;

/**
 * A customer as a reader sees it — §4.2's fields, and nothing a supplier owns.
 *
 * §3.12 rule 2 keeps supplier cost, supplier names and margin out of what a
 * customer-facing document carries. None of them is a column on `customers` in
 * the first place, and this class is where that stays true as later modules add
 * joins: a field that does not exist here cannot be serialised by accident.
 *
 * `customerStatus` is carried, never set. §4.5 derives it from five ordered
 * conditions and `recompute_customer_status` is Module 5's; a read model that
 * offered a setter would be the first place somebody edited it by hand.
 */
final readonly class CustomerSummary
{
    public function __construct(
        public string $id,
        public string $name,
        public string $customerStatus,
        public ?string $sector,
        public ?string $region,
        public ?string $contactPerson,
        public ?string $phone,
        public ?string $phone2,
        public ?string $whatsapp,
        public ?string $email,
        public ?string $salesOwnerId,
        public ?DateTimeImmutable $startDate,
        public ?string $notes,
        public bool $isArchived,
        public bool $isIncomplete,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
