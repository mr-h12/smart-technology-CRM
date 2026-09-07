<?php

declare(strict_types=1);

namespace Tests\Feature\Quotations;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Module 7, Point 1.5 — `customers.is_tax_exempt`.
 *
 * The column lives on Module 3's table but exists for `D-63`, a quotation
 * rule, so the test lives with Module 7 alongside the point that added it.
 *
 * `D-63` names the column itself — "The customer record carries the default
 * (`is_tax_exempt`)" — so the assertions below check the two things the
 * decision actually promises: that a customer can be flagged, and that
 * flagging none of them was the state every existing row starts from.
 */
final class CustomerTaxExemptionMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_that_the_column_d_63_names_exists(): void
    {
        self::assertTrue(
            Schema::hasColumn('customers', 'is_tax_exempt'),
            'D-63: "The customer record carries the default (`is_tax_exempt`)".',
        );
    }

    /**
     * The safe direction. Every customer created before this migration was
     * created under a system that taxed normally, and a migration must not
     * change what any of them is charged.
     */
    public function test_that_a_customer_is_taxed_unless_someone_says_otherwise(): void
    {
        $this->insert();

        self::assertFalse(
            (bool) DB::table('customers')->value('is_tax_exempt'),
            'the default exempts customers nobody exempted — a financial change made by a migration.',
        );
    }

    public function test_that_a_customer_can_be_flagged_exempt(): void
    {
        $this->insert(['is_tax_exempt' => true]);

        self::assertTrue((bool) DB::table('customers')->value('is_tax_exempt'));
    }

    /**
     * A customer is taxed or is not — there is no third state. Contrast
     * `quotations.tax_percent`, where NULL means "no tax line at all" and is
     * deliberately distinct from a rate of zero (`D-63`, the other half).
     */
    public function test_that_the_flag_has_no_third_state(): void
    {
        try {
            $this->insert(['is_tax_exempt' => null]);
        } catch (QueryException $exception) {
            self::assertSame(
                '23502',
                $exception->errorInfo[0] ?? null,
                'an unknown exemption state is not a state D-63 describes.',
            );

            return;
        }

        self::fail('`is_tax_exempt` accepted NULL — a customer is taxed or is not.');
    }

    /**
     * `DB-09` names customer · owner · deal status · dates · entity codes, and
     * this is none of them. Nothing documented lists customers by exemption —
     * it is read one row at a time when a quotation is created. Asserted so
     * the absence reads as the decision it is.
     */
    public function test_that_the_flag_is_not_indexed(): void
    {
        $indexes = DB::select(
            'select indexname from pg_indexes where tablename = ? and indexdef like ?',
            ['customers', '%is_tax_exempt%'],
        );

        self::assertSame([], $indexes, 'an index for a query nothing specifies still costs every write.');
    }

    // ────────────────────────────────────────────────────────────────── DEV-03

    public function test_that_the_migration_rolls_back_and_forward(): void
    {
        Artisan::call('migrate:reset', ['--force' => true]);
        self::assertFalse(
            Schema::hasTable('customers'),
            'down() left `customers` behind — this point only adds a column, so the table goes with Module 3.',
        );

        self::assertSame(0, Artisan::call('migrate'));
        self::assertTrue(
            Schema::hasColumn('customers', 'is_tax_exempt'),
            '`is_tax_exempt` did not come back.',
        );
    }

    /** @param array<string, mixed> $overrides */
    private function insert(array $overrides = []): void
    {
        DB::table('customers')->insert(array_merge([
            'id' => Uuid::uuid4()->toString(),
            'name' => 'Nile Trading',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }
}
