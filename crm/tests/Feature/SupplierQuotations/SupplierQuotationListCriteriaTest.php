<?php

declare(strict_types=1);

namespace Tests\Feature\SupplierQuotations;

use App\Modules\SupplierQuotations\Domain\Listing\InvalidSupplierQuotationListQuery;
use App\Modules\SupplierQuotations\Domain\Listing\SupplierQuotationListCriteria;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Module 6, Point 4.1 — `OpenAPI §6`'s query contract for
 * `GET /api/v1/supplier-quotations`, parsed once and refused loudly.
 *
 * ── Why this is tested here and not only at the endpoint ───────────────────
 *
 * Modules 3, 4 and 5 test their criteria through the list endpoint, because
 * that is where the class first became reachable. This point has no endpoint —
 * it is the contract alone (4.3 serves it) — and a class that cannot be tested
 * until a later point is a class whose rules nobody checked when they were
 * written. `SupplierPage`'s own docblock makes that argument about arithmetic
 * in a serialiser, and it applies to parsing in a controller.
 *
 * ── §6.2's three refusals, and what this resource declares ────────────────
 *
 * §6.2: "Reject unknown filter, sort, group, or include values with `400
 * invalid_request`; **never ignore them silently**." §6.1 adds the page size.
 * What this resource declares is small on purpose: `filter[supplier_id]` is the
 * build plan's "Linked Quotations" on the supplier page, `filter[deal_id]` is
 * `D-51`'s "available to any deal", and nothing else is asked for by any
 * source.
 */
final class SupplierQuotationListCriteriaTest extends TestCase
{
    /** @return array<string, array{array<string, mixed>, string, string}> */
    public static function refusedQueries(): array
    {
        return [
            'a page size above §6.1 maximum' => [['per_page' => '101'], 'per_page', 'above_maximum'],
            'a fractional page' => [['page' => '1.5'], 'page', 'not_a_positive_integer'],
            'a page of zero' => [['page' => '0'], 'page', 'not_a_positive_integer'],
            'a page size that is not a number' => [['per_page' => 'lots'], 'per_page', 'not_a_positive_integer'],
            'a sort field this resource does not declare' => [['sort' => 'total_price'], 'sort', 'unknown_sort_field'],
            'the same sort field twice' => [['sort' => '-offer_date,offer_date'], 'sort', 'repeated_sort_field'],
            'a filter this resource does not declare' => [['filter' => ['currency_id' => 'EGP']], 'filter', 'unknown_filter'],
            'a supplier that is not an id' => [['filter' => ['supplier_id' => 'alpha']], 'filter[supplier_id]', 'not_a_uuid'],
            'a deal that is not an id' => [['filter' => ['deal_id' => '42']], 'filter[deal_id]', 'not_a_uuid'],
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     */
    #[DataProvider('refusedQueries')]
    public function test_that_a_query_the_contract_does_not_offer_is_refused(
        array $query,
        string $parameter,
        string $detailCode,
    ): void {
        try {
            SupplierQuotationListCriteria::fromQuery($query);
        } catch (InvalidSupplierQuotationListQuery $refusal) {
            self::assertSame($parameter, $refusal->parameter);
            self::assertSame($detailCode, $refusal->detailCode);

            // §6.2 wants the refusal to say what was wrong, and `details` is
            // where that sentence goes.
            //
            // ⚠️ **With the fallback off, deliberately.** The first version of
            // this asserted `__($key) !== $key` per locale and was proven
            // useless: deleting the Arabic sentence left it green, because
            // Laravel falls back to `fallback_locale` and returns the English
            // one. `Translator::get()`'s fourth argument is what turns "some
            // locale has this" into "this locale has this" — the concrete class
            // rather than the contract, because the contract's `get()` has no
            // such parameter.
            $translator = $this->app->make(Translator::class);

            foreach (['en', 'ar'] as $locale) {
                $sentence = $translator->get($refusal->messageKey(), [], $locale, false);

                self::assertIsString($sentence);
                self::assertNotSame(
                    $refusal->messageKey(),
                    $sentence,
                    "`{$refusal->messageKey()}` has no {$locale} sentence of its own.",
                );
            }

            return;
        }

        self::fail('The contract accepted a query it had to refuse.');
    }

    /**
     * §6.1's defaults, and §6.2's "default order is resource-specific and
     * **documented**" — the owner approved `-offer_date` on 2026-09-04, newest
     * offer first, which is the order a supplier page reads in.
     */
    public function test_that_an_empty_query_takes_the_documented_defaults(): void
    {
        $criteria = SupplierQuotationListCriteria::fromQuery([]);

        self::assertSame(1, $criteria->page);
        self::assertSame(25, $criteria->perPage);
        self::assertNull($criteria->supplierId);
        self::assertNull($criteria->dealId);
        self::assertSame([['field' => 'offer_date', 'descending' => true]], $criteria->sorts);
    }

    /** The build plan's "Linked Quotations": one supplier's offers, asked for by id. */
    public function test_that_the_supplier_page_can_ask_for_one_suppliers_offers(): void
    {
        $supplier = Uuid::uuid4()->toString();

        self::assertSame(
            $supplier,
            SupplierQuotationListCriteria::fromQuery(['filter' => ['supplier_id' => $supplier]])->supplierId,
        );
    }

    /** `D-51`: the offer is standalone and available to any deal, so a deal is a filter, not a parent. */
    public function test_that_offers_can_be_asked_for_by_deal(): void
    {
        $deal = Uuid::uuid4()->toString();

        self::assertSame(
            $deal,
            SupplierQuotationListCriteria::fromQuery(['filter' => ['deal_id' => $deal]])->dealId,
        );
    }

    /** §6.2: "Comma-separated allowed fields. Prefix `-` means descending." */
    public function test_that_the_caller_may_order_by_the_fields_this_resource_declares(): void
    {
        self::assertSame(
            [['field' => 'created_at', 'descending' => false], ['field' => 'offer_date', 'descending' => true]],
            SupplierQuotationListCriteria::fromQuery(['sort' => 'created_at,-offer_date'])->sorts,
        );
    }

    /** §6.1: pagination happens after scoping and before serialisation, so the offset is the criteria's. */
    public function test_that_the_offset_follows_the_page(): void
    {
        self::assertSame(20, SupplierQuotationListCriteria::fromQuery(['page' => '3', 'per_page' => '10'])->offset());
    }
}
