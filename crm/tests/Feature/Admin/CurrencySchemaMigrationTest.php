<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Modules\Admin\Domain\Money\ExchangeRate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Module 2, Point 1.2 — `currencies` and `fx_rates`.
 *
 * **A rate is history, so the table is append-only where it matters.** §5.3 and
 * the module's own acceptance criterion require that editing a rate leaves the
 * old one standing; `AP-06` files rates under append-only critical data. An
 * `UPDATE` that moves `rate` is therefore refused by the database, not by a
 * convention the API is trusted to keep. `deleted_at` stays writable — `DB-01`
 * requires the soft delete, and forbidding every `UPDATE` would forbid that too.
 *
 * **The rounding unit survives being switched off** (`D-65`, `RoundingRule`):
 * the column keeps its value and a separate boolean moves, so switching
 * rounding back on does not have to invent a unit.
 *
 * **Exactly one base currency.** §13 screen 5 names "base currency" in the
 * singular and `Currencies::base()` returns one; two of them would make
 * `base_amount` (`DB-06`) ambiguous, so the database refuses the second.
 */
final class CurrencySchemaMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = ['currencies', 'fx_rates'];

    private const MASTER_DOCUMENTATION = '/opt/crm/docs/CRM_Documentation_EN.md';

    private const UNIQUE_VIOLATION = '23505';

    private const CHECK_VIOLATION = '23514';

    private const FOREIGN_KEY_VIOLATION = '23503';

    /** Raised by the history trigger. */
    private const HISTORY_VIOLATION = 'FXH01';

    /** @return list<array{string}> */
    public static function tables(): array
    {
        return array_map(static fn (string $t): array => [$t], self::TABLES);
    }

    // ─────────────────────────────────────────────────────────── the block

    #[DataProvider('tables')]
    public function test_that_the_table_exists(string $table): void
    {
        self::assertTrue(Schema::hasTable($table), "Module 2 needs `{$table}`.");
    }

    #[DataProvider('tables')]
    public function test_that_the_table_carries_the_block_section_4_8_requires(string $table): void
    {
        self::assertFileExists(
            self::MASTER_DOCUMENTATION,
            'The master documentation is not mounted. This fails rather than skips: without it '
            .'the audit block below would only be agreeing with the migration that wrote it.',
        );

        $rule = null;
        foreach (file(self::MASTER_DOCUMENTATION) ?: [] as $line) {
            if (str_contains($line, 'DB-02')) {
                $rule = $line;
                break;
            }
        }

        self::assertNotNull($rule, '§4.8 no longer states DB-02.');

        foreach (['created_by', 'created_at', 'updated_by', 'updated_at'] as $column) {
            self::assertStringContainsString($column, $rule, "§4.8's DB-02 no longer names {$column}.");
            self::assertTrue(Schema::hasColumn($table, $column), "`{$table}` is missing {$column} (DB-02).");
        }

        self::assertTrue(Schema::hasColumn($table, 'deleted_at'), "`{$table}` is missing the DB-01 soft delete.");
    }

    /** `DB-07`. A rate held as a double is the defect this rule exists to name. */
    #[DataProvider('tables')]
    public function test_that_no_column_stores_a_float(string $table): void
    {
        self::assertTrue(Schema::hasTable($table), "`{$table}` does not exist, so this check proves nothing.");

        $floats = DB::select(
            'select column_name from information_schema.columns '
            .'where table_name = ? and data_type in (?, ?)',
            [$table, 'double precision', 'real'],
        );

        self::assertSame([], $floats, "`{$table}` has a float column — DB-07 forbids it.");
    }

    /**
     * `ExchangeRate::SCALE` is the domain's own answer, declared before this
     * table existed. The column has to hold what that class produces.
     */
    public function test_that_the_rate_column_holds_the_scale_the_domain_uses(): void
    {
        $scale = DB::scalar(
            'select numeric_scale from information_schema.columns '
            ."where table_name = 'fx_rates' and column_name = 'rate'",
        );

        self::assertSame(ExchangeRate::SCALE, $scale, 'fx_rates.rate cannot hold an ExchangeRate.');
    }

    // ──────────────────────────────────────────────────────────── currencies

    public function test_that_a_duplicate_live_code_is_refused(): void
    {
        $this->insertCurrency('EGP', isBase: true);

        self::assertSame(self::UNIQUE_VIOLATION, $this->refusedWith(fn () => $this->insertCurrency('EGP')));
    }

    public function test_that_an_archived_code_may_be_taken_again(): void
    {
        $this->insertCurrency('USD');
        DB::table('currencies')->where('code', 'USD')->update(['deleted_at' => now()]);

        $this->insertCurrency('USD');

        self::assertSame(2, DB::table('currencies')->where('code', 'USD')->count());
    }

    public function test_that_a_second_base_currency_is_refused(): void
    {
        $this->insertCurrency('EGP', isBase: true);

        self::assertSame(
            self::UNIQUE_VIOLATION,
            $this->refusedWith(fn () => $this->insertCurrency('USD', isBase: true)),
        );
    }

    /** `RoundingRule` refuses a non-positive unit; so must the column. */
    public function test_that_a_rounding_unit_of_zero_is_refused(): void
    {
        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->insertCurrency('EUR', unit: '0')),
        );
    }

    /** `D-65`: switching rounding off leaves the unit where it was. */
    public function test_that_rounding_may_be_switched_off_without_losing_the_unit(): void
    {
        $this->insertCurrency('EGP', isBase: true, unit: '1', enabled: false);

        // Asked of the database as booleans rather than read as columns and
        // cast: a cast off `mixed` is the escape hatch PHPStan level 10 exists
        // to refuse, and it would hide a column that came back as text.
        self::assertSame(false, DB::scalar("select rounding_enabled from currencies where code = 'EGP'"));
        self::assertSame(true, DB::scalar("select rounding_unit = 1 from currencies where code = 'EGP'"));
    }

    // ────────────────────────────────────────────────────────────── fx_rates

    public function test_that_a_rate_against_an_unknown_currency_is_refused(): void
    {
        $egp = $this->insertCurrency('EGP', isBase: true);

        self::assertSame(
            self::FOREIGN_KEY_VIOLATION,
            $this->refusedWith(fn () => $this->insertRate($egp, Uuid::uuid7()->toString())),
        );
    }

    public function test_that_a_rate_from_a_currency_to_itself_is_refused(): void
    {
        $egp = $this->insertCurrency('EGP', isBase: true);

        self::assertSame(self::CHECK_VIOLATION, $this->refusedWith(fn () => $this->insertRate($egp, $egp)));
    }

    public function test_that_a_non_positive_rate_is_refused(): void
    {
        $egp = $this->insertCurrency('EGP', isBase: true);
        $usd = $this->insertCurrency('USD');

        self::assertSame(
            self::CHECK_VIOLATION,
            $this->refusedWith(fn () => $this->insertRate($usd, $egp, rate: '0')),
        );
    }

    /**
     * The acceptance criterion, at the level that cannot be bypassed: a new
     * rate is a new row, and the old one is not editable into it.
     */
    public function test_that_an_existing_rate_may_not_be_edited(): void
    {
        $egp = $this->insertCurrency('EGP', isBase: true);
        $usd = $this->insertCurrency('USD');
        $this->insertRate($usd, $egp, rate: '48.50000000');

        self::assertSame(
            self::HISTORY_VIOLATION,
            $this->refusedWith(fn () => DB::table('fx_rates')->update(['rate' => '52.00000000'])),
        );
    }

    /** `DB-01` still has to work — the soft delete is an UPDATE. */
    public function test_that_a_rate_may_still_be_soft_deleted(): void
    {
        $egp = $this->insertCurrency('EGP', isBase: true);
        $usd = $this->insertCurrency('USD');
        $this->insertRate($usd, $egp);

        DB::table('fx_rates')->update(['deleted_at' => now()]);

        self::assertSame(1, DB::table('fx_rates')->whereNotNull('deleted_at')->count());
    }

    public function test_that_the_same_pair_may_be_priced_again_at_a_later_time(): void
    {
        $egp = $this->insertCurrency('EGP', isBase: true);
        $usd = $this->insertCurrency('USD');

        $this->insertRate($usd, $egp, rate: '48.50000000', effectiveFrom: '2026-08-01 00:00:00');
        $this->insertRate($usd, $egp, rate: '49.25000000', effectiveFrom: '2026-08-20 00:00:00');

        self::assertSame(2, DB::table('fx_rates')->count());
    }

    // ─────────────────────────────────────────────────────────────── DEV-03

    /**
     * `migrate:reset`, not `migrate:rollback --path`: `--path` does not choose
     * which migrations roll back. `Migrator::rollback()` takes the whole last
     * batch from the repository and uses the path only to resolve each entry to
     * a file, skipping what it cannot resolve ("Migration not found") — so under
     * `RefreshDatabase`, where the schema is a single batch, a one-file path
     * means "run this down() while every later table still stands", and any new
     * child foreign key turns this red (`SQLSTATE[2BP01]`) without this down()
     * having changed. Same shelf life, same fix, as `RbacSchemaMigrationTest`
     * and `AuditLogMigrationTest`. `DEV-03` asks that rollback work; rolling the
     * chain back and forward proves more of it, not less.
     */
    public function test_that_the_migration_rolls_back_and_forward_again(): void
    {
        Artisan::call('migrate:reset', ['--force' => true]);

        foreach (self::TABLES as $table) {
            self::assertFalse(Schema::hasTable($table), "down() left `{$table}` behind (DEV-03).");
        }

        self::assertSame(0, Artisan::call('migrate'));

        foreach (self::TABLES as $table) {
            self::assertTrue(Schema::hasTable($table), "`{$table}` did not come back.");
        }
    }

    // ───────────────────────────────────────────────────────────── helpers

    private function insertCurrency(
        string $code,
        bool $isBase = false,
        string $unit = '0.01',
        bool $enabled = true,
    ): string {
        $id = Uuid::uuid7()->toString();

        DB::table('currencies')->insert([
            'id' => $id,
            'code' => $code,
            'rounding_unit' => $unit,
            'rounding_enabled' => $enabled,
            'is_base' => $isBase,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function insertRate(
        string $from,
        string $to,
        string $rate = '48.50000000',
        string $effectiveFrom = '2026-08-01 00:00:00',
    ): void {
        DB::table('fx_rates')->insert([
            'id' => Uuid::uuid7()->toString(),
            'from_currency_id' => $from,
            'to_currency_id' => $to,
            'rate' => $rate,
            'effective_from' => $effectiveFrom,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** The SQLSTATE PostgreSQL answers with, or a failure if it accepted the write. */
    private function refusedWith(callable $write): string
    {
        try {
            $write();
        } catch (QueryException $e) {
            return (string) $e->getCode();
        }

        self::fail('The database accepted a write it should have refused.');
    }
}
