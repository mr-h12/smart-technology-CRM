<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 3, Point 1.1 — `customers`, the table §4.2 publishes.
 *
 * ── `added_by` is `created_by` ─────────────────────────────────────────────
 *
 * §4.2 lists "added_by · created_at | Automatic" and `DB-02` mandates
 * `created_by · created_at · updated_by · updated_at` on every table. They are
 * the same fact, so there is one column and it carries `DB-02`'s name. Two
 * columns meaning "who added this" is the defect, not the reconciliation.
 *
 * ── `customer_status` is a CHECK, not an enum table ────────────────────────
 *
 * `DB-05` requires enum tables for four named lists — sectors, units, service
 * types and delivery terms — and customer status is not one of them. §4.5
 * derives it from five ordered conditions, so a fifth status is a change to
 * that rule and therefore a migration; it is not a row an administrator adds
 * in settings. The column defaults to `prospect` because §4.5's fifth rule says
 * a customer "registered with no deals at all" **is** a Prospect, and on the day
 * this table is created no deals exist at all.
 *
 * **Nothing here derives anything.** `recompute_customer_status` is Module 5's
 * (`MVP_Build_Plan_EN.md`), and until it exists the column holds its default
 * and the UI shows it read-only, which is what §4.5 requires of it anyway.
 *
 * ── `sector` has no foreign key, and that is measured ──────────────────────
 *
 * ⚠️ `enum_lists`'s uniqueness is a **partial** index — `enum_lists_code_unique_alive
 * ON enum_lists (list, code) WHERE deleted_at IS NULL`, written that way by
 * Module 2 Point 1.3 so an archived code can be taken again. PostgreSQL refuses
 * a foreign key against a partial index, and this was probed against the
 * running database rather than recalled:
 *
 *     SQLSTATE[42830]: Invalid foreign key: 7 ERROR: there is no unique
 *     constraint matching given keys for referenced table "enum_lists"
 *
 * So `DB-04` is honoured everywhere a key is possible — `sales_owner_id` and
 * the two actor columns — and the sector is validated at the boundary against
 * `ManagedList::Sectors` instead. `CustomerSchemaMigrationTest` pins both
 * halves, so if that index ever becomes total the decision is revisited rather
 * than inherited.
 *
 * ── One index, not a speculative set ───────────────────────────────────────
 *
 * `DB-09` requires an index on the owner, and every scoped read in §3.3 filters
 * by `sales_owner_id`, so that one is written here. The name search belongs to
 * `SearchService` (Point 2.1) and the list's default sort is not chosen until
 * Point 3.2 — indexes for queries that do not exist yet would be guesses that
 * cost writes.
 */
return new class extends Migration
{
    /** §4.5's four outcomes. Prospect appears twice in the rule; it is one status. */
    private const STATUSES = ['prospect', 'customer', 'no_response', 'deal_not_completed'];

    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->standardColumns();

            // §4.2: "name | Required".
            $table->string('name', 255);

            // §4.5, derived. Never written by an employee.
            $table->string('customer_status', 32)->default('prospect');

            // A `code` from `enum_lists` where `list = 'sectors'` (`DB-05`).
            // Nullable: §4.2 marks only the name required, and `D-31` lets an
            // imported row arrive without one and be flagged incomplete.
            $table->string('sector', 64)->nullable();

            // `D-20`: free text with suggestions. The suggestions are the
            // screen's; the column takes whatever the person typed.
            $table->string('region', 128)->nullable();

            // `D-18`: one contact person, not a table of them.
            $table->string('contact_person', 255)->nullable();

            $table->string('phone', 32)->nullable();
            $table->string('phone2', 32)->nullable();
            $table->string('whatsapp', 32)->nullable();
            $table->string('email', 255)->nullable();

            // §3.3 scopes every read by this column; §10.1 keeps it pointing at
            // a deactivated employee rather than clearing it, and Flow 10 moves
            // it deliberately with an audit entry.
            $table->uuid('sales_owner_id')->nullable();

            // §4.2: "First engagement — drives years of dealing". A date, not a
            // moment: nobody records the hour a relationship began.
            $table->date('start_date')->nullable();

            // `D-16`: communication history is free-text notes, not a log table.
            $table->text('notes')->nullable();

            // Flow 7's manual archive, distinct from `DB-01`'s soft delete: an
            // archived customer is hidden from the list and still exists for the
            // Manager and the Team Leader. Deleting one is forbidden outright.
            $table->boolean('is_archived')->default(false);

            // `D-31`: an imported row with missing fields saves anyway, flagged.
            $table->boolean('is_incomplete')->default(false);

            $table->standardActorForeignKeys();

            $table->foreign('sales_owner_id')->references('id')->on('users')->nullOnDelete();
        });

        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_name_not_blank CHECK (btrim(name) <> '')");

        $statuses = "'".implode("', '", self::STATUSES)."'";

        DB::statement(
            "ALTER TABLE customers ADD CONSTRAINT customers_known_status CHECK (customer_status IN ({$statuses}))"
        );

        // `DB-09`. Partial, because every read this serves already excludes the
        // soft-deleted rows and a smaller index is a cheaper write.
        DB::statement('CREATE INDEX customers_by_owner ON customers (sales_owner_id) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
