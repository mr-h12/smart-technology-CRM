<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `§17` attachments, in the shape `D-71` chose when it closed `Q-2`.
 *
 * The draft in `design/DATABASE.md` carried `entity_type` + `entity_id`, and the
 * reason that was rejected is the reason this migration looks the way it does:
 * **PostgreSQL cannot constrain one column against five tables.** A polymorphic
 * `entity_id` is free to name a deal that never existed, and the database has no
 * way to object. `J-11` — a weekly job that deletes files linked to nothing —
 * was the specification conceding in advance that this would happen.
 *
 * So `files` holds what is true about the bytes, and one pivot per parent holds
 * the attachment, with real foreign keys. `J-11` survives, demoted from
 * mechanism to backstop: it now catches a file uploaded and never attached,
 * which is a genuine orphan, rather than a pointer into nothing.
 *
 * **What is deliberately missing.** `deals`, `supplier_quotations`,
 * `purchase_orders` and `reports` belong to Modules 5, 6, 10 and 13. A foreign
 * key cannot reference a table that does not exist yet, so each pivot carries
 * its parent column and index now and the constraint arrives with the parent —
 * the same arrangement `standardActorForeignKeys()` already uses for
 * `created_by`. `FilesMigrationTest` lists the four that are owed and fails as
 * soon as a parent table appears without its key, so the debt cannot be
 * forgotten quietly.
 */
return new class extends Migration
{
    /**
     * Each pivot, and the table its parent column will point at once that
     * module exists.
     *
     * @var array<string, array{parent: string, column: string}>
     */
    private const PIVOTS = [
        'deal_files' => ['parent' => 'deals', 'column' => 'deal_id'],
        'supplier_quotation_files' => ['parent' => 'supplier_quotations', 'column' => 'supplier_quotation_id'],
        'purchase_order_files' => ['parent' => 'purchase_orders', 'column' => 'purchase_order_id'],
        'report_files' => ['parent' => 'reports', 'column' => 'report_id'],
    ];

    public function up(): void
    {
        Schema::create('files', function (Blueprint $table): void {
            // §4.8: UUIDv7 key, actor, timestamps, soft delete (D-61, DB-01, DB-02).
            $table->standardColumns();

            $table->string('original_name', 255);

            // The true type, established by inspecting the bytes — §17 rejects a
            // spoofed extension, and 5.3 is where that check is enforced. Long
            // enough for the compound Office types, which run past 70 characters.
            $table->string('mime_type', 127);

            $table->bigInteger('size_bytes');

            // §17's path is /{year}/{month}/{entity_type}/{entity_id}/{uuid}.ext
            // and {uuid} is files.id, so no second identifier column exists to
            // drift. UNIQUE because two rows claiming one file on disk means
            // deleting either destroys or orphans the other's bytes.
            $table->string('storage_path', 512)->unique();

            // SEC-15. Nothing is served before this reads `clean` (point 5.5).
            $table->string('scan_status', 16)->default('pending');
            $table->timestampTz('scanned_at')->nullable();
        });

        // DB-04. A CHECK rather than a managed enum table, and provisionally so:
        // DB-05 asks for enum tables, Q-6 is still open about which columns
        // qualify, and its own suggested split keeps a state a machine depends
        // on in a constrained column rather than one editable from a settings
        // screen. Revisit when Q-6 closes.
        DB::statement("ALTER TABLE files ADD CONSTRAINT files_scan_status_check
                       CHECK (scan_status IN ('pending', 'clean', 'infected'))");

        // size_bytes is what the D-71 limit is measured against, so a zero or a
        // negative value would satisfy every limit check there is.
        DB::statement('ALTER TABLE files ADD CONSTRAINT files_size_bytes_check CHECK (size_bytes > 0)');

        // The quarantine view reads the exception, not the rule, so the index
        // only covers rows that are not clean — which is the small minority.
        // A full index would be mostly dead weight on the common value.
        DB::statement("CREATE INDEX files_scan_status_pending_index
                       ON files (scan_status) WHERE scan_status <> 'clean'");

        // J-11 walks by age.
        Schema::table('files', function (Blueprint $table): void {
            $table->index('created_at');
        });

        foreach (self::PIVOTS as $name => $meta) {
            Schema::create($name, function (Blueprint $table) use ($meta): void {
                $table->uuid($meta['column']);
                $table->uuid('file_id');

                // The composite key does what an application-level "is it
                // already attached?" query does worse: two concurrent requests
                // can both pass that query, and only one can win a primary key.
                $table->primary([$meta['column'], 'file_id']);

                // Every permission check reads a parent's attachments (D-38),
                // which the primary key serves. J-11 asks the reverse — which
                // files have no parent — and that needs its own index.
                $table->index('file_id');

                // ON DELETE CASCADE is safe here only because DB-01 forbids
                // physically deleting business data: archiving sets deleted_at
                // and this row stays. The cascade covers the case the rules do
                // allow — a repair, or a migration — so that it cannot leave a
                // row pointing at nothing.
                $table->foreign('file_id')->references('id')->on('files')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        // DEV-03 wants a down path that actually runs. Pivots first: each holds
        // a foreign key into files, and dropping the referenced table while a
        // constraint points at it fails.
        foreach (array_keys(self::PIVOTS) as $name) {
            Schema::dropIfExists($name);
        }

        // The CHECK constraints and both indexes belong to the table and go with
        // it; naming them again here would fail on a second rollback.
        Schema::dropIfExists('files');
    }
};
