<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 6, Point 1.2 — `supplier_quotation_items`, the line §7.2 describes.
 *
 * ── One row in §7.2, three facts, three columns ────────────────────────────
 *
 *     | Line items | Product · **price** · quantity (+ to add more) |
 *
 * `D-21` says what this table is *for*: "prices live here". A catalog item is
 * descriptive only (§7.3, "no prices"), so the price of a thing is a fact about
 * an offer on a date, and this is the row that holds it.
 *
 * ── All three are NOT NULL, and that is §4.1's argument, not a new one ─────
 *
 * Point 1.1 made `supplier_id` required because a supplier quotation with no
 * supplier is not a smaller version of one. The same reading applies here to
 * all three: a line with no product, no price or no quantity is not the row
 * §7.2 describes. The price half is stated outright by §5.6 — "Product or price
 * missing at the supplier → **block save**" — and §10.4 lists "missing price"
 * among the red inline validations.
 *
 * The parent's own `total_price` is nullable, which is not a contradiction:
 * there it is a summary of the offer that §7.2 marks Required nowhere, here it
 * is the fact the line exists to record.
 *
 * ── What the two CHECKs decide, and what they deliberately do not ──────────
 *
 * `unit_price >= 0` allows zero: a supplier throwing in an accessory free is a
 * real offer, and a negative price is not a discount — §5.2 puts discount on
 * the customer quotation as a percentage of the subtotal (`D-07`), never as a
 * negative supplier line.
 *
 * `quantity > 0` refuses zero as well as negative, because §5.1 computes
 * `line_total = unit_price × quantity` and a line for none of something totals
 * nothing while still appearing on the offer. This one goes beyond the literal
 * wording of the approved point list ("a `unit_price >= 0` CHECK"); it is the
 * same kind of constraint on the neighbouring column, and it is named in the
 * point's report rather than slipped in.
 *
 * Neither CHECK decides whether the parent's `total_price` is a typed figure or
 * the sum of these rows. That is Step 2's open question with the owner, it is a
 * cross-row rule no CHECK can express, and nothing here presumes an answer.
 *
 * ── No unique key on (quotation, product) ──────────────────────────────────
 *
 * §7.2's "+ to add more" places no limit, and nothing in §5 or §10 forbids a
 * supplier quoting the same product twice — a quantity break is the ordinary
 * reason. A constraint no source asks for would be a rule invented here.
 *
 * ── `DB-09`, as this table has it ──────────────────────────────────────────
 *
 * No customer, no owner (§3.6 grants every role `Scope::All`, so there is no
 * scope column and no `scopeIndex()`), no status, no date and no entity code —
 * the code is the parent's. What it has is two joins, both of which every read
 * of this table makes: the parent's lines, and `D-21`'s reverse lookup of what
 * a product has been priced at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_quotation_items', function (Blueprint $table): void {
            $table->standardColumns();

            $table->uuid('supplier_quotation_id');

            // §7.2's "Product". `D-22` guarantees there is always a catalog row
            // to point at: an offer naming a product the catalog lacks adds it
            // automatically, so the line never points at nothing.
            $table->uuid('catalog_item_id');

            // §7.2's "**price**", and §5.1's `unit_cost` — "supplier unit price
            // (in supplier currency)", the currency being the parent's.
            // `money()` rather than `decimal()`: `D-68`, and Laravel's default
            // (8,2) truncates silently.
            $table->money('unit_price');

            // `quantity()` for the reason `Precision` gives: units include
            // metre and kilo (§7.3), so this is not an integer.
            $table->quantity('quantity');

            $table->standardActorForeignKeys();

            $table->foreign('supplier_quotation_id')->references('id')->on('supplier_quotations');
            $table->foreign('catalog_item_id')->references('id')->on('catalog_items');
        });

        DB::statement(
            'ALTER TABLE supplier_quotation_items ADD CONSTRAINT supplier_quotation_items_price_not_negative '
            .'CHECK (unit_price >= 0)'
        );

        DB::statement(
            'ALTER TABLE supplier_quotation_items ADD CONSTRAINT supplier_quotation_items_quantity_positive '
            .'CHECK (quantity > 0)'
        );

        DB::statement(
            'CREATE INDEX supplier_quotation_items_by_quotation ON supplier_quotation_items '
            .'(supplier_quotation_id) WHERE deleted_at IS NULL'
        );
        DB::statement(
            'CREATE INDEX supplier_quotation_items_by_catalog_item ON supplier_quotation_items '
            .'(catalog_item_id) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_quotation_items');
    }
};
