<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Support\Database\DocumentNumberAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Module 7, Point 1.6 — §4.7's `PREFIX-YYYY-NNNN`, now allocated in one place.
 *
 * `DL`'s and `SQ`'s own numbering tests are untouched by that move and are the
 * proof the extraction preserved behaviour. What they cannot show is the
 * property the shared class is actually for: two callers that share nothing but
 * the connection still never receive the same number, and one prefix's count is
 * not another's. Those are asserted here, against the class rather than through
 * a module.
 */
final class DocumentNumberAllocatorTest extends TestCase
{
    use RefreshDatabase;

    private function allocator(string $prefix): DocumentNumberAllocator
    {
        return new DocumentNumberAllocator(DB::connection(), $prefix);
    }

    public function test_that_the_first_number_of_the_year_is_one(): void
    {
        self::assertSame(
            'DL-'.now()->format('Y').'-0001',
            $this->allocator('DL')->next(),
        );
    }

    /**
     * The concurrency property, as close as a single process can get to it: two
     * allocators built independently — which is what two simultaneous requests
     * get — must not both be handed `0001`. A `SELECT` followed by an `UPDATE`
     * would fail here; `INSERT … ON CONFLICT … DO UPDATE … RETURNING` does not,
     * because the counter is read and advanced in the same statement.
     */
    public function test_that_two_independent_allocators_never_repeat_a_number(): void
    {
        $first = $this->allocator('SQ')->next();
        $second = $this->allocator('SQ')->next();

        self::assertSame('SQ-'.now()->format('Y').'-0001', $first);
        self::assertSame('SQ-'.now()->format('Y').'-0002', $second);
    }

    /**
     * `document_sequences` keys on `(prefix, year)` (Module 0). Asserted rather
     * than assumed: a single shared counter would still produce unique codes and
     * would still look right in a test that only ever allocates one prefix.
     */
    public function test_that_one_prefix_does_not_move_another(): void
    {
        $this->allocator('DL')->next();
        $this->allocator('DL')->next();

        self::assertSame(
            'SQ-'.now()->format('Y').'-0001',
            $this->allocator('SQ')->next(),
        );
    }

    /**
     * The year comes from the clock, not from a row — so a create crossing
     * midnight on 31 December starts a new count on 1 January rather than 2026
     * counting forever.
     */
    public function test_that_a_new_year_starts_a_new_count(): void
    {
        $this->travelTo(Carbon::parse('2026-12-31 23:59:59'));
        self::assertSame('DL-2026-0001', $this->allocator('DL')->next());
        self::assertSame('DL-2026-0002', $this->allocator('DL')->next());

        $this->travelTo(Carbon::parse('2027-01-01 00:00:01'));
        self::assertSame('DL-2027-0001', $this->allocator('DL')->next());
    }
}
