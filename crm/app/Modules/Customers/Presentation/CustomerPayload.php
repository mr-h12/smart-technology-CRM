<?php

declare(strict_types=1);

namespace App\Modules\Customers\Presentation;

use App\Modules\Customers\Domain\Listing\CustomerPage;
use App\Modules\Customers\Domain\Listing\CustomerSummary;

/**
 * How a customer appears on the wire — §4.2's fields, in `OpenAPI §8`'s shapes.
 *
 * `sales_owner_id` is an explicit id per §8.2, "represent direct relationships
 * with explicit ID fields". The owner's *name* is deliberately not expanded
 * here: it belongs to Identity, and inlining it would mean this module reaching
 * for another module's rows on every list row.
 *
 * `customer_status` is sent as its stored code, not a translated label. §4.5
 * derives the value and the SPA translates it — a server-side label would make
 * the field unusable as a filter value, which is exactly what
 * `filter[customer_status]` needs it to be.
 *
 * Timestamps in ISO-8601 UTC (`DB-08`).
 */
final class CustomerPayload
{
    /** @return array<string, mixed> */
    public static function of(CustomerSummary $customer): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'customer_status' => $customer->customerStatus,
            'sector' => $customer->sector,
            'region' => $customer->region,
            'contact_person' => $customer->contactPerson,
            'phone' => $customer->phone,
            'phone2' => $customer->phone2,
            'whatsapp' => $customer->whatsapp,
            'email' => $customer->email,
            'sales_owner_id' => $customer->salesOwnerId,
            'start_date' => $customer->startDate?->format('Y-m-d'),
            'notes' => $customer->notes,
            'is_archived' => $customer->isArchived,
            'is_incomplete' => $customer->isIncomplete,
            'created_at' => $customer->createdAt->format(DATE_ATOM),
            'updated_at' => $customer->updatedAt->format(DATE_ATOM),
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function many(CustomerPage $page): array
    {
        return array_map(static fn (CustomerSummary $customer): array => self::of($customer), $page->items);
    }

    /**
     * `D-35`'s warning — §10.2's "yellow warning listing the similar customers".
     *
     * Id and name only, and not the whole record. The screen needs enough to
     * name the customer and open it; anything more is a second detail payload
     * nobody asked for, delivered to somebody who was only saving a form.
     *
     * @param  list<CustomerSummary>  $similar
     * @return list<array{id: string, name: string}>
     */
    public static function similar(array $similar): array
    {
        return array_map(
            static fn (CustomerSummary $customer): array => ['id' => $customer->id, 'name' => $customer->name],
            $similar,
        );
    }

    /** @return array{page: int, per_page: int, total: int, total_pages: int, has_next_page: bool, has_previous_page: bool} */
    public static function pagination(CustomerPage $page): array
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
