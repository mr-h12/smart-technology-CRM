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
 * Module 5, Point 2.6 — `lost_reason`, closing the open question Point 1.1
 * recorded. See the migration's own docblock for why this is a new column
 * rather than a reuse of `rejection_reason`.
 */
final class DealLostReasonMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_08_31_010000_add_lost_reason_to_deals.php';

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
        self::assertTrue(Schema::hasColumn('deals', 'lost_reason'));
    }

    public function test_that_a_lost_deal_without_a_reason_is_refused(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['status' => 'lost', 'lost_reason' => null])),
        );
    }

    public function test_that_a_lost_deal_with_a_blank_reason_is_refused(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->insert(['status' => 'lost', 'lost_reason' => '   '])),
        );
    }

    public function test_that_a_lost_deal_with_a_reason_is_accepted(): void
    {
        $id = $this->insert(['status' => 'lost', 'lost_reason' => 'Customer chose a competitor.']);

        self::assertSame('lost', DB::table('deals')->where('id', $id)->value('status'));
    }

    public function test_that_a_non_lost_deal_needs_no_reason(): void
    {
        $id = $this->insert(['status' => 'negotiations', 'lost_reason' => null]);

        self::assertSame('negotiations', DB::table('deals')->where('id', $id)->value('status'));
    }

    public function test_that_the_migration_rolls_back_and_forward(): void
    {
        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATION]));

        self::assertFalse(Schema::hasColumn('deals', 'lost_reason'), 'down() left `lost_reason` behind (DEV-03).');

        self::assertSame(0, Artisan::call('migrate'));

        self::assertTrue(Schema::hasColumn('deals', 'lost_reason'), '`lost_reason` did not come back.');
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

    private function refusedWith(callable $write): string
    {
        try {
            $write();
        } catch (QueryException $e) {
            return (string) $e->getCode();
        }

        self::fail('The database accepted a write it had to refuse.');
    }
}
