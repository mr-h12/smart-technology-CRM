<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 16, Point 5 (`D-153`, `BK-04`) — `J-10`'s record: one row per run,
 * `running`, then `succeeded` with both artefacts' paths, SHA-256 and sizes, or
 * `failed` with its reason. The CHECKs hold BK-04 here, not only in the code; each
 * checksum is `IS NOT NULL` as well as matched, because `NULL ~ '…'` is NULL and a
 * CHECK lets NULL through.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_runs', function (Blueprint $table): void {
            $table->standardId();
            $table->string('status', 20);
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            // Relative to `backups.path`: `<YYYYMMDD-HHMMSS>/database.dump` and `…/files.tar.gz`.
            $table->string('database_path', 255)->nullable();
            $table->char('database_sha256', 64)->nullable();
            $table->unsignedBigInteger('database_bytes')->nullable();
            $table->string('files_path', 255)->nullable();
            $table->char('files_sha256', 64)->nullable();
            $table->unsignedBigInteger('files_bytes')->nullable();
            $table->text('error')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'started_at']);
        });

        DB::statement("ALTER TABLE backup_runs ADD CONSTRAINT backup_runs_status_check CHECK (status IN ('running', 'succeeded', 'failed'))");
        DB::statement(<<<'SQL'
            ALTER TABLE backup_runs ADD CONSTRAINT backup_runs_succeeded_complete CHECK (
                status <> 'succeeded' OR (
                    finished_at IS NOT NULL
                    AND database_path IS NOT NULL AND database_bytes IS NOT NULL
                    AND database_sha256 IS NOT NULL AND database_sha256 ~ '^[0-9a-f]{64}$'
                    AND files_path IS NOT NULL AND files_bytes IS NOT NULL
                    AND files_sha256 IS NOT NULL AND files_sha256 ~ '^[0-9a-f]{64}$'
                )
            )
            SQL);
        DB::statement("ALTER TABLE backup_runs ADD CONSTRAINT backup_runs_failed_says_why CHECK (status <> 'failed' OR (finished_at IS NOT NULL AND error IS NOT NULL AND btrim(error) <> ''))");
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_runs');
    }
};
