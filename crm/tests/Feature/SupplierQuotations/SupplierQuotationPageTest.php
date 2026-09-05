<?php

declare(strict_types=1);

namespace Tests\Feature\SupplierQuotations;

use App\Modules\SupplierQuotations\Domain\Listing\SupplierQuotationPage;
use Tests\TestCase;

/**
 * Module 6, Point 4.1 — the six numbers `OpenAPI §4.2` puts in
 * `meta.pagination`, and the arithmetic behind three of them.
 *
 * `SupplierPage` (Module 4) says the arithmetic lives in the page rather than
 * in the serialiser because "arithmetic in a serialiser is arithmetic no unit
 * test reaches" — and then no test reached it there either: nothing in the
 * suite names `totalPages()` or `hasNextPage()`, in any of the four modules
 * that copied the class. This file is that test for Module 6's copy; the other
 * four are on the debt register.
 */
final class SupplierQuotationPageTest extends TestCase
{
    /** An empty result is one empty page, not zero — `page=1` of an empty list is a valid request. */
    public function test_that_an_empty_list_is_still_one_page(): void
    {
        $page = new SupplierQuotationPage([], 0, 1, 25);

        self::assertSame(1, $page->totalPages());
        self::assertFalse($page->hasNextPage());
        self::assertFalse($page->hasPreviousPage());
    }

    /** A remainder is a page: 26 offers at 25 a page is two, not one. */
    public function test_that_a_partial_page_counts(): void
    {
        $page = new SupplierQuotationPage([], 26, 1, 25);

        self::assertSame(2, $page->totalPages());
        self::assertTrue($page->hasNextPage());
        self::assertFalse($page->hasPreviousPage());
    }

    public function test_that_the_last_page_has_a_previous_and_no_next(): void
    {
        $page = new SupplierQuotationPage([], 26, 2, 25);

        self::assertSame(2, $page->totalPages());
        self::assertFalse($page->hasNextPage());
        self::assertTrue($page->hasPreviousPage());
    }
}
