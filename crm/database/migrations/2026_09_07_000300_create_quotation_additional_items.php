<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 7, Point 1.4 — `quotation_additional_items`: delivery, installation
 * and the like.
 *
 * ── Small, and not deferrable ──────────────────────────────────────────────
 *
 * `design/DATABASE.md` §10 says so in as many words: the table "carries
 * `additional_total`, and `OD-01`/`D-62` turn on the fact that these lines sit
 * outside the tax base. It ships with Module 7, not after it." Four columns,
 * and the most consequential financial rule in the project rests on them.
 *
 * ── These lines never enter `tax_base` ─────────────────────────────────────
 *
 * `OD-01` was opened to ask whether additional items are taxable and closed
 * **no** (`D-62`, reconfirmed 2026-08-19). §5.2 places them accordingly:
 *
 *     tax_base   = subtotal − discount_amount        ← additional items absent
 *     net_amount = subtotal + additional_total − discount_amount
 *
 * So an additional item enters the customer's total and never the tax base. A
 * calculation that sums these into the base overcharges the customer, which is
 * the exact defect `OD-01` exists to prevent — and Point 1.2 already made that
 * a database fact: `quotations_tax_base_follows_discount` has no
 * `additional_total` term, so a parent row that taxed delivery cannot be
 * stored. This table's job is to hold the lines; the rule that keeps them
 * untaxed lives one table up, where the totals are.
 *
 * ── No currency column ─────────────────────────────────────────────────────
 *
 * `D-09` gives the quotation one currency and §10 says the amount is "in the
 * quotation currency". A currency here could disagree with its parent's, and
 * nothing would reconcile them. Contrast `quotation_items.unit_cost_currency`,
 * which names a *supplier's* currency and therefore has something real to say.
 *
 * ── `amount >= 0`, because the back door is the interesting case ───────────
 *
 * A negative additional item is a discount that skips `D-07` entirely — it
 * would reduce `net_amount` without touching `discount_percent`,
 * `discount_amount` or the tax base, so the discount §5.2 subtracts *before*
 * tax could be re-introduced *after* it under another name. `D-07` makes a
 * discount a percentage of the subtotal, and this constraint keeps it the only
 * kind.
 *
 * ── `description` is a label, not a paragraph ──────────────────────────────
 *
 * §10 calls it "free text — the label the customer sees", so it is sized like
 * the other names in the schema (`customers.name`, `catalog_items.name` are
 * both `string(255)`) rather than like `quotations.payment_terms`, which is a
 * `text` clause. The stated ceiling: a line label longer than 255 characters
 * will not fit, and if a real quotation ever needs one, the fix is a widening
 * migration, not a `text` column added speculatively now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotation_additional_items', function (Blueprint $table): void {
            $table->standardColumns();

            $table->uuid('quotation_id');

            // §10: "the label the customer sees". It reaches the PDF, so it is
            // required — a blank line on a customer document is a defect.
            $table->string('description', 255);

            // §10: `NUMERIC(18,6)`, in the quotation's currency. `money()`
            // rather than `decimal()` — `D-68`, which Laravel's (8,2) default
            // would silently truncate.
            $table->money('amount');

            // §10 "display order", matching `quotation_items.line_no`.
            $table->integer('line_no');

            $table->standardActorForeignKeys();

            $table->foreign('quotation_id')->references('id')->on('quotations');
        });

        // The same reading `customers_name_not_blank` and
        // `catalog_items_name_not_blank` gave a mandatory text field: a string
        // of spaces is not a label.
        DB::statement(
            'ALTER TABLE quotation_additional_items ADD CONSTRAINT quotation_additional_items_description_not_blank '
            ."CHECK (btrim(description) <> '')"
        );

        // See the docblock: a negative additional item is a discount that
        // bypasses `D-07` and lands on the wrong side of the tax base.
        DB::statement(
            'ALTER TABLE quotation_additional_items ADD CONSTRAINT quotation_additional_items_amount_not_negative '
            .'CHECK (amount >= 0)'
        );

        // Both partial — `DB-01` soft-deletes, and a total that counts archived
        // lines is the wrong total.
        DB::statement(
            'CREATE INDEX quotation_additional_items_by_quotation ON quotation_additional_items (quotation_id) '
            .'WHERE deleted_at IS NULL'
        );
        // Stable ordering, and non-unique for the reason Point 1.3 recorded: a
        // partial unique index cannot be DEFERRABLE, so a reorder would need a
        // temporary value.
        DB::statement(
            'CREATE INDEX quotation_additional_items_in_order ON quotation_additional_items (quotation_id, line_no) '
            .'WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        // `DEV-03`. The constraints and both indexes go with the table.
        Schema::dropIfExists('quotation_additional_items');
    }
};
