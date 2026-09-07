<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 7, Point 1.5 — `customers.is_tax_exempt`.
 *
 * ── A cross-module edit, declared rather than slipped in ───────────────────
 *
 * This adds a column to Module 3's table from inside Module 7. `CLAUDE.md`'s
 * module-isolation rule allows "migrations the module owns", and this is not
 * one of them — so it is recorded as a deliberate override, the way Module 6
 * recorded its own. The justification is that the column exists for `D-63`,
 * which is a quotation rule; Module 3 had no reason to add it and did not.
 *
 * ── `D-63` names the column, so nothing here is invented ───────────────────
 *
 * The decision's own text: *"The customer record carries the default
 * (`is_tax_exempt`); a new quotation inherits it and the preparer may override
 * per quotation."* The name, the owner and the direction of the default all
 * come from that line. §5.2 relies on it as well — "no tax line at all when
 * the customer is exempt or `tax_percent` is null".
 *
 * Without this column the acceptance criterion *"customer flagged tax-exempt
 * → no tax line at all, not a zero line"* cannot be implemented at all: there
 * is nothing to flag. Verified absent before writing this — `customers` had no
 * such column, and §4.2's field table never listed one.
 *
 * ── The default is `false`, and that is the safe direction ─────────────────
 *
 * Every customer that exists today was created under a system that taxed
 * normally, so `false` preserves their behaviour exactly. The opposite default
 * would silently exempt every existing customer and remove tax from the next
 * quotation raised for each of them — a financial change made by a migration,
 * which is precisely what a migration must not do.
 *
 * ── No index ───────────────────────────────────────────────────────────────
 *
 * `DB-09` names customer · owner · deal status · dates · entity codes; this is
 * none of them. Nothing documented filters or lists customers by exemption —
 * it is read one row at a time, when a quotation is created, through the
 * primary key. `customers` Point 1.1 recorded the governing rule: an index for
 * a query that does not exist still costs every write. `is_incomplete` earned
 * its partial index by having `D-31`'s filter behind it; this has no such
 * filter, and if a "tax-exempt customers" list is ever specified, an index is
 * a one-line migration then.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            // `D-63`. Not nullable: a customer is taxed or is not, and there is
            // no third state — unlike `quotations.tax_percent`, where NULL
            // means "no tax line" and is distinct from a rate of zero.
            $table->boolean('is_tax_exempt')->default(false);
        });
    }

    public function down(): void
    {
        // `DEV-03`. `migrate:rollback` must actually work before the point is
        // done, and dropping the column returns `customers` to Module 3's shape.
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('is_tax_exempt');
        });
    }
};
