<?php

declare(strict_types=1);

namespace App\Modules\Deals\Presentation;

use App\Modules\Deals\Domain\Listing\DealPage;
use App\Modules\Deals\Domain\Listing\DealSummary;

/**
 * How a deal appears on the wire — §4.3's fields, in `OpenAPI §8`'s shapes.
 *
 * `customer_id` and `owner_id` are explicit ids per §8.2, "represent direct
 * relationships with explicit ID fields". The customer's and owner's *names*
 * are deliberately not expanded here: they belong to other modules, and
 * inlining them would mean this module reaching for another module's rows on
 * every list row.
 *
 * `status`, `source`, `service_type` and `approval_status` are sent as their
 * stored codes, not translated labels — the SPA translates them, and a
 * server-side label would make the field unusable as a filter value.
 *
 * Timestamps in ISO-8601 UTC (`DB-08`).
 */
final class DealPayload
{
    /** @return array<string, mixed> */
    public static function of(DealSummary $deal): array
    {
        return [
            'id' => $deal->id,
            'code' => $deal->code,
            'customer_id' => $deal->customerId,
            'title' => $deal->title,
            'source' => $deal->source,
            'service_type' => $deal->serviceType,
            'status' => $deal->status,
            'owner_id' => $deal->ownerId,
            'approval_status' => $deal->approvalStatus,
            'rejection_reason' => $deal->rejectionReason,
            'last_activity_at' => $deal->lastActivityAt->format(DATE_ATOM),
            'created_at' => $deal->createdAt->format(DATE_ATOM),
            'updated_at' => $deal->updatedAt->format(DATE_ATOM),
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function many(DealPage $page): array
    {
        return array_map(static fn (DealSummary $deal): array => self::of($deal), $page->items);
    }

    /** @return array{page: int, per_page: int, total: int, total_pages: int, has_next_page: bool, has_previous_page: bool} */
    public static function pagination(DealPage $page): array
    {
        return [
            'page' => $page->page,
            'per_page' => $page->perPage,
            'total' => $page->total,
            'total_pages' => $page->totalPages(),
            'has_next_page' => $page->hasNextPage(),
            'has_previous_page' => $page->hasPreviousPage(),
        ];
    }
}
