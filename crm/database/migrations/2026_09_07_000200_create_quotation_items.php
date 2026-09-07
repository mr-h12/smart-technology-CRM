<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 7, Point 1.3 — `quotation_items`, the lines §5.1 prices.
 *
 * §5.1's formula block is the column list:
 *
 *     unit_cost         = supplier unit price (in supplier currency)
 *     unit_cost_base    = unit_cost × fx_rate_at_time            (D-09)
 *     margin_percent    = line margin; if empty, inherits the quotation's (D-03)
 *     unit_price        = unit_cost_base × (1 + margin_percent / 100)  (D-04)
 *     line_total        = unit_price × quantity
 *     line_cost         = unit_cost_base × quantity
 *
 * ── `supplier_quotation_item_id` is NOT NULL, and that is a rule not a habit ──
 *
 * `design/DATABASE.md` §10 lists it among "three columns the specification
 * requires but the earlier draft of this file omitted", and gives the reason:
 * §4.1's entity map already draws `SUPPLIER_QUOTATION_ITEMS ─► QUOTATION_ITEMS
 * "feeds price"`, but without the foreign key that edge does not exist in the
 * schema. §10.3 — "supplier price changed after the quotation was built →
 * warning + refresh prices" — is unimplementable without knowing which
 * supplier line fed which quotation line.
 *
 * It is NOT NULL because §5.6 forbids the alternative outright: "Product or
 * price missing at the supplier → **block save**." A nullable column would be
 * the schema holding open a state the specification closes.
 *
 * ── `margin_percent` is nullable, and NULL means *inherit*, never zero ─────
 *
 * `D-03`: "line margin; if empty, inherits the quotation margin". Three states
 * have to be distinguishable — a line margin of 30, a line margin of 0 (sold
 * at cost, which is a real instruction), and no line margin at all. A
 * `->default(0)` would collapse the third into the second and quietly price
 * every inheriting line at cost. `quotations.default_margin` is NOT NULL for
 * the matching half of this rule: the chain has somewhere to terminate.
 *
 * This is the acceptance criterion "quotation margin 20%, line margin 30% →
 * line uses 30%" made storable. Which of the two applies is Step 2's
 * arithmetic; this column only has to keep the question answerable.
 *
 * ── `moneyWithContext('unit_cost')`, used once in this module ──────────────
 *
 * `DB-06` and §5.6 require every amount to store "amount · currency ·
 * fx_rate_at_time · base_amount", and this is the one place in Module 7 where
 * that quartet is not redundant: the supplier's price arrives in the
 * supplier's currency and is converted into the quotation's. On the parent
 * table the same macro would have repeated one `currency_id` and a rate of
 * `1.0` across nine totals, so §5.6 is satisfied there by the row's own
 * currency instead.
 *
 * `unit_cost_currency` is a three-character code with **no** foreign key,
 * deliberately, and the asymmetry against `quotations.currency_id` is the
 * point: this is a *snapshot* of somebody else's document at a moment in time.
 * `currencies_code_unique_alive` is partial, so an archived code can be
 * reassigned to a new row — a foreign key would let an issued quotation's
 * source currency be silently redefined, which is exactly what `D-09`
 * ("changing an FX rate never affects an existing quotation") forbids.
 *
 * ── No `catalog_item_id` ───────────────────────────────────────────────────
 *
 * The product is reachable through `supplier_quotation_item_id`, which is NOT
 * NULL. A second path to the same fact, with nothing keeping the two equal,
 * lets a line name product X while the supplier line it prices names product
 * Y. §4.1 draws one edge here, not two.
 *
 * ── No CHECK on the arithmetic ─────────────────────────────────────────────
 *
 * Every §5.1 formula is multiplicative, and Point 1.2's docblock records why
 * that puts them out of reach: `NUMERIC(18,6) × NUMERIC(18,8)` yields scale
 * 14 against a scale-6 column, so `unit_cost_base = unit_cost ×
 * unit_cost_fx_rate_at_time` would reject correct rows. `D-68` calls the
 * quantization deliberate. The bounds below are what a CHECK *can* say.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotation_items', function (Blueprint $table): void {
            $table->standardColumns();

            $table->uuid('quotation_id');

            // §10, §10.3, §5.6. See the docblock — nullable is a state the
            // specification closes.
            $table->uuid('supplier_quotation_item_id');

            // §10 "display order", and design §13's `(quotation_id, line_no)`.
            $table->integer('line_no');

            // `DB-06` · §5.6's quartet: amount · currency · rate · base.
            $table->moneyWithContext('unit_cost');

            // `D-03`. NULL is "inherit", and it is not zero.
            $table->percentage('margin_percent', true);

            // `D-04`, and §5.1's two line figures.
            $table->money('unit_price');
            $table->quantity('quantity');
            $table->money('line_total');
            $table->money('line_cost');

            $table->standardActorForeignKeys();

            $table->foreign('quotation_id')->references('id')->on('quotations');
            $table->foreign('supplier_quotation_item_id')->references('id')->on('supplier_quotation_items');
        });

        // `supplier_quotation_items_quantity_positive` said the same one module
        // earlier: a line for nothing is not a line.
        DB::statement(
            'ALTER TABLE quotation_items ADD CONSTRAINT quotation_items_quantity_positive CHECK (quantity > 0)'
        );

        // A supplier may quote zero; none may quote less than nothing.
        // `supplier_quotation_items_price_not_negative` is the precedent.
        DB::statement(
            'ALTER TABLE quotation_items ADD CONSTRAINT quotation_items_unit_cost_not_negative '
            .'CHECK (unit_cost >= 0)'
        );

        // §5.1 allows a negative `margin_percent` — selling below cost happens
        // in tendering, and no source forbids it — but a negative *price* is
        // not a sale. The bound is placed here rather than on the margin
        // because this is the figure the customer is charged.
        DB::statement(
            'ALTER TABLE quotation_items ADD CONSTRAINT quotation_items_unit_price_not_negative '
            .'CHECK (unit_price >= 0)'
        );

        // `fx_rates_rate_positive` said it about the live rate; a captured copy
        // is no less required to be a real rate. A zero here would make
        // `unit_cost_base` zero and price the line at pure margin on nothing.
        DB::statement(
            'ALTER TABLE quotation_items ADD CONSTRAINT quotation_items_fx_rate_positive '
            .'CHECK (unit_cost_fx_rate_at_time > 0)'
        );

        // design §13, all three partial: `DB-01` soft-deletes and a list that
        // counts archived lines is a list of the wrong set.
        DB::statement(
            'CREATE INDEX quotation_items_by_quotation ON quotation_items (quotation_id) WHERE deleted_at IS NULL'
        );
        // §10.3 scans the other direction — which quotations does this supplier
        // line feed, so a price change can warn them.
        DB::statement(
            'CREATE INDEX quotation_items_by_supplier_line ON quotation_items (supplier_quotation_item_id) '
            .'WHERE deleted_at IS NULL'
        );
        // §10 "stable ordering". Deliberately **not** unique: `DB-01` would
        // force a partial unique index, a partial unique index cannot be
        // DEFERRABLE, and swapping two lines' numbers would then need a
        // temporary value. Ties break on `(line_no, id)`.
        DB::statement(
            'CREATE INDEX quotation_items_in_order ON quotation_items (quotation_id, line_no) '
            .'WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        // `DEV-03`. The constraints and all three indexes go with the table.
        Schema::dropIfExists('quotation_items');
    }
};
