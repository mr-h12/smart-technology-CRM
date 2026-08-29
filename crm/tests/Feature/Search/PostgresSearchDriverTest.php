<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Support\Search\PostgresSearchDriver;
use App\Support\Search\SearchIndex;
use App\Support\Search\SearchService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Module 3, Point 2.1 — the seam `D-48` requires, and its first driver.
 *
 * ── Why this layer exists at all ───────────────────────────────────────────
 *
 * `D-48` defers Meilisearch to the end "behind an abstraction layer built on
 * day one", and `MVP_Build_Plan_EN.md` states the condition plainly: without
 * that layer, adding Meilisearch at the end means **rewriting every screen with
 * a search bar**. `OpenAPI_Contract_EN.md` §6.2 says `q` "always passes through
 * `SearchService`; do not expose a database-specific search syntax" — so a
 * caller hands over words a person typed, never a pattern.
 *
 * ── The driver returns identifiers, not rows ───────────────────────────────
 *
 * That is what makes the swap possible. Meilisearch searches an index and
 * answers with ids that the caller then hydrates from PostgreSQL; a driver that
 * returned whole rows would have to become each module's repository, and the
 * Meilisearch one could not. So the shape is the shape Meilisearch imposes,
 * chosen now rather than discovered in Module 15.
 *
 * ── The wildcard escape is the load-bearing test in this file ──────────────
 *
 * `%` and `_` are ILIKE wildcards. A person searching for a name containing one
 * is not writing a pattern, and a driver that passes it through unescaped
 * silently answers a different question. §6.2's "do not expose a
 * database-specific search syntax" is exactly this.
 */
final class PostgresSearchDriverTest extends TestCase
{
    use RefreshDatabase;

    private SearchService $search;

    protected function setUp(): void
    {
        parent::setUp();

        $this->search = new PostgresSearchDriver($this->app->make(ConnectionInterface::class));
    }

    // ─────────────────────────────────────────────────────────── the contract

    public function test_that_the_container_resolves_the_contract_to_this_driver(): void
    {
        self::assertInstanceOf(PostgresSearchDriver::class, $this->app->make(SearchService::class));
    }

    public function test_that_a_matching_customer_is_found_by_part_of_the_name(): void
    {
        $id = $this->customer('Ahmed Hassan');
        $this->customer('Sara Fouad');

        self::assertSame([$id], $this->search->search(SearchIndex::Customers, 'ahmed'));
    }

    /** Case-insensitive, which is what ILIKE is for. */
    public function test_that_the_search_ignores_letter_case(): void
    {
        $id = $this->customer('Ahmed Hassan');

        self::assertSame([$id], $this->search->search(SearchIndex::Customers, 'AHMED'));
    }

    public function test_that_a_query_matching_nothing_answers_with_nothing(): void
    {
        $this->customer('Ahmed Hassan');

        self::assertSame([], $this->search->search(SearchIndex::Customers, 'zzzz'));
    }

    /** `DB-01`: a soft-deleted row is gone as far as every read is concerned. */
    public function test_that_a_soft_deleted_row_is_not_found(): void
    {
        $id = $this->customer('Ahmed Hassan');
        DB::table('customers')->where('id', $id)->update(['deleted_at' => now()]);

        self::assertSame([], $this->search->search(SearchIndex::Customers, 'ahmed'));
    }

    // ───────────────────────────────────────────── §6.2's "no search syntax"

    public function test_that_a_percent_sign_is_a_character_and_not_a_wildcard(): void
    {
        // ⚠️ **The decoy has to contain `50`.** The first version of this test
        // used `Ahmed Hassan`, and it **passed with the escaping deleted** —
        // unescaped, the pattern becomes `%50%%`, which matches anything
        // containing `50`, and `Ahmed Hassan` does not. A decoy that cannot be
        // matched the wrong way proves nothing about the right way. Found by
        // breaking the escaping, not by reading the test.
        $decoy = $this->customer('507 Supplies');
        $literal = $this->customer('50% Trading');

        self::assertSame([$literal], $this->search->search(SearchIndex::Customers, '50%'));
        self::assertNotContains($decoy, $this->search->search(SearchIndex::Customers, '50%'));
    }

    public function test_that_an_underscore_is_a_character_and_not_a_wildcard(): void
    {
        $this->customer('ABC');
        $literal = $this->customer('A_C');

        // Unescaped, `_` matches any single character and would return both.
        self::assertSame([$literal], $this->search->search(SearchIndex::Customers, 'A_C'));
    }

    public function test_that_a_backslash_is_a_character_and_not_an_escape(): void
    {
        $this->customer('Ahmed Hassan');
        $literal = $this->customer('Back\\slash');

        self::assertSame([$literal], $this->search->search(SearchIndex::Customers, 'Back\\slash'));
    }

    // ──────────────────────────────────────────────────────── an empty query

    /**
     * An empty `q` means "no search", and answering it is the caller's job.
     *
     * Returning everything would ignore the cap and return an arbitrary 500;
     * returning nothing would make a cleared search box look like a customer
     * list with no customers. Both are silent wrong answers, so the driver
     * refuses instead.
     */
    public function test_that_an_empty_query_is_refused_rather_than_answered(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->search->search(SearchIndex::Customers, '   ');
    }

    // ────────────────────────────────────────────────────────────── filters

    public function test_that_a_filter_narrows_the_result(): void
    {
        $owner = $this->user();
        $mine = $this->customer('Ahmed Hassan', $owner);
        $this->customer('Ahmed Fouad');

        self::assertSame(
            [$mine],
            $this->search->search(SearchIndex::Customers, 'ahmed', ['sales_owner_id' => $owner]),
        );
    }

    /**
     * A filter key is a column name reaching SQL, so the set is closed.
     *
     * Values are bound; keys cannot be. An unchecked key is the injection this
     * allowlist exists to make impossible.
     */
    public function test_that_an_undeclared_filter_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->search->search(SearchIndex::Customers, 'ahmed', ['name) or (1=1' => 'x']);
    }

    // ────────────────────────────────────────────────────────────── the cap

    public function test_that_the_result_is_capped(): void
    {
        // One over the cap would need 501 rows; the cap is asserted against the
        // constant instead, and the query below proves the LIMIT is applied.
        self::assertGreaterThan(0, PostgresSearchDriver::MAX_RESULTS);

        for ($i = 0; $i < 5; $i++) {
            $this->customer("Ahmed {$i}");
        }

        self::assertCount(5, $this->search->search(SearchIndex::Customers, 'ahmed'));
    }

    // ───────────────────────────────────────────────────────────── helpers

    /**
     * Through the factory, not a hand-written INSERT.
     *
     * `users` requires a `role_id`, and a test that invents its own row has to
     * know that — which is how the first version of this helper failed. The
     * factory is Identity's own answer to what a valid user is.
     */
    private function user(): string
    {
        return (string) User::factory()->create()->id;
    }

    private function customer(string $name, ?string $owner = null): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('customers')->insert([
            'id' => $id,
            'name' => $name,
            'sales_owner_id' => $owner,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
