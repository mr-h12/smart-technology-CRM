<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 9, Point 3.3 — `pdf_generations`, one row per request to render a
 * customer quotation (§14.6, `PRF-04`).
 *
 * `OpenAPI §4.3` answers queued work with `202` and a `job_id`, and leaves "who
 * may view job status and what completed output is available" to the module.
 * This table is that record, and its `id` is the `job_id`. The endpoint (3.5)
 * writes a row `queued`; the job (3.4) finishes it `completed` with its file or
 * `failed` with the reason — Q3's failure record, and what a Queue Monitor
 * would show if Horizon were installed (`§15.1`).
 *
 * ── Every rule is the database's ───────────────────────────────────────────
 *
 * `status` is a constrained column, not a managed list: the policy Q-6 set for
 * any value a state machine depends on (see `create_quotations`). `locale` is
 * Q15's — the document's language, chosen per request — and the template has
 * labels for two. The remaining CHECKs tie the columns to the status, so no
 * writer can leave a `completed` row without the file it points the download
 * at, a `failed` one without saying why, or either without when it ended.
 *
 * `created_by` is the employee who asked (`DB-02` via `standardColumns()`),
 * which is also who the job stamps on the `files` row. Nothing cascades: a
 * generation is history (`DB-01`), and `files` and `quotations` are never
 * physically deleted.
 */
return new class extends Migration
{
    private const STATUSES = ['queued', 'completed', 'failed'];

    private const LOCALES = ['ar', 'en'];

    public function up(): void
    {
        Schema::create('pdf_generations', function (Blueprint $table): void {
            $table->standardColumns();
            $table->uuid('quotation_id');
            $table->string('locale', 2);
            $table->string('status', 16)->default('queued');
            $table->uuid('file_id')->nullable();
            $table->smallInteger('attempts')->default(0);
            $table->text('failure_reason')->nullable();
            $table->timestampTz('finished_at')->nullable();

            $table->foreign('quotation_id')->references('id')->on('quotations');
            $table->foreign('file_id')->references('id')->on('files');
            $table->standardActorForeignKeys();
        });

        $statuses = "'".implode("', '", self::STATUSES)."'";
        $locales = "'".implode("', '", self::LOCALES)."'";

        DB::statement("ALTER TABLE pdf_generations ADD CONSTRAINT pdf_generations_known_status CHECK (status IN ({$statuses}))");
        DB::statement("ALTER TABLE pdf_generations ADD CONSTRAINT pdf_generations_known_locale CHECK (locale IN ({$locales}))");
        DB::statement(
            'ALTER TABLE pdf_generations ADD CONSTRAINT pdf_generations_completed_has_file '
            ."CHECK (status <> 'completed' OR file_id IS NOT NULL)"
        );
        DB::statement(
            'ALTER TABLE pdf_generations ADD CONSTRAINT pdf_generations_failed_has_reason '
            ."CHECK (status <> 'failed' OR failure_reason IS NOT NULL)"
        );
        DB::statement(
            'ALTER TABLE pdf_generations ADD CONSTRAINT pdf_generations_finished_iff_final '
            ."CHECK ((status = 'queued') = (finished_at IS NULL))"
        );
        DB::statement('ALTER TABLE pdf_generations ADD CONSTRAINT pdf_generations_attempts_non_negative CHECK (attempts >= 0)');

        // 4.2 reads the newest live generation of one quotation on every poll.
        DB::statement(
            'CREATE INDEX pdf_generations_latest ON pdf_generations (quotation_id, created_at DESC) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        // `DEV-03`. The constraints and the index go with the table.
        Schema::dropIfExists('pdf_generations');
    }
};
