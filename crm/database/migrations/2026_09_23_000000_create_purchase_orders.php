<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 10 · 1.6 — the customer's purchase order (§4.6, `D-12`, `D-53`),
 * written in the acceptance's transaction (Q5). Two numbers: `po_number` is
 * ours (`PO-YYYY-NNNN`, §4.7, allocated by `DocumentNumberAllocator`),
 * `customer_po_reference` is the customer's own. The attachment is not a
 * column: it is `purchase_order_files` (`D-71`), uploaded after acceptance.
 *
 * ── One alive order per quotation ──────────────────────────────────────────
 *
 * `accepted` is terminal, so a quotation is accepted once; the partial unique
 * index is the database's own answer to a second order, as
 * `quotations_version_unique_alive` is to a second copy (`DB-03`).
 *
 * ── `purchase_order_files.purchase_order_id`, the debt Module 0 recorded ────
 *
 * The pivot has carried the column and its index since
 * `2026_08_22_000000_create_files_and_attachment_pivots`; the key waited for
 * this table. Closed here, as `deals` and `supplier_quotations` closed theirs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->standardId();
            $table->uuid('quotation_id');
            $table->string('po_number', 32)->unique();
            $table->string('customer_po_reference', 255);
            $table->date('po_date');
            $table->standardAudit();

            $table->standardActorForeignKeys();
            $table->foreign('quotation_id')->references('id')->on('quotations');
        });

        DB::statement(
            'ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_reference_not_blank '
            ."CHECK (btrim(customer_po_reference) <> '')"
        );
        DB::statement(
            'CREATE UNIQUE INDEX purchase_orders_quotation_unique_alive '
            .'ON purchase_orders (quotation_id) WHERE deleted_at IS NULL'
        );

        Schema::table('purchase_order_files', function (Blueprint $table): void {
            $table->foreign('purchase_order_id')->references('id')->on('purchase_orders');
        });
    }

    public function down(): void
    {
        // The key on the pivot goes first — the parent cannot be dropped while
        // it is referenced.
        Schema::table('purchase_order_files', function (Blueprint $table): void {
            $table->dropForeign(['purchase_order_id']);
        });

        Schema::dropIfExists('purchase_orders');
    }
};
