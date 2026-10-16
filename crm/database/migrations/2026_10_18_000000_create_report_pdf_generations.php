<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 13, slice 4 — `report_pdf_generations` (`D-132`, spec §4.2): one row per
 * submitted version of a report, `queued` until the job finishes it `completed`
 * with its file or `failed` with the reason. The shape and the CHECKs are
 * `pdf_generations`' (`2026_09_27_100000_create_pdf_generations`), keyed on
 * `(report_id, version)` so a repeat of the same submit is a no-op. Arabic only
 * (`D-132`), so there is no `locale`. `pdf_generations` itself is not touched.
 */
return new class extends Migration
{
    private const STATUSES = ['queued', 'completed', 'failed'];

    public function up(): void
    {
        Schema::create('report_pdf_generations', function (Blueprint $table): void {
            $table->standardColumns();
            $table->uuid('report_id');
            $table->unsignedInteger('version');
            $table->string('status', 16)->default('queued');
            $table->uuid('file_id')->nullable();
            $table->smallInteger('attempts')->default(0);
            $table->text('failure_reason')->nullable();
            $table->timestampTz('finished_at')->nullable();

            $table->foreign('report_id')->references('id')->on('reports');
            $table->foreign('file_id')->references('id')->on('files');
            $table->standardActorForeignKeys();
            $table->unique(['report_id', 'version']);
            $table->index('file_id');
        });

        $statuses = "'".implode("', '", self::STATUSES)."'";

        DB::statement("ALTER TABLE report_pdf_generations ADD CONSTRAINT report_pdf_generations_known_status CHECK (status IN ({$statuses}))");
        DB::statement(
            'ALTER TABLE report_pdf_generations ADD CONSTRAINT report_pdf_generations_completed_has_file '
            ."CHECK (status <> 'completed' OR file_id IS NOT NULL)"
        );
        DB::statement(
            'ALTER TABLE report_pdf_generations ADD CONSTRAINT report_pdf_generations_failed_has_reason '
            ."CHECK (status <> 'failed' OR failure_reason IS NOT NULL)"
        );
        DB::statement(
            'ALTER TABLE report_pdf_generations ADD CONSTRAINT report_pdf_generations_finished_iff_final '
            ."CHECK ((status = 'queued') = (finished_at IS NULL))"
        );
        DB::statement('ALTER TABLE report_pdf_generations ADD CONSTRAINT report_pdf_generations_attempts_non_negative CHECK (attempts >= 0)');
    }

    public function down(): void
    {
        // `DEV-03`. The constraints and the indexes go with the table.
        Schema::dropIfExists('report_pdf_generations');
    }
};
