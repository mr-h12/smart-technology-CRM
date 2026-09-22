<?php

declare(strict_types=1);

namespace Tests\Feature\Deals;

use App\Modules\Deals\Domain\Contracts\DealFactsInterface;
use App\Modules\Deals\Infrastructure\EloquentDealFacts;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Support\Search\SearchIndex;
use App\Support\Search\SearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Module 7's one read of a deal (Point 3.4): who owns it and whom it is for,
 * so a scoped `quotation.create` can be filed against `deals.owner_id` and a
 * quotation's `customer_id` can be held to its deal's.
 */
final class DealFactsTest extends TestCase
{
    use RefreshDatabase;

    public function test_that_the_contract_resolves_to_the_eloquent_reader(): void
    {
        self::assertInstanceOf(EloquentDealFacts::class, $this->app->make(DealFactsInterface::class));
    }

    public function test_that_an_owned_deal_names_its_owner_and_customer(): void
    {
        $ownerId = User::factory()->create()->getKey();
        self::assertIsString($ownerId);
        $customerId = $this->customer();

        $facts = $this->reader()->factsOf($this->deal($customerId, $ownerId));

        self::assertNotNull($facts);
        self::assertSame($ownerId, $facts->ownerId);
        self::assertSame($customerId, $facts->customerId);
    }

    /** `deals.owner_id` is nullable; an unowned deal is a fact, not an absence. */
    public function test_that_an_unowned_deal_has_a_null_owner_and_still_a_customer(): void
    {
        $customerId = $this->customer();

        $facts = $this->reader()->factsOf($this->deal($customerId, null));

        self::assertNotNull($facts);
        self::assertNull($facts->ownerId);
        self::assertSame($customerId, $facts->customerId);
    }

    public function test_that_a_soft_deleted_deal_is_absent(): void
    {
        $dealId = $this->deal($this->customer(), null);
        DB::table('deals')->where('id', $dealId)->update(['deleted_at' => now()]);

        self::assertNull($this->reader()->factsOf($dealId));
    }

    public function test_that_an_unknown_deal_is_absent(): void
    {
        self::assertNull($this->reader()->factsOf(Uuid::uuid4()->toString()));
    }

    // ── Point 5.1: the set form the list reads ──────────────────────────────

    public function test_that_owned_deal_ids_are_only_that_owners_live_deals(): void
    {
        $ownerId = User::factory()->create()->getKey();
        $otherId = User::factory()->create()->getKey();
        self::assertIsString($ownerId);
        self::assertIsString($otherId);
        $customerId = $this->customer();

        $mine = $this->deal($customerId, $ownerId);
        $mineToo = $this->deal($customerId, $ownerId);
        $this->deal($customerId, $otherId);
        $this->deal($customerId, null);
        $deleted = $this->deal($customerId, $ownerId);
        DB::table('deals')->where('id', $deleted)->update(['deleted_at' => now()]);

        $ids = $this->reader()->dealIdsOwnedBy($ownerId);

        sort($ids);
        self::assertSame(collect([$mine, $mineToo])->sort()->values()->all(), $ids);
    }

    public function test_that_owners_of_maps_each_live_deal_to_its_owner_or_null(): void
    {
        $ownerId = User::factory()->create()->getKey();
        self::assertIsString($ownerId);
        $customerId = $this->customer();

        $owned = $this->deal($customerId, $ownerId);
        $unowned = $this->deal($customerId, null);

        self::assertSame(
            [$owned => $ownerId, $unowned => null],
            $this->reader()->ownersOf([$owned, $unowned]),
        );
    }

    /** A soft-deleted or unknown id names nothing: absent from the map, not `null` in it. */
    public function test_that_owners_of_omits_soft_deleted_and_unknown_deals(): void
    {
        $customerId = $this->customer();
        $live = $this->deal($customerId, null);
        $deleted = $this->deal($customerId, null);
        DB::table('deals')->where('id', $deleted)->update(['deleted_at' => now()]);

        $owners = $this->reader()->ownersOf([$live, $deleted, Uuid::uuid4()->toString()]);

        self::assertSame([$live], array_keys($owners));
    }

    public function test_that_owners_of_an_empty_list_answers_without_a_query(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        self::assertSame([], $this->reader()->ownersOf([]));

        self::assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
    }

    // ── F-13 · 1.2 (D-88): a deal's code, for SupplierQuotations ────────────

    /** `D-88`: a fragment of the code, trimmed and any case, through `SearchService`; `DB-01` drops the deleted. */
    public function test_that_a_code_fragment_finds_live_deals_any_case_trimmed(): void
    {
        $customerId = $this->customer();
        $first = $this->deal($customerId, null, 'DL-2026-0001');
        $third = $this->deal($customerId, null, 'DL-2026-0003');
        $this->deal($customerId, null, 'DL-2025-0100');
        $deleted = $this->deal($customerId, null, 'DL-2026-0005');
        DB::table('deals')->where('id', $deleted)->update(['deleted_at' => now()]);

        $ids = $this->reader()->dealIdsMatchingCode('  dl-2026-000  ');
        sort($ids);

        self::assertSame(collect([$first, $third])->sort()->values()->all(), $ids);
        self::assertSame([$third], $this->reader()->dealIdsMatchingCode('0003'));
    }

    /** `D-48`/`D-88`: the fragment goes through `SearchService` — the seam Meilisearch replaces — not beside it. */
    public function test_that_a_code_fragment_is_answered_by_the_search_service(): void
    {
        $spy = new class implements SearchService
        {
            /** @var list<array{SearchIndex, string}> */
            public array $calls = [];

            public function search(SearchIndex $index, string $query, array $filters = []): array
            {
                $this->calls[] = [$index, $query];

                return ['from-the-search-service'];
            }
        };
        $this->app->instance(SearchService::class, $spy);

        self::assertSame(['from-the-search-service'], $this->reader()->dealIdsMatchingCode('0003'));
        self::assertSame([[SearchIndex::Deals, '0003']], $spy->calls);
    }

    public function test_that_codes_of_maps_live_deals_and_omits_soft_deleted_and_unknown(): void
    {
        $customerId = $this->customer();
        $first = $this->deal($customerId, null, 'DL-2026-0001');
        $second = $this->deal($customerId, null, 'DL-2026-0002');
        $deleted = $this->deal($customerId, null, 'DL-2026-0003');
        DB::table('deals')->where('id', $deleted)->update(['deleted_at' => now()]);

        $codes = $this->reader()->codesOf([$first, $second, $deleted, Uuid::uuid4()->toString()]);
        ksort($codes);

        $expected = [$first => 'DL-2026-0001', $second => 'DL-2026-0002'];
        ksort($expected);
        self::assertSame($expected, $codes);
    }

    public function test_that_codes_of_an_empty_list_answers_without_a_query(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        self::assertSame([], $this->reader()->codesOf([]));

        self::assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
    }

    private function reader(): EloquentDealFacts
    {
        $reader = $this->app->make(DealFactsInterface::class);
        self::assertInstanceOf(EloquentDealFacts::class, $reader);

        return $reader;
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

    private function deal(string $customerId, ?string $ownerId, ?string $code = null): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('deals')->insert([
            'id' => $id,
            'code' => $code ?? 'DL-'.now()->format('Y').'-'.substr($id, 0, 4),
            'customer_id' => $customerId,
            'owner_id' => $ownerId,
            'last_activity_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
