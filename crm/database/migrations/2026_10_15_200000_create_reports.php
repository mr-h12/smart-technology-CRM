<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 13, first slice — the three tables the module card names.
 *
 * - `reports`: one report and its live draft. `type` is `D-125`'s six;
 *   `status` is §11.4's lifecycle plus `D-127`'s Missed. `version_token` is
 *   `DB-12`'s optimistic lock, read by `If-Match`.
 * - `report_versions`: §11.3's frozen copy, one per submitted version, never
 *   rewritten (`UNIQUE (report_id, version)`).
 * - `report_recipients`: who a report was sent to (`D-127`, `D-129`).
 *
 * It also closes the parent key `report_files` has owed since Module 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table): void {
            $table->standardColumns();
            $table->string('code', 32)->unique();
            $table->string('type', 16);
            $table->string('status', 16)->default('draft');
            $table->uuid('author_id');
            $table->date('period_start');
            $table->date('period_end');
            $table->text('notes')->nullable();
            $table->unsignedInteger('version_token')->default(1);
            $table->timestampTz('submitted_at')->nullable();

            $table->standardActorForeignKeys();
            $table->foreign('author_id')->references('id')->on('users');
        });

        DB::statement("ALTER TABLE reports ADD CONSTRAINT reports_type_known CHECK (type IN ('daily', 'weekly', 'monthly', 'deal', 'accounts', 'performance'))");
        DB::statement("ALTER TABLE reports ADD CONSTRAINT reports_status_known CHECK (status IN ('draft', 'submitted', 'viewed', 'acknowledged', 'returned', 'archived', 'missed'))");
        DB::statement('ALTER TABLE reports ADD CONSTRAINT reports_period_ordered CHECK (period_end >= period_start)');
        // "My reports": one author's live reports, newest period first.
        DB::statement('CREATE INDEX reports_by_author ON reports (author_id, period_start DESC) WHERE deleted_at IS NULL');

        Schema::create('report_versions', function (Blueprint $table): void {
            $table->standardColumns();
            $table->uuid('report_id');
            $table->unsignedInteger('version');
            $table->jsonb('snapshot');

            $table->standardActorForeignKeys();
            $table->foreign('report_id')->references('id')->on('reports');
            $table->unique(['report_id', 'version']);
        });

        Schema::create('report_recipients', function (Blueprint $table): void {
            $table->standardColumns();
            $table->uuid('report_id');
            $table->uuid('user_id');

            $table->standardActorForeignKeys();
            $table->foreign('report_id')->references('id')->on('reports');
            $table->foreign('user_id')->references('id')->on('users');
        });

        DB::statement('CREATE UNIQUE INDEX report_recipients_once ON report_recipients (report_id, user_id) WHERE deleted_at IS NULL');

        Schema::table('report_files', function (Blueprint $table): void {
            $table->foreign('report_id')->references('id')->on('reports');
        });
    }

    public function down(): void
    {
        // The pivot's key goes first — the parent cannot be dropped while it is referenced.
        Schema::table('report_files', function (Blueprint $table): void {
            $table->dropForeign(['report_id']);
        });
        Schema::dropIfExists('report_recipients');
        Schema::dropIfExists('report_versions');
        Schema::dropIfExists('reports');
    }
};
