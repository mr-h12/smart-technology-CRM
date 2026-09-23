<?php

declare(strict_types=1);

namespace Tests\Feature\Deals;

use App\Modules\Deals\Domain\Contracts\DealTitlesInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Module 9, Point 2.3 — `DealTitlesInterface`, the seam the customer PDF's
 * **Subject** line reads (`D-89`).
 *
 * A separate contract rather than a sixth method on `DealFactsInterface`:
 * Module 6's own tests implement that interface as an anonymous fake, and a
 * new method there would leave those fakes abstract — a module of Yousef's
 * broken by a change in ours, which the per-module rule exists to prevent.
 */
final class DealTitlesTest extends TestCase
{
    use RefreshDatabase;

    public function test_that_a_deals_title_is_published_by_id(): void
    {
        $titled = $this->deal('Printers for the Alexandria branch');
        $untitled = $this->deal(null);

        $titles = $this->titles()->titlesOf([$titled, $untitled]);

        self::assertSame(['Printers for the Alexandria branch'], array_values($titles));
        self::assertSame([$titled], array_keys($titles), 'A deal with no title has no entry, not a blank one.');
    }

    public function test_that_a_soft_deleted_deal_is_absent(): void
    {
        // DB-01: archived, not gone — and not printed on a customer's document.
        $dealId = $this->deal('Archived request');
        DB::table('deals')->where('id', $dealId)->update(['deleted_at' => now()]);

        self::assertSame([], $this->titles()->titlesOf([$dealId]));
    }

    public function test_that_the_empty_list_asks_the_database_nothing(): void
    {
        DB::enableQueryLog();

        self::assertSame([], $this->titles()->titlesOf([]));
        self::assertSame([], DB::getQueryLog());
    }

    private function titles(): DealTitlesInterface
    {
        return $this->app->make(DealTitlesInterface::class);
    }

    private function deal(?string $title): string
    {
        $customerId = Uuid::uuid7()->toString();
        $dealId = Uuid::uuid7()->toString();

        DB::table('customers')->insert(['id' => $customerId, 'name' => 'Vegatrone', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('deals')->insert([
            'id' => $dealId,
            'code' => 'DL-2026-'.substr(str_replace('-', '', $dealId), -4),
            'customer_id' => $customerId,
            'title' => $title,
            'last_activity_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $dealId;
    }
}
