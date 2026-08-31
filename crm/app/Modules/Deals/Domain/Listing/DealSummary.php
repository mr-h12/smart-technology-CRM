<?php

declare(strict_types=1);

namespace App\Modules\Deals\Domain\Listing;

use DateTimeImmutable;

/**
 * A deal as a reader sees it — §4.3's fields, and nothing beyond them.
 *
 * `status` and `approvalStatus` are carried, never set from here: §4.4's
 * transitions and Flow 3's approval step are a later point's business rules, not
 * this read model's.
 */
final readonly class DealSummary
{
    public function __construct(
        public string $id,
        public string $code,
        public string $customerId,
        public ?string $title,
        public ?string $source,
        public ?string $serviceType,
        public string $status,
        public ?string $ownerId,
        public ?string $approvalStatus,
        public ?string $rejectionReason,
        public ?string $lostReason,
        public DateTimeImmutable $lastActivityAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
