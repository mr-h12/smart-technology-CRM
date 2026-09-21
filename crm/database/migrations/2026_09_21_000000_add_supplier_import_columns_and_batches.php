<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F-09 · 1.2 — `D-85`: suppliers are imported like customers, and `D-31`
 * flags an imported row with missing fields.
 *
 * `suppliers.is_incomplete` is `customers.is_incomplete` again: NOT NULL,
 * default false, so every existing supplier — none of which was imported —
 * reads complete without a backfill.
 *
 * `supplier_import_batches` is `import_batches`' shape (Module 3, Point 1.2,
 * whose migration explains each column and CHECK) owned by the Suppliers
 * module: modules do not share tables, so the customers' one is not reused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->boolean('is_incomplete')->default(false);
        });

        Schema::create('supplier_import_batches', function (Blueprint $table): void {
            $table->standardColumns();

            $table->string('original_filename', 255);

            $table->integer('row_count')->default(0);

            $table->integer('imported_count')->default(0);

            $table->integer('incomplete_count')->default(0);

            $table->standardActorForeignKeys();
        });

        foreach ([
            'filename_not_blank' => "btrim(original_filename) <> ''",
            'counts_not_negative' => 'row_count >= 0 AND imported_count >= 0 AND incomplete_count >= 0',
            'imported_within_read' => 'imported_count <= row_count',
            'incomplete_within_imported' => 'incomplete_count <= imported_count',
        ] as $name => $check) {
            DB::statement("ALTER TABLE supplier_import_batches ADD CONSTRAINT supplier_import_batches_{$name} CHECK ({$check})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_import_batches');

        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropColumn('is_incomplete');
        });
    }
};
