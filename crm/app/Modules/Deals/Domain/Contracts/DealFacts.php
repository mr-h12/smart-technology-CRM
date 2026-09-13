<?php

declare(strict_types=1);

namespace App\Modules\Deals\Domain\Contracts;

/**
 * The two facts {@see DealFactsInterface} publishes about a deal.
 *
 * `ownerId` is nullable because `deals.owner_id` is (§4.3's "assigned sales
 * employee" may not be assigned yet); `customerId` is not, because every deal
 * is somebody's (`deals.customer_id` NOT NULL).
 */
final readonly class DealFacts
{
    public function __construct(
        public ?string $ownerId,
        public string $customerId,
    ) {}
}
