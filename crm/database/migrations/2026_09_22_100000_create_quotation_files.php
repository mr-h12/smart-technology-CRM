<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 9, Point 1.3 — `quotation_files`, where a generated customer PDF is
 * stored against its quotation (§14.6, `D-71`).
 *
 * ── Why a fifth pivot ──────────────────────────────────────────────────────
 *
 * `2026_08_22_000000_create_files_and_attachment_pivots` shipped four —
 * `deal_files`, `supplier_quotation_files`, `purchase_order_files`,
 * `report_files` — and not this one, so the module whose stored PDF is its
 * headline feature had nowhere to put it. It is built here on their exact
 * shape: composite primary key, `file_id` index for `J-11`, `file_id`
 * cascade. `AttachmentParent::Quotation` names it; nothing else maps it.
 *
 * ── Its parent key arrives with it ─────────────────────────────────────────
 *
 * The four had to wait for their parents; `quotations` already exists, so
 * this pivot carries its foreign key from the first migration — the state
 * `deals` and `supplier_quotations` reached only when they closed the debt
 * Module 0 recorded. Nothing is owed afterwards.
 *
 * ── It creates a table and alters none ─────────────────────────────────────
 *
 * `quotations` is Module 7's table and is not touched: a key *from* the pivot
 * needs nothing on the parent. That is what keeps Module 9 inside its own
 * boundary, as Module 6's nullable `deal_id` did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotation_files', function (Blueprint $table): void {
            $table->uuid('quotation_id');
            $table->uuid('file_id');

            // The key refuses a second attach of one file, where a pre-check
            // would lose a race (the four Module 0 pivots' reasoning).
            $table->primary(['quotation_id', 'file_id']);

            // J-11's reverse lookup; the primary key cannot serve it.
            $table->index('file_id');

            // Cascade on the file only — the reason Module 0 gives: DB-01 never
            // physically deletes a file row, and a repair must not leave a link
            // to nothing. The quotation side has no cascade: a quotation is
            // soft-deleted, and a PDF sent to a customer outlives its archiving.
            $table->foreign('file_id')->references('id')->on('files')->cascadeOnDelete();
            $table->foreign('quotation_id')->references('id')->on('quotations');
        });
    }

    public function down(): void
    {
        // Both keys belong to the table and go with it; `quotations` and
        // `files` are untouched.
        Schema::dropIfExists('quotation_files');
    }
};
