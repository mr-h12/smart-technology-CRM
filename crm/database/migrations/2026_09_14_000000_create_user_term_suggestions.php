<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 7, Point 6.8 — `user_term_suggestions`, the table the build plan lists
 * for SmartTermInput (`MVP_Build_Plan` Module 7) and Step 6's Q5 shapes: one
 * row per person, per field, per distinct term, filled by the server whenever a
 * quotation is saved and read back by the builder as a suggestion.
 *
 * `(user_id, field, term)` is UNIQUE because a repeat is a touch of
 * `last_used_at`, not a second row — the upsert relies on the key. `field` is
 * one of the three term columns on `quotations` (`payment_terms`, `warranty`,
 * `delivery_terms`); the Form Request holds it to those, and the column is a
 * plain string rather than an enum table because it names a column, not a
 * business value a person manages.
 *
 * `standardColumns()` as the point's approved text says (`DB-01`, `DB-02`),
 * even though a suggestion is written by the server: it is a row a person may
 * one day want retired, and retiring is a soft delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_term_suggestions', function (Blueprint $table): void {
            $table->standardColumns();
            $table->uuid('user_id');
            $table->string('field', 32);
            // 500 characters: a suggestion is a term somebody would type again,
            // not a page; the writer skips anything longer rather than fail the
            // quotation save on this table's UNIQUE index.
            $table->string('term', 500);
            $table->timestampTz('last_used_at');

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->standardActorForeignKeys();
            $table->unique(['user_id', 'field', 'term']);
            // The read: own rows for one field, newest first.
            $table->index(['user_id', 'field', 'last_used_at']);
        });
    }

    public function down(): void
    {
        // DEV-03. The UNIQUE, the index and the foreign keys go with the table.
        Schema::dropIfExists('user_term_suggestions');
    }
};
