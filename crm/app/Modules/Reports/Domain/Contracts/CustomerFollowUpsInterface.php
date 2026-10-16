<?php

declare(strict_types=1);

namespace App\Modules\Reports\Domain\Contracts;

/**
 * `D-133`: what Customers reads of the reports — a customer's follow-ups from each
 * report's latest submitted version, never a draft's live rows.
 */
interface CustomerFollowUpsInterface
{
    /**
     * Newest first. `followed_up_at` is the row's first save (UTC, ATOM), or its
     * version's submit time for a copy frozen before `D-133`.
     *
     * @return list<array{note: string, author_id: string, followed_up_at: string}>
     */
    public function ofCustomer(string $customerId): array;
}
