<?php

declare(strict_types=1);

namespace Tests\Feature\Deals;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * F-41 · 1.4 — `details`, `D-108`'s optional internal free text on a deal
 * (≤ 2000 characters). The limit is the column's as well as the request's
 * (owner, 2026-10-11, «أ»), counted in characters: Arabic text is two bytes a
 * character, so a byte limit would halve it.
 */
final class DealDetailsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_11_000000_add_details_to_deals.php';

    private const CHECK_VIOLATION = '23514';

    private string $customerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customerId = Uuid::uuid4()->toString();

        DB::table('customers')->insert([
            'id' => $this->customerId,
            'name' => 'Test Customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_that_the_column_exists(): void
    {
        self::assertTrue(Schema::hasColumn('deals', 'details'));
    }

    public function test_that_a_deal_needs_no_details(): void
    {
        $id = $this->insert();

        self::assertNull(DB::table('deals')->where('id', $id)->value('details'));
    }

    public function test_that_two_thousand_arabic_characters_are_accepted(): void
    {
        $id = $this->insert(['details' => str_repeat('ب', 2000)]);

        $details = DB::table('deals')->where('id', $id)->value('details');
        self::assertIsString($details);
        self::assertSame(2000, mb_strlen($details));
    }

    public function test_that_details_longer_than_2000_characters_are_refused(): void
    {
        // `expectExceptionCode` and not a 20th private `refusedWith()` (debt register: one shared trait owed).
        $this->expectException(QueryException::class);
        $this->expectExceptionCode(self::CHECK_VIOLATION);

        $this->insert(['details' => str_repeat('ب', 2001)]);
    }

    public function test_that_the_migration_rolls_back_and_forward(): void
    {
        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATION]));

        self::assertFalse(Schema::hasColumn('deals', 'details'), 'down() left `details` behind (DEV-03).');

        self::assertSame(0, Artisan::call('migrate'));

        self::assertTrue(Schema::hasColumn('deals', 'details'), '`details` did not come back.');
    }

    /** @param  array<string, mixed>  $overrides */
    private function insert(array $overrides = []): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('deals')->insert(array_merge([
            'id' => $id,
            'code' => 'DL-2026-'.substr(str_replace('-', '', $id), -4),
            'customer_id' => $this->customerId,
            'last_activity_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $id;
    }
}
