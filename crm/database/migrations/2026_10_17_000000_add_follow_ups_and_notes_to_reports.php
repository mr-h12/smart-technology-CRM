<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Module 13, slice 3 — `D-131`: a daily report's follow-ups (a customer and a
 * note per row) and its several notes, both soft-deleted (`DB-01`).
 *
 * Each draft or returned report's non-blank `notes` text becomes its first
 * note; a submitted report's lives in its frozen copy. `reports.notes` is kept,
 * unread (the `D-122` precedent), so `down()` drops the two tables and loses nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_follow_ups', function (Blueprint $table): void {
            $table->standardColumns();
            $table->uuid('report_id');
            $table->uuid('customer_id');
            $table->text('note');
            $table->standardActorForeignKeys();
            $table->foreign('report_id')->references('id')->on('reports');
            $table->foreign('customer_id')->references('id')->on('customers');
        });

        Schema::create('report_notes', function (Blueprint $table): void {
            $table->standardColumns();
            $table->uuid('report_id');
            $table->text('body');
            $table->standardActorForeignKeys();
            $table->foreign('report_id')->references('id')->on('reports');
        });

        DB::statement("ALTER TABLE report_follow_ups ADD CONSTRAINT report_follow_ups_note_not_blank CHECK (btrim(note) <> '')");
        DB::statement("ALTER TABLE report_notes ADD CONSTRAINT report_notes_body_not_blank CHECK (btrim(body) <> '')");

        // The page's read: one report's live rows, in the order they were added.
        DB::statement('CREATE INDEX report_follow_ups_by_report ON report_follow_ups (report_id, created_at) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX report_notes_by_report ON report_notes (report_id, created_at) WHERE deleted_at IS NULL');

        $now = now();

        DB::table('reports')
            ->whereIn('status', ['draft', 'returned'])
            ->whereNotNull('notes')
            ->whereRaw("btrim(notes) <> ''")
            ->orderBy('id')
            ->each(function (object $report) use ($now): void {
                DB::table('report_notes')->insert([
                    'id' => (string) Str::uuid7(),
                    'report_id' => $report->id,
                    'body' => $report->notes,
                    'created_by' => $report->author_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_notes');
        Schema::dropIfExists('report_follow_ups');
    }
};
