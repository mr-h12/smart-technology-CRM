<?php

declare(strict_types=1);

namespace Tests\Feature\Quotations;

use App\Modules\Quotations\Domain\Listing\InvalidQuotationListQuery;
use App\Modules\Quotations\Domain\Listing\QuotationListCriteria;
use App\Modules\Quotations\Domain\Listing\QuotationPage;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Module 7, Point 5.2 — `OpenAPI §6`'s query contract for
 * `GET /api/v1/quotations`, parsed once and refused loudly, on
 * `SupplierQuotationListCriteriaTest`'s reasoning: the endpoint is 5.4, and
 * a contract that cannot be tested until then is a contract nobody checked.
 *
 * The allowlists are transcribed from the approved Step 5 list (Q1–Q5):
 * `status` (§6.1's nine, repeatable), `bucket`, `employee`, `customer_id`,
 * `deal_id`, `currency`, `amount_min`/`amount_max` (only with `currency`),
 * `from`/`to`; sorts `quotation_date`, `created_at`, `updated_at`, `code`,
 * `final_total` (only with `currency`); `group_by` `employee|customer`.
 * No `q`, no `include`.
 */
final class QuotationListCriteriaTest extends TestCase
{
    /** @return array<string, array{array<string, mixed>, string, string}> */
    public static function refusedQueries(): array
    {
        $id = Uuid::uuid4()->toString();

        return [
            'a page size above §6.1 maximum' => [['per_page' => '101'], 'per_page', 'above_maximum'],
            'a page of zero' => [['page' => '0'], 'page', 'not_a_positive_integer'],
            'a page size that is not a number' => [['per_page' => 'lots'], 'per_page', 'not_a_positive_integer'],
            'a parameter this resource does not declare (Q5: no q)' => [['q' => 'nile'], 'q', 'unknown_parameter'],
            'an include (§6.2, none declared)' => [['include' => 'lines'], 'include', 'unknown_parameter'],
            'a filter this resource does not declare' => [['filter' => ['margin' => '10']], 'filter', 'unknown_filter'],
            'a status outside §6.1' => [['filter' => ['status' => 'won']], 'filter[status]', 'unknown_status'],
            'a repeated status outside §6.1' => [['filter' => ['status' => ['draft', 'won']]], 'filter[status]', 'unknown_status'],
            'a bucket outside Q1' => [['filter' => ['bucket' => 'all']], 'filter[bucket]', 'unknown_bucket'],
            'an employee that is not an id' => [['filter' => ['employee' => 'ali']], 'filter[employee]', 'not_a_uuid'],
            'a customer that is not an id' => [['filter' => ['customer_id' => '42']], 'filter[customer_id]', 'not_a_uuid'],
            'a deal that is not an id' => [['filter' => ['deal_id' => 'DL-2026-0001']], 'filter[deal_id]', 'not_a_uuid'],
            'a currency that is not a code' => [['filter' => ['currency' => 'pounds']], 'filter[currency]', 'not_a_code'],
            'an amount that is not a number' => [['filter' => ['currency' => 'EGP', 'amount_min' => 'ten']], 'filter[amount_min]', 'not_an_amount'],
            'a negative amount' => [['filter' => ['currency' => 'EGP', 'amount_max' => '-1']], 'filter[amount_max]', 'not_an_amount'],
            'amount_min without a currency (Q3)' => [['filter' => ['amount_min' => '100']], 'filter[amount_min]', 'currency_required'],
            'amount_max without a currency (Q3)' => [['filter' => ['amount_max' => '100']], 'filter[amount_max]', 'currency_required'],
            'a from that is not a date' => [['filter' => ['from' => 'yesterday']], 'filter[from]', 'not_a_date'],
            'a to that is not a calendar date' => [['filter' => ['to' => '2026-02-30']], 'filter[to]', 'not_a_date'],
            'a from after its to (Q4)' => [['filter' => ['from' => '2026-09-13', 'to' => '2026-09-01']], 'filter[from]', 'after_to'],
            'a sort field this resource does not declare' => [['sort' => 'margin'], 'sort', 'unknown_sort_field'],
            'the same sort field twice' => [['sort' => '-code,code'], 'sort', 'repeated_sort_field'],
            'sort by final_total without a currency (Q3)' => [['sort' => '-final_total'], 'sort', 'currency_required'],
            'a group this resource does not declare' => [['group_by' => 'status'], 'group_by', 'unknown_group'],
            'a well-formed id in the wrong filter still needs the right shape' => [['filter' => ['employee' => $id.'x']], 'filter[employee]', 'not_a_uuid'],
        ];
    }

    /** @param array<string, mixed> $query */
    #[DataProvider('refusedQueries')]
    public function test_that_a_query_the_contract_does_not_offer_is_refused(array $query, string $parameter, string $detailCode): void
    {
        try {
            QuotationListCriteria::fromQuery($query);
        } catch (InvalidQuotationListQuery $refusal) {
            self::assertSame($parameter, $refusal->parameter);
            self::assertSame($detailCode, $refusal->detailCode);
            self::assertSame('invalid_request', InvalidQuotationListQuery::ERROR_CODE);

            // Fallback off, deliberately — see SupplierQuotationListCriteriaTest.
            $translator = $this->app->make(Translator::class);
            foreach (['en', 'ar'] as $locale) {
                $sentence = $translator->get($refusal->messageKey(), [], $locale, false);
                self::assertIsString($sentence);
                self::assertNotSame($refusal->messageKey(), $sentence, "`{$refusal->messageKey()}` has no {$locale} sentence of its own.");
            }

            return;
        }

        self::fail('The contract accepted a query it had to refuse.');
    }

    /** §6.1's defaults; the approved default sort is `-updated_at`. */
    public function test_that_an_empty_query_takes_the_documented_defaults(): void
    {
        $criteria = QuotationListCriteria::fromQuery([]);

        self::assertSame(1, $criteria->page);
        self::assertSame(25, $criteria->perPage);
        self::assertSame(0, $criteria->offset());
        self::assertSame([], $criteria->statuses);
        self::assertNull($criteria->bucket);
        self::assertNull($criteria->employeeId);
        self::assertNull($criteria->customerId);
        self::assertNull($criteria->dealId);
        self::assertNull($criteria->currency);
        self::assertNull($criteria->amountMin);
        self::assertNull($criteria->amountMax);
        self::assertNull($criteria->from);
        self::assertNull($criteria->to);
        self::assertNull($criteria->groupBy);
        self::assertSame([['field' => 'updated_at', 'descending' => true]], $criteria->sorts);
    }

    /** The allowlists, transcribed: every declared value is accepted as written. */
    public function test_that_every_declared_filter_sort_and_group_is_accepted(): void
    {
        $employee = Uuid::uuid4()->toString();
        $customer = Uuid::uuid4()->toString();
        $deal = Uuid::uuid4()->toString();

        $criteria = QuotationListCriteria::fromQuery([
            'page' => '3',
            'per_page' => '100',
            'filter' => [
                'status' => ['draft', 'pending', 'approved', 'sent', 'accepted', 'partial', 'counter', 'rejected', 'expired'],
                'bucket' => 'history',
                'employee' => $employee,
                'customer_id' => $customer,
                'deal_id' => $deal,
                'currency' => 'EGP',
                'amount_min' => '100',
                'amount_max' => '2500.50',
                'from' => '2026-09-01',
                'to' => '2026-09-30',
            ],
            'sort' => 'quotation_date,-created_at,updated_at,code,-final_total',
            'group_by' => 'employee',
        ]);

        self::assertSame(200, $criteria->offset());
        self::assertSame(QuotationListCriteria::STATUSES, $criteria->statuses);
        self::assertSame('history', $criteria->bucket);
        self::assertSame($employee, $criteria->employeeId);
        self::assertSame($customer, $criteria->customerId);
        self::assertSame($deal, $criteria->dealId);
        self::assertSame('EGP', $criteria->currency);
        self::assertSame('100', $criteria->amountMin);
        self::assertSame('2500.50', $criteria->amountMax);
        self::assertSame('2026-09-01', $criteria->from);
        self::assertSame('2026-09-30', $criteria->to);
        self::assertSame('employee', $criteria->groupBy);
        self::assertSame([
            ['field' => 'quotation_date', 'descending' => false],
            ['field' => 'created_at', 'descending' => true],
            ['field' => 'updated_at', 'descending' => false],
            ['field' => 'code', 'descending' => false],
            ['field' => 'final_total', 'descending' => true],
        ], $criteria->sorts);
    }

    /** `status` is repeatable: one value arrives as a string, several as a list. */
    public function test_that_one_status_is_read_as_a_list_of_one(): void
    {
        self::assertSame(['draft'], QuotationListCriteria::fromQuery(['filter' => ['status' => 'draft']])->statuses);
        self::assertSame('customer', QuotationListCriteria::fromQuery(['group_by' => 'customer'])->groupBy);
    }

    /** Q1's two buckets are the nine statuses split in four and five. */
    public function test_that_the_buckets_partition_the_nine_statuses(): void
    {
        self::assertSame(['draft', 'pending', 'approved', 'sent'], QuotationListCriteria::BUCKETS['active']);
        self::assertSame(['accepted', 'partial', 'counter', 'rejected', 'expired'], QuotationListCriteria::BUCKETS['history']);
        self::assertSame(QuotationListCriteria::STATUSES, [...QuotationListCriteria::BUCKETS['active'], ...QuotationListCriteria::BUCKETS['history']]);
    }

    /** `OpenAPI §4.2`'s six pagination numbers, derived on `DealPage`'s precedent. */
    public function test_that_a_page_derives_its_pagination_numbers(): void
    {
        $page = new QuotationPage(items: [], total: 51, page: 2, perPage: 25);

        self::assertSame(3, $page->totalPages());
        self::assertTrue($page->hasNextPage());
        self::assertTrue($page->hasPreviousPage());
        self::assertSame(1, (new QuotationPage([], 0, 1, 25))->totalPages());
    }
}
