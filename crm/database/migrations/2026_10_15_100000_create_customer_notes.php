<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * F-50 · 3 — `D-122`: a customer's notes are separate entries, each with an
 * optional date that only sorts the list (`D-15` stays deferred).
 *
 * Each customer's non-blank `notes` text is copied into one undated note. The
 * old column is left as it is: data is never dropped (`DB-01`), and an applied
 * migration is never edited. `down()` drops the table, and with it the copies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_notes', function (Blueprint $table): void {
            $table->standardColumns();

            $table->uuid('customer_id');
            $table->text('body');
            // A calendar date, no hour: the label a person gives the note.
            $table->date('note_date')->nullable();

            $table->standardActorForeignKeys();

            $table->foreign('customer_id')->references('id')->on('customers');
        });

        DB::statement("ALTER TABLE customer_notes ADD CONSTRAINT customer_notes_body_not_blank CHECK (btrim(body) <> '')");

        // The list's own read: one customer's live notes, by date.
        DB::statement('CREATE INDEX customer_notes_by_customer ON customer_notes (customer_id, note_date) WHERE deleted_at IS NULL');

        $now = now();

        // ponytail: one insert per customer with notes; a bulk INSERT … SELECT if a site ever has many thousands.
        DB::table('customers')
            ->whereNotNull('notes')
            ->whereRaw("btrim(notes) <> ''")
            ->orderBy('id')
            ->each(function (object $customer) use ($now): void {
                DB::table('customer_notes')->insert([
                    'id' => (string) Str::uuid7(),
                    'customer_id' => $customer->id,
                    'body' => $customer->notes,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_notes');
    }
};
