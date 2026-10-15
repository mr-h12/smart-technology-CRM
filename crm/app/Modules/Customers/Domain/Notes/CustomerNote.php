<?php

declare(strict_types=1);

namespace App\Modules\Customers\Domain\Notes;

use DateTimeImmutable;

/** `D-122`: one entry of a customer's communication history. */
final readonly class CustomerNote
{
    public function __construct(
        public string $id,
        public string $customerId,
        public string $body,
        /** A calendar date (`Y-m-d`), a label only: it sorts the list and triggers nothing (`D-15`). */
        public ?string $noteDate,
        public ?string $createdBy,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
