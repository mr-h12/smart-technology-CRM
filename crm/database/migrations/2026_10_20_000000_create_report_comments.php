<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 13, slice 7 · 7.3 — `D-127`: a recipient's comments on a report, one flat
 * list, append-only (no edit, no remove route), soft-deleted like every business
 * table (`DB-01`). A comment's author is the row's `created_by`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_comments', function (Blueprint $table): void {
            $table->standardColumns();
            $table->uuid('report_id');
            $table->text('body');
            $table->standardActorForeignKeys();
            $table->foreign('report_id')->references('id')->on('reports');
        });

        DB::statement("ALTER TABLE report_comments ADD CONSTRAINT report_comments_body_not_blank CHECK (btrim(body) <> '')");

        // The page's read: one report's live comments, oldest first.
        DB::statement('CREATE INDEX report_comments_by_report ON report_comments (report_id, created_at) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('report_comments');
    }
};
