<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 2's money configuration — `currencies` and `fx_rates`.
 *
 * **The rounding unit and its switch are two columns, not one.** `D-52` makes
 * the unit per-currency and `D-65` makes rounding optional, and `RoundingRule`
 * already models them separately: switching rounding off keeps the unit so that
 * switching it back on does not have to invent one. A nullable unit meaning
 * "off" would lose that and would make `NULL` do the work of a boolean.
 *
 * **Exactly one base currency, enforced by a partial unique index.** §13 screen
 * 5 names "base currency" in the singular and `Currencies::base()` returns one;
 * with two, `DB-06`'s `base_amount` has no defined meaning. The index covers
 * only live rows that are base, so archiving one and naming another works.
 *
 * **A rate is history.** §5.3 and this module's acceptance criterion require an
 * edit to leave the old rate standing, and `AP-06` files rates under append-only
 * critical data. Rather than trust every future writer, the database refuses an
 * `UPDATE` that moves `rate`, either currency, or `effective_from` — a new price
 * is a new row. `deleted_at` and the actor columns stay writable, because
 * `DB-01`'s soft delete *is* an `UPDATE` and blocking every one would block it.
 *
 * `fx_rates` therefore carries no unique index on the pair alone: the same pair
 * priced at two different times is the feature, not a duplicate.
 */
return new class extends Migration
{
    private const HISTORY_FUNCTION = 'fx_rates_are_history';

    /**
     * A custom SQLSTATE, the way `make_audit_log_append_only` uses `AUD03`.
     * PostgreSQL passes an unrecognised five-character class straight through,
     * so a caller can tell this refusal from a constraint violation.
     */
    private const HISTORY_ERRCODE = 'FXH01';

    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table): void {
            $table->standardColumns();

            // ISO 4217 is three characters, always. `CurrencyCode` declares the
            // three this system starts with; the table is what makes adding a
            // fourth a settings change rather than a deployment (AP-08).
            $table->char('code', 3);

            // D-68 money precision. §5.3's units are 1 and 0.01, but the column
            // is the same NUMERIC every amount uses — a unit is an amount.
            $table->money('rounding_unit');

            // D-65. Kept beside the unit, never encoded as a null unit.
            $table->boolean('rounding_enabled');

            // DB-06: base_amount needs a base to be an amount of.
            $table->boolean('is_base');
        });

        // DB-01 soft-deletes, so a plain UNIQUE would let one archived currency
        // reserve `USD` forever. Laravel ignores unique()->where() silently.
        DB::statement('CREATE UNIQUE INDEX currencies_code_unique_alive ON currencies (code) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX currencies_one_base_alive ON currencies (is_base) WHERE is_base AND deleted_at IS NULL');

        // RoundingRule refuses a non-positive unit (Decimal::positive). The
        // database says the same thing, so a direct INSERT cannot get past it.
        DB::statement('ALTER TABLE currencies ADD CONSTRAINT currencies_rounding_unit_positive CHECK (rounding_unit > 0)');

        Schema::create('fx_rates', function (Blueprint $table): void {
            $table->standardColumns();

            // Keyed on the currency row, not on the code: DB-04 wants a real
            // foreign key, and the code's uniqueness is partial — a partial
            // index cannot be one's target.
            $table->foreignUuid('from_currency_id')->constrained('currencies')->restrictOnDelete();
            $table->foreignUuid('to_currency_id')->constrained('currencies')->restrictOnDelete();

            // D-68 FX precision — the scale ExchangeRate::SCALE already uses.
            $table->fxRate('rate');

            // D-09 fixes a quotation's rate at creation, which only works if
            // the rate says when it started applying. DB-08: stored UTC.
            $table->timestampTz('effective_from');
        });

        DB::statement('ALTER TABLE fx_rates ADD CONSTRAINT fx_rates_rate_positive CHECK (rate > 0)');
        DB::statement('ALTER TABLE fx_rates ADD CONSTRAINT fx_rates_distinct_currencies CHECK (from_currency_id <> to_currency_id)');

        // One price per pair per moment. Partial for the DB-01 reason above.
        DB::statement(
            'CREATE UNIQUE INDEX fx_rates_pair_moment_unique_alive '
            .'ON fx_rates (from_currency_id, to_currency_id, effective_from) WHERE deleted_at IS NULL'
        );

        // The reading path is "the rate for this pair as of now", newest first.
        DB::statement('CREATE INDEX fx_rates_pair_recent ON fx_rates (from_currency_id, to_currency_id, effective_from DESC)');

        // CREATE OR REPLACE, not CREATE: `migrate:fresh` drops tables, and a
        // function is not a table — it outlives the trigger attached to it, and
        // a plain CREATE fails the second time the suite runs (42723). The
        // lesson is `make_audit_log_append_only`'s, paid for once already.
        DB::statement(sprintf(<<<'SQL'
            CREATE OR REPLACE FUNCTION %s() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.rate IS DISTINCT FROM OLD.rate
                    OR NEW.from_currency_id IS DISTINCT FROM OLD.from_currency_id
                    OR NEW.to_currency_id IS DISTINCT FROM OLD.to_currency_id
                    OR NEW.effective_from IS DISTINCT FROM OLD.effective_from
                THEN
                    RAISE EXCEPTION
                        'an exchange rate is history: record a new rate instead of editing this one (AP-06, section 5.3)'
                        USING ERRCODE = '%s';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL, self::HISTORY_FUNCTION, self::HISTORY_ERRCODE));

        DB::statement(sprintf(
            'CREATE TRIGGER fx_rates_no_rewrite BEFORE UPDATE ON fx_rates
             FOR EACH ROW EXECUTE FUNCTION %s()',
            self::HISTORY_FUNCTION,
        ));
    }

    public function down(): void
    {
        // DEV-03. The trigger goes with its table; the function does not, so it
        // is dropped by name or it survives the rollback.
        Schema::dropIfExists('fx_rates');
        DB::statement('DROP FUNCTION IF EXISTS '.self::HISTORY_FUNCTION.'()');
        Schema::dropIfExists('currencies');
    }
};
