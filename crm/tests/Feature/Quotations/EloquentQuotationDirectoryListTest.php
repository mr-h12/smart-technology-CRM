<?php

declare(strict_types=1);

namespace Tests\Feature\Quotations;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Quotations\Domain\Access\QuotationRowScope;
use App\Modules\Quotations\Domain\Contracts\QuotationDirectoryInterface;
use App\Modules\Quotations\Domain\Listing\QuotationListCriteria;
use App\Modules\Quotations\Domain\Listing\QuotationPage;
use App\Modules\Quotations\Domain\Listing\QuotationSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Module 7, Point 5.3 — `QuotationDirectoryInterface::list()`: the approved
 * Step 5 filters in the query, the `SEC-08` scope in the query (`deal_id IN`
 * the owner's deals through `DealFactsInterface::dealIdsOwnedBy()`), and
 * `OpenAPI §6.1`'s "pagination after scoping".
 *
 * Three quotations, two owners, two customers, two currencies:
 *
 * | id | deal (owner, customer) | currency | status   | total | date       | updated |
 * |----|------------------------|----------|----------|-------|------------|---------|
 * | q1 | dA (A, C1)             | EGP      | draft    | 100   | 2026-09-01 | oldest  |
 * | q2 | dA (A, C1)             | USD      | sent     | 200   | 2026-09-10 | middle  |
 * | q3 | dB (B, C2)             | EGP      | accepted | 300   | 2026-09-20 | newest  |
 */
final class EloquentQuotationDirectoryListTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        $a = User::factory()->create()->getKey();
        $b = User::factory()->create()->getKey();
        self::assertIsString($a);
        self::assertIsString($b);
        $this->ids['A'] = $a;
        $this->ids['B'] = $b;
        $this->ids['C1'] = $this->customer();
        $this->ids['C2'] = $this->customer();
        $this->ids['dA'] = $this->deal($this->ids['C1'], $a);
        $this->ids['dB'] = $this->deal($this->ids['C2'], $b);
        $this->ids['EGP'] = $this->currency('EGP');
        $this->ids['USD'] = $this->currency('USD');
        $this->ids['q1'] = $this->quotation('QT-2026-0001', 'dA', 'C1', 'EGP', 'draft', '100', '2026-09-01', '2026-09-01 08:00:00');
        $this->ids['q2'] = $this->quotation('QT-2026-0002', 'dA', 'C1', 'USD', 'sent', '200', '2026-09-10', '2026-09-10 08:00:00');
        $this->ids['q3'] = $this->quotation('QT-2026-0003', 'dB', 'C2', 'EGP', 'accepted', '300', '2026-09-20', '2026-09-20 08:00:00');
    }

    /** @return array<string, array{array<string, mixed>, list<string>}> */
    public static function filters(): array
    {
        return [
            'one status' => [['status' => 'sent'], ['q2']],
            'two statuses' => [['status' => ['draft', 'accepted']], ['q1', 'q3']],
            'the active bucket (Q1)' => [['bucket' => 'active'], ['q1', 'q2']],
            'the history bucket (Q1)' => [['bucket' => 'history'], ['q3']],
            'an employee (Q2: the deal owner)' => [['employee' => '@A'], ['q1', 'q2']],
            'a customer' => [['customer_id' => '@C2'], ['q3']],
            'a deal' => [['deal_id' => '@dB'], ['q3']],
            'a currency code' => [['currency' => 'USD'], ['q2']],
            'a currency code nothing has' => [['currency' => 'XXX'], []],
            'amount_min in a currency (Q3)' => [['currency' => 'EGP', 'amount_min' => '200'], ['q3']],
            'amount_max in a currency (Q3)' => [['currency' => 'EGP', 'amount_max' => '200'], ['q1']],
            'from, inclusive (Q4)' => [['from' => '2026-09-10'], ['q2', 'q3']],
            'to, inclusive (Q4)' => [['to' => '2026-09-10'], ['q1', 'q2']],
            'two together' => [['bucket' => 'active', 'currency' => 'EGP'], ['q1']],
        ];
    }

    /**
     * @param  array<string, mixed>  $filter
     * @param  list<string>  $expected
     */
    #[DataProvider('filters')]
    public function test_that_each_filter_selects_in_the_query(array $filter, array $expected): void
    {
        foreach ($filter as $key => $value) {
            if (is_string($value) && str_starts_with($value, '@')) {
                $filter[$key] = $this->ids[substr($value, 1)];
            }
        }

        $page = $this->list(['filter' => $filter], ['all'], $this->ids['A']);

        self::assertSame($this->sorted($this->named($expected)), $this->sorted($this->idsOf($page)));
        self::assertSame(count($expected), $page->total);
    }

    /** Q1: every one of §6.1's nine statuses is in exactly one bucket. */
    public function test_that_the_buckets_split_the_statuses_without_overlap_or_gap(): void
    {
        foreach (QuotationListCriteria::STATUSES as $i => $status) {
            $this->quotation('QT-2026-01'.sprintf('%02d', $i), 'dA', 'C1', 'EGP', $status, '1', '2026-01-01', '2026-01-01 00:00:00');
        }

        $active = $this->list(['filter' => ['bucket' => 'active'], 'per_page' => '100'], ['all'], $this->ids['A']);
        $history = $this->list(['filter' => ['bucket' => 'history'], 'per_page' => '100'], ['all'], $this->ids['A']);

        self::assertSame(4 + 2, $active->total);
        self::assertSame(5 + 1, $history->total);
        self::assertSame([], array_intersect($this->idsOf($active), $this->idsOf($history)));
    }

    public function test_that_own_reaches_only_the_quotations_of_the_callers_deals(): void
    {
        self::assertSame($this->sorted($this->named(['q1', 'q2'])), $this->sorted($this->idsOf($this->list([], ['own'], $this->ids['A']))));
        self::assertSame($this->named(['q3']), $this->idsOf($this->list([], ['own'], $this->ids['B'])));
    }

    /** Module 8 · 2.2 (§8 "Incomplete"): a returned draft, still a draft to Q1, within the caller's rows. */
    public function test_that_the_incomplete_bucket_is_the_returned_drafts_within_scope(): void
    {
        DB::table('quotations')->where('id', $this->ids['q1'])->update(['returned_at' => now()]);
        $this->ids['q4'] = $this->quotation('QT-2026-0004', 'dB', 'C2', 'EGP', 'draft', '400', '2026-09-21', '2026-09-21 08:00:00');

        self::assertSame($this->named(['q1']), $this->idsOf($this->list(['filter' => ['bucket' => 'incomplete']], ['all'], $this->ids['A'])));
        self::assertSame($this->named(['q1']), $this->idsOf($this->list(['filter' => ['bucket' => 'incomplete']], ['own'], $this->ids['A'])));
        self::assertSame([], $this->idsOf($this->list(['filter' => ['bucket' => 'incomplete']], ['own'], $this->ids['B'])));
        self::assertContains($this->ids['q1'], $this->idsOf($this->list(['filter' => ['bucket' => 'active']], ['all'], $this->ids['A'])));
    }

    public function test_that_a_soft_deleted_quotation_is_absent(): void
    {
        DB::table('quotations')->where('id', $this->ids['q2'])->update(['deleted_at' => now()]);

        self::assertSame($this->named(['q1']), $this->idsOf($this->list([], ['own'], $this->ids['A'])));
        self::assertSame(2, $this->list([], ['all'], $this->ids['A'])->total);
    }

    /** `OpenAPI §5.1`: a scope that reaches nothing is answered before any read. */
    public function test_that_a_scope_permitting_nothing_answers_an_empty_page_without_a_query(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        $page = $this->list(['page' => '2'], ['team'], $this->ids['A']);

        self::assertSame([], $page->items);
        self::assertSame(0, $page->total);
        self::assertSame(2, $page->page);
        self::assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
    }

    /** `OpenAPI §6.1`: the count is taken after scoping; the last page carries the remainder. */
    public function test_that_pagination_counts_after_scoping(): void
    {
        $page = $this->list(['per_page' => '2', 'page' => '2'], ['all'], $this->ids['A']);

        self::assertSame(3, $page->total);
        self::assertSame(2, $page->totalPages());
        self::assertCount(1, $page->items);
        self::assertTrue($page->hasPreviousPage());
        self::assertFalse($page->hasNextPage());

        self::assertSame(2, $this->list(['per_page' => '1'], ['own'], $this->ids['A'])->total);
    }

    public function test_that_the_default_order_is_most_recently_updated_first(): void
    {
        self::assertSame($this->named(['q3', 'q2', 'q1']), $this->idsOf($this->list([], ['all'], $this->ids['A'])));
        self::assertSame($this->named(['q1', 'q2', 'q3']), $this->idsOf($this->list(['sort' => 'code'], ['all'], $this->ids['A'])));
        self::assertSame(
            $this->named(['q3', 'q1']),
            $this->idsOf($this->list(['sort' => '-final_total', 'filter' => ['currency' => 'EGP']], ['all'], $this->ids['A'])),
        );
    }

    /** Q6: the row carries §6.6's columns and nothing from the cost side. */
    public function test_that_a_row_carries_the_columns_the_list_shows(): void
    {
        $page = $this->list(['filter' => ['deal_id' => $this->ids['dB']]], ['all'], $this->ids['A']);
        $row = $page->items[0];

        self::assertInstanceOf(QuotationSummary::class, $row);
        self::assertSame($this->ids['q3'], $row->id);
        self::assertSame('QT-2026-0003', $row->code);
        self::assertSame('accepted', $row->status);
        self::assertSame($this->ids['C2'], $row->customerId);
        self::assertSame($this->ids['dB'], $row->dealId);
        self::assertSame($this->ids['EGP'], $row->currencyId);
        self::assertSame('300.000000', $row->finalTotal);
        self::assertSame('2026-09-20', $row->quotationDate);
        self::assertNull($row->validUntil);
        self::assertNull($row->submittedAt);
        self::assertSame(1, $row->version);
        self::assertNull($row->parentId);
        self::assertSame('2026-09-20T08:00:00+00:00', $row->updatedAt->format(DATE_ATOM));
    }

    // ── fixtures ───────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $query
     * @param  list<string>  $scopes
     */
    private function list(array $query, array $scopes, string $actorId): QuotationPage
    {
        return $this->app->make(QuotationDirectoryInterface::class)->list(
            QuotationListCriteria::fromQuery($query),
            QuotationRowScope::resolve($scopes, $actorId),
        );
    }

    /** @return list<string> */
    private function idsOf(QuotationPage $page): array
    {
        return array_map(static fn (QuotationSummary $row): string => $row->id, $page->items);
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function named(array $names): array
    {
        return array_map(fn (string $name): string => $this->ids[$name], $names);
    }

    private function quotation(string $code, string $deal, string $customer, string $currency, string $status, string $total, string $date, string $updatedAt): string
    {
        $id = Uuid::uuid4()->toString();

        // The six money identities hold with one number: no discount, no tax,
        // no additional items, rounding off.
        DB::table('quotations')->insert([
            'id' => $id,
            'code' => $code,
            'deal_id' => $this->ids[$deal],
            'customer_id' => $this->ids[$customer],
            'currency_id' => $this->ids[$currency],
            'status' => $status,
            'quotation_date' => $date,
            'rejection_reason' => in_array($status, ['rejected', 'counter'], true) ? 'Price' : null,
            'default_margin' => '0',
            'discount_percent' => '0',
            'rounding_unit' => '0.05',
            'rounding_enabled' => false,
            'subtotal' => $total,
            'additional_total' => '0',
            'discount_amount' => '0',
            'tax_base' => $total,
            'net_amount' => $total,
            'total_before_round' => $total,
            'final_total' => $total,
            'rounding_diff' => '0',
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ]);

        return $id;
    }

    private function currency(string $code): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('currencies')->insert([
            'id' => $id,
            'code' => $code,
            'rounding_unit' => '0.05',
            'rounding_enabled' => false,
            'is_base' => $code === 'EGP',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function customer(): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('customers')->insert([
            'id' => $id,
            'name' => 'Nile Trading',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function deal(string $customerId, string $ownerId): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('deals')->insert([
            'id' => $id,
            'code' => 'DL-'.now()->format('Y').'-'.substr($id, 0, 4),
            'customer_id' => $customerId,
            'owner_id' => $ownerId,
            'last_activity_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
