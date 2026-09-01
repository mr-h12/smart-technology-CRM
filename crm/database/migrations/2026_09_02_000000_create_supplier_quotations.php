<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 6, Point 1.1 — `supplier_quotations`, the table §7.2 publishes.
 *
 * ── §4.1 draws exactly one line into this entity ───────────────────────────
 *
 * "Supplier ──► Supplier Quotation ← standalone entity (D-51)", and beneath
 * it "deal_id (optional — nullable)". So `supplier_id` is `NOT NULL` for the
 * reason `deals.customer_id` is: a supplier quotation with no supplier is not
 * a smaller version of one, it is not the row §4.1 describes. `deal_id` is
 * nullable because the entity map says so **and** because `D-51` states the
 * purpose — the offer is reusable, "available to any deal", which a required
 * link would forbid.
 *
 * ── The currency is an id, not a three-letter code ─────────────────────────
 *
 * §7.2 writes "total_price · currency", and the obvious reading is `char(3)`.
 * It is wrong here: `currencies.code` is unique only among live rows —
 * `currencies_code_unique_alive` is a **partial** index, deliberately, so an
 * archived currency does not reserve `USD` forever — and PostgreSQL cannot
 * point a foreign key at a partial unique index. `fx_rates` already made this
 * choice for the same reason (`from_currency_id`, `to_currency_id`); this
 * table follows it rather than inventing a second convention.
 *
 * ── Both money columns, or neither ─────────────────────────────────────────
 *
 * §7.2 marks nothing "Required", and this migration reads that silence the
 * way Module 5 Point 1.1 read §4.3's: nullable unless a source forces
 * otherwise. But a price with no currency is not a smaller fact — it is a
 * number that cannot be compared, converted or printed, and `D-24`'s
 * conversion has nothing to convert *from*. The CHECK below therefore allows
 * both or neither and refuses one alone. Whether the write path *fills* them
 * — a figure the person enters, or the sum of Point 1.2's items — is a
 * question for Step 2 and is deliberately not decided by this column.
 *
 * ── Three of §7.2's rows are not columns here ──────────────────────────────
 *
 * `pdf_file` is `supplier_quotation_files` (`D-71`: a column cannot carry the
 * two foreign keys that decision exists to provide). `Line items` is Point
 * 1.2's table. `entered_by` is `created_by`, which `standardColumns()` already
 * puts on every table under `DB-02` — a second column for the same fact is
 * exactly the duplicate the waste audit exists to catch.
 *
 * ── `supplier_quotation_files.supplier_quotation_id`, the debt Module 0 recorded ──
 *
 * `2026_08_22_000000_create_files_and_attachment_pivots` created that pivot
 * with its column and index but no key, because a key cannot reference a table
 * that does not exist. Its docblock names the parent's own migration as the
 * one that closes it, and `FilesMigrationTest`'s owed list fails from the
 * moment this table exists until the constraint is added. Closed here, in the
 * same migration that creates what it was waiting for — `deals` set the
 * precedent one module earlier.
 *
 * ── `DB-09`'s categories, as this table has them ───────────────────────────
 *
 * `DB-09` names "customer · owner · deal status · dates · entity codes". This
 * table has no customer, no owner and no status: §3.6 grants every role `All`
 * ("a shared screen — not restricted by ownership"), so there is no scope
 * column to index and no `scopeIndex()` here. What it does have is the
 * supplier (the "Linked Quotations" read, Step 4), the deal (a deal's offers),
 * the offer date (the list's default order) and the code, which `unique()`
 * already indexes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_quotations', function (Blueprint $table): void {
            $table->standardColumns();

            // §7.2 "SQ-2026-0001", and §4.5's prefix table. NOT NULL and
            // UNIQUE for what an offer always has once it exists; the
            // generator that fills it is Point 1.3's.
            $table->string('code', 20)->unique();

            $table->uuid('supplier_id');

            // `D-51`. The whole point of the entity.
            $table->uuid('deal_id')->nullable();

            // `money()` rather than `decimal()` — `D-68` precision, which
            // Laravel's default (8,2) would silently truncate.
            $table->money('total_price', true);
            $table->uuid('currency_id')->nullable();

            $table->date('offer_date')->nullable();
            $table->date('valid_until')->nullable();

            $table->text('notes')->nullable();

            $table->standardActorForeignKeys();

            $table->foreign('supplier_id')->references('id')->on('suppliers');
            // `DB-01` forbids physically deleting a deal, so this cannot fire
            // in practice; it is stated for the repair case the rules do allow,
            // and nulling it returns the offer to the standalone state `D-51`
            // says it may always occupy.
            $table->foreign('deal_id')->references('id')->on('deals')->nullOnDelete();
            $table->foreign('currency_id')->references('id')->on('currencies')->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE supplier_quotations ADD CONSTRAINT supplier_quotations_price_needs_currency '
            .'CHECK ((total_price IS NULL) = (currency_id IS NULL))'
        );

        DB::statement(
            'CREATE INDEX supplier_quotations_by_supplier ON supplier_quotations (supplier_id) WHERE deleted_at IS NULL'
        );
        DB::statement(
            'CREATE INDEX supplier_quotations_by_deal ON supplier_quotations (deal_id) WHERE deleted_at IS NULL'
        );
        DB::statement(
            'CREATE INDEX supplier_quotations_by_offer_date ON supplier_quotations (offer_date) WHERE deleted_at IS NULL'
        );

        // The debt `2026_08_22_000000_create_files_and_attachment_pivots`
        // recorded: the column and its index existed, the key could not.
        Schema::table('supplier_quotation_files', function (Blueprint $table): void {
            $table->foreign('supplier_quotation_id')->references('id')->on('supplier_quotations');
        });
    }

    public function down(): void
    {
        // The key on the pivot goes first — the parent cannot be dropped while
        // it is referenced.
        Schema::table('supplier_quotation_files', function (Blueprint $table): void {
            $table->dropForeign(['supplier_quotation_id']);
        });

        Schema::dropIfExists('supplier_quotations');
    }
};
