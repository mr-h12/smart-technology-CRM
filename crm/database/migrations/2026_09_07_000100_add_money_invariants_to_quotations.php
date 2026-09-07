<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Module 7, Point 1.2 — §5.2's money identities, as constraints.
 *
 * Point 1.1 created the columns; this migration makes the arithmetic between
 * them a fact the database enforces rather than a promise the write path
 * keeps. It is a separate migration for the reason the point list gives: these
 * six lines are the highest-risk content in the project, and a reviewer should
 * read them without thirty column definitions in the diff.
 *
 * ── Additive identities only, and that is a hard rule ──────────────────────
 *
 * §5.2's chain is additive from `tax_base` onward, and in PostgreSQL the sum
 * or difference of two `NUMERIC(18,6)` values is exact at scale 6 — so these
 * comparisons are exact, not approximate.
 *
 * The **multiplicative** steps are deliberately absent and must stay absent.
 * `discount_amount = subtotal × discount_percent / 100`,
 * `tax_amount = tax_base × tax_percent / 100`, and every §5.1 line formula
 * produce more than six decimals in PostgreSQL — `NUMERIC(18,6) * NUMERIC(6,3)`
 * yields scale 9, and division yields more still — while the stored column is
 * scale 6. `D-68` calls that quantization deliberate: "Arithmetic runs in
 * BCMath at higher precision; only the persisted snapshot is at scale 6." A
 * CHECK on a multiplicative identity would therefore reject **correct** rows,
 * and would do it only against real data, never against a round test fixture.
 * `subtotal = Σ line_total` is cross-row and no CHECK can express it at all;
 * that one belongs to Step 2's engine and its tests.
 *
 * ── What each identity buys ────────────────────────────────────────────────
 *
 * `tax_base = subtotal − discount_amount` is `D-64` and `D-62` in one line.
 * The discount comes off **before** tax, and `additional_total` is absent from
 * the right-hand side — so a row that taxes delivery is refused by the
 * database, not merely by a test. This is the acceptance criterion "subtotal
 * 10,000, delivery 1,000, discount 1%, tax 14% → tax base 9,900" expressed as
 * a constraint: a row claiming 10,800 cannot be stored.
 *
 * `total_before_round = net_amount + COALESCE(tax_amount, 0)` carries `D-63`.
 * An exempt quotation has `tax_amount IS NULL`, and NULL must add nothing
 * rather than make the whole comparison NULL — which is what a bare `+` would
 * do, silently passing the constraint for every exempt row.
 *
 * `final_total = total_before_round + rounding_diff` is definitional: §5.2
 * writes `rounding_diff = final_total − total_before_round`. It turns the
 * acceptance row "total 1234.67 EGP → final total 1235, rounding_diff 0.33"
 * into something the database can refuse to get wrong.
 *
 * `rounding_enabled OR rounding_diff = 0` is `D-65`: with rounding off the
 * total keeps full precision, so there is no difference to record.
 *
 * `(tax_percent IS NULL) = (tax_amount IS NULL)` is the other half of `D-63`.
 * A percentage with no amount, or an amount with no percentage, is a tax line
 * that cannot be explained on a PDF. `supplier_quotations_price_needs_currency`
 * is the same shape one module earlier.
 */
return new class extends Migration
{
    /**
     * The constraint name and its expression. Named so `down()` cannot drift
     * from `up()` — a second hand-written list is the duplicate the waste
     * audit exists to catch.
     *
     * @var array<string, string>
     */
    private const INVARIANTS = [
        // `D-64` (discount first) and `D-62` (additional items outside the base).
        'quotations_tax_base_follows_discount' => 'tax_base = subtotal - discount_amount',

        // §5.2: revenue excluding tax. Additional items enter here and nowhere earlier.
        'quotations_net_amount_includes_additional' => 'net_amount = subtotal + additional_total - discount_amount',

        // §5.2, with `D-63`'s NULL treated as "no tax line", not as "unknown".
        'quotations_total_before_round_adds_tax' => 'total_before_round = net_amount + COALESCE(tax_amount, 0)',

        // §5.2's definition of `rounding_diff`, read as an identity.
        'quotations_final_total_carries_rounding_diff' => 'final_total = total_before_round + rounding_diff',

        // `D-65`: rounding off means full precision and nothing to record.
        'quotations_rounding_diff_zero_when_disabled' => 'rounding_enabled OR rounding_diff = 0',

        // `D-63`: a rate and its amount arrive together or not at all.
        'quotations_tax_amount_matches_tax_percent' => '(tax_percent IS NULL) = (tax_amount IS NULL)',
    ];

    public function up(): void
    {
        foreach (self::INVARIANTS as $name => $expression) {
            DB::statement("ALTER TABLE quotations ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }

    public function down(): void
    {
        // `DEV-03`. Reversing leaves Point 1.1's table exactly as it was.
        foreach (array_keys(self::INVARIANTS) as $name) {
            DB::statement("ALTER TABLE quotations DROP CONSTRAINT {$name}");
        }
    }
};
