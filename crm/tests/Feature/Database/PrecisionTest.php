<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Support\Database\Precision;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * D-68's precisions, enforced rather than declared.
 *
 * The distinction is the whole point. sqlite accepted NUMERIC(4,2) values of
 * 123456.789 without complaint and stored NUMERIC(18,6) as a double; the reason
 * §14.2 chose PostgreSQL is that its column types are constraints. These tests
 * assert the constraint actually bites.
 */
final class PrecisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('precision_probes', function (Blueprint $table): void {
            $table->standardId();
            $table->money('amount');
            $table->fxRate('rate');
            $table->percentage('percent');
            $table->quantity('qty');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('precision_probes');
        parent::tearDown();
    }

    public function test_the_columns_have_the_precision_d68_fixed(): void
    {
        // The numbers are written out, not read from Precision. Comparing the
        // columns against the same constants that built them proves only that
        // the code is self-consistent: changing a constant moves both sides and
        // the test stays green. Confirmed — dropping money to Laravel's default
        // (8,2) left this assertion passing until it was pinned to D-68's
        // literal values.
        $expected = [
            'amount' => [18, 6],    // D-68 money
            'rate' => [18, 8],      // D-68 FX rate
            'percent' => [6, 3],    // D-68 percentage
            'qty' => [14, 4],       // D-68 quantity
        ];

        foreach ($expected as $column => [$total, $scale]) {
            /** @var object{data_type: string, numeric_precision: int, numeric_scale: int}|null $meta */
            $meta = DB::selectOne(
                'select data_type, numeric_precision, numeric_scale
                 from information_schema.columns
                 where table_name = ? and column_name = ?',
                ['precision_probes', $column],
            );

            self::assertNotNull($meta);
            self::assertSame('numeric', $meta->data_type, "{$column} must be NUMERIC, never a float (DB-07).");
            self::assertSame($total, (int) $meta->numeric_precision, "{$column} total digits");
            self::assertSame($scale, (int) $meta->numeric_scale, "{$column} decimal places");
        }
    }

    public function test_money_survives_a_round_trip_at_full_precision(): void
    {
        // The PO #226 tax, which is where six decimals came from. Stored as a
        // string and compared as a string: casting through float is what D-68
        // and DB-07 exist to prevent.
        DB::table('precision_probes')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid7(),
            'amount' => '1021.263012',
            'rate' => '1.00000000',
            'percent' => '14.000',
            'qty' => '1.0000',
        ]);

        $stored = DB::table('precision_probes')->value('amount');
        self::assertIsScalar($stored);
        self::assertSame('1021.263012', (string) $stored);
    }

    public function test_the_database_refuses_a_value_that_exceeds_the_column(): void
    {
        // A percentage column of (6,3) holds up to 999.999. Anything larger must
        // be rejected, not silently rounded — silent acceptance is what made
        // sqlite unusable for this project.
        //
        // Wrapped in a savepoint rather than left to expectException. PostgreSQL
        // aborts the surrounding transaction on error, so the rejection is
        // correct but tearDown's DROP then fails with "current transaction is
        // aborted" and the test reports the wrong cause. The savepoint contains
        // the damage and lets the assertion be about the constraint.
        $rejected = false;

        DB::statement('savepoint overflow_probe');
        try {
            DB::table('precision_probes')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid7(),
                'amount' => '1.000000',
                'rate' => '1.00000000',
                'percent' => '1000.000',
                'qty' => '1.0000',
            ]);
        } catch (QueryException $e) {
            $rejected = true;
            self::assertStringContainsString('numeric field overflow', $e->getMessage());
        } finally {
            DB::statement('rollback to savepoint overflow_probe');
        }

        self::assertTrue($rejected, 'The column must reject an out-of-range value, not round it.');
    }

    public function test_the_cast_scale_matches_the_column_scale(): void
    {
        // Column and cast are two numbers that must agree. Drift between them
        // stores a value exactly and reads it back rounded, which presents as a
        // calculation bug somewhere else entirely.
        // Pinned to D-68's literals for the same reason as above.
        self::assertSame('decimal:6', Precision::CAST_MONEY);
        self::assertSame('decimal:8', Precision::CAST_FX);
        self::assertSame('decimal:3', Precision::CAST_PERCENT);
        self::assertSame('decimal:4', Precision::CAST_QUANTITY);
        self::assertSame(6, Precision::MONEY_SCALE);
        self::assertSame(18, Precision::MONEY_TOTAL);
    }

    public function test_the_money_context_columns_are_kept_together(): void
    {
        // DB-06 and §5.6: amount, currency, captured rate, base amount. D-09
        // forbids recomputing a historical rate, which is only possible if it
        // was stored alongside the amount.
        Schema::create('money_context_probes', function (Blueprint $table): void {
            $table->standardId();
            $table->moneyWithContext('subtotal');
        });

        foreach (['subtotal', 'subtotal_currency', 'subtotal_fx_rate_at_time', 'subtotal_base'] as $column) {
            self::assertTrue(Schema::hasColumn('money_context_probes', $column), "DB-06 requires {$column}.");
        }

        Schema::dropIfExists('money_context_probes');
    }
}
