<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 3, Point 1.2 — `import_batches`.
 *
 * ── ⚠️ The table is required; its columns are not documented ───────────────
 *
 * `import_batches` appears **once** in the entire corpus: `MVP_Build_Plan_EN.md`
 * lists it among Module 3's tables. No section describes a field of it, and the
 * OpenAPI contract does not mention import at all. So the columns below are
 * derived from what *is* documented, and `CHECKLIST.md` records them as a
 * decision awaiting a `D-xx` rather than as a reading of the documentation.
 *
 * What each one is derived from:
 *
 *   * **who and when** — `DB-02`, free with `standardColumns()`. §3.3 gives
 *     `import (Excel)` to the Manager alone, so `created_by` is the importer.
 *   * **`original_filename`** — a batch nobody can identify answers no question
 *     anyone would ask of it. The *name*, not the file: see below.
 *   * **three counts** — `D-31` makes "saved but incomplete" an outcome distinct
 *     from "saved", so a batch that cannot report how many of each it produced
 *     cannot describe its own result.
 *
 * **A fourth count is deliberately absent.** Outright failures are
 * `row_count - imported_count`; a stored copy is a number that can disagree
 * with the two it is computed from.
 *
 * ── The uploaded file is not kept ─────────────────────────────────────────
 *
 * There is no path column, and that is scope rather than omission. Storing the
 * upload pulls in `SEC-15`'s virus scan, `D-39`'s configurable 10 MB limit and
 * `D-38`'s permission-checked download — the files work that belongs to
 * Module 5. A column holding a path today would promise machinery that does not
 * exist yet, and `ImportBatchSchemaMigrationTest` fails if one appears.
 *
 * ── The arithmetic is the database's, not the writer's ────────────────────
 *
 * `DB-04`. A batch claiming more imported rows than it read is nonsense, and
 * `D-31` makes an incomplete row an *imported* row — it saves, flagged — so
 * `incomplete_count` can never exceed `imported_count`. Both are CHECKs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table): void {
            $table->standardColumns();

            // The name the person recognises. Not a path — the file is not kept.
            $table->string('original_filename', 255);

            // How many data rows the file held, once the header is discounted.
            $table->integer('row_count')->default(0);

            // How many became customers. `D-31`: a flagged row still counts as
            // imported, because it saved.
            $table->integer('imported_count')->default(0);

            // How many of those carry `customers.is_incomplete` (`D-31`).
            $table->integer('incomplete_count')->default(0);

            $table->standardActorForeignKeys();
        });

        DB::statement(
            'ALTER TABLE import_batches ADD CONSTRAINT import_batches_filename_not_blank '
            ."CHECK (btrim(original_filename) <> '')"
        );

        DB::statement(
            'ALTER TABLE import_batches ADD CONSTRAINT import_batches_counts_not_negative '
            .'CHECK (row_count >= 0 AND imported_count >= 0 AND incomplete_count >= 0)'
        );

        DB::statement(
            'ALTER TABLE import_batches ADD CONSTRAINT import_batches_imported_within_read '
            .'CHECK (imported_count <= row_count)'
        );

        DB::statement(
            'ALTER TABLE import_batches ADD CONSTRAINT import_batches_incomplete_within_imported '
            .'CHECK (incomplete_count <= imported_count)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
