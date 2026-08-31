<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 5, Point 1.1 — `deals`, the table §4.3 publishes.
 *
 * ── `code` is a column here, not a value yet ───────────────────────────────
 *
 * §4.3 marks it "Automatic — `DL-2026-0001`", and `document_sequences`
 * (Module 0) exists precisely to allocate that number atomically. Nothing here
 * calls it: allocating a code is something that happens when a deal is
 * *created*, which is Step 2's `POST /deals`, not Step 1's table. This
 * migration only has to make the column able to hold what that point will put
 * in it — `NOT NULL` and `UNIQUE`, because a deal is never without its own
 * code once it exists, and two deals can never share one.
 *
 * ── None of §4.3's fields say "Required" ───────────────────────────────────
 *
 * §4.2 marked exactly one field that way — "name | Required" — and Module 3
 * Point 1.1 read that literally: everything else nullable unless the entity
 * map itself forces otherwise. §4.3 marks *nothing* "Required", not even
 * `title`. That silence is carried forward rather than improved on: `title`,
 * `source`, `service_type`, `owner_id` and `rejection_reason` are all
 * nullable. `customer_id` is the one exception, and it is not required by an
 * annotation — it is required by §4.1's entity map, which draws exactly one
 * line into a deal: `Customer └── Deal (request)`. A deal with no customer is
 * not a request; it is not a row §4.1 describes at all.
 *
 * ── `status` is NOT NULL with a default, and that is not the same silence ──
 *
 * Unlike the fields above, a deal without a status is not a smaller version of
 * one — §4.4 draws it as a state machine with no "unset" state, and Flow 1
 * step 2 names where a deal begins: "Team Leader … enters the deal → *Lead*".
 * The column therefore defaults to `lead` rather than being merely present.
 *
 * ── `approval_status` is nullable, and NULL means "not applicable" ─────────
 *
 * §4.3 ties it to one case only — "Pending · Approved · Rejected — **for
 * employee-entered requests**" — and Flow 1 shows a request that never has
 * one: a Team Leader who enters a deal directly puts it straight at `Lead`,
 * with no approval step at all (Flow 3 is what needs one, and Flow 3 is the
 * employee-entered path). A TL-entered deal is not "approved by default"; it
 * was never submitted for approval, which is a different fact and needs a
 * different value than any of the three named ones. `D-63` already set this
 * precedent for `tax_percent` — NULL is a fourth state, not a gap the other
 * three are trusted to cover — and it is followed here rather than
 * reinvented.
 *
 * ── `rejection_reason` names one rejection, and §4.4 quietly names another ─
 *
 * §4.3 puts `rejection_reason` directly under `approval_status`, so this
 * migration reads it as *that* rejection's reason — the CHECK below enforces
 * it exactly there: `approval_status = 'rejected'` requires a non-blank
 * reason, nothing else does.
 *
 * ⚠️ **§4.4 separately requires a reason for a deal reaching `Lost`** — "Lost
 * | Sales (mandatory reason) | Terminal" — and no field in §4.3's table is
 * named for it. That is not resolved here. Whether the `Lost` transition
 * reuses this same column or needs one of its own is an **open owner
 * question**, and inventing an answer now would be a schema decision §4.3
 * does not support. It belongs to whichever point first implements a status
 * transition to `Lost`.
 *
 * ── `service_type` shares a name with `catalog_items.service_type` and nothing else ──
 *
 * `catalog_items.service_type` (Module 4 Point 1.2) is a code out of
 * `enum_lists` — installation, repair, maintenance, setup. §4.3's
 * `service_type` is a two-way split of the *request itself* — "Product ·
 * Service" — the same shape as `catalog_items.kind`, not the same column
 * repeated. No source relates the two, so none is built here; the identical
 * name is a coincidence of vocabulary, stated so a future reader does not go
 * looking for a relationship that was never documented.
 *
 * ── `owner_id` and `customer_id`, and why only one gets `nullOnDelete()` ────
 *
 * `customer_id` is `NOT NULL` for the reason above, so nulling it on a
 * deleted customer is not an available behaviour — and moot besides, since
 * `DB-01` forbids a customer ever being physically deleted. `owner_id` is
 * nullable, on `customers.sales_owner_id`'s precedent (Module 3 Point 1.1):
 * an unassigned deal is a real, documented state (Flow 3's "Pending
 * Approval … inactive until approved"), so losing the referenced user clears
 * the assignment rather than blocking it.
 *
 * ── `deal_files.deal_id → deals`, the debt Module 0 recorded ───────────────
 *
 * `2026_08_22_000000_create_files_and_attachment_pivots` created the pivot
 * with the column and its index but no key, because the key cannot reference
 * a table that does not exist yet — its own docblock names this exact
 * migration as the one that closes it, and `FilesMigrationTest`'s
 * `test_the_parent_foreign_keys_that_are_still_owed_are_recorded` fails from
 * the moment `deals` exists until the constraint is added. Closed here, in
 * the same migration that creates the table it was waiting for.
 *
 * ── One index each for customer, owner, status and date — `DB-09` by name ──
 *
 * `DB-09` names all four categories this table actually has. `customer_id`
 * serves the customer detail page's "this customer's deals"; `owner_id` is
 * §3.4's `Own` scope, so it takes `scopeIndex()` on `customers.sales_owner_id`'s
 * precedent — a scope column paired with `deleted_at` in one partial index;
 * `status` serves the Kanban board (§7.1 of the design system) and the
 * dashboards (§12); `last_activity_at` is read once a day by `J-03`
 * (`detect_stale_deals`). None of the four is a guess: each is a query this
 * table already has a named caller for.
 */
return new class extends Migration
{
    /** §4.4's eleven non-terminal-plus-terminal states, in the order it draws them. */
    private const STATUSES = [
        'lead', 'contacted', 'waiting_customer_request', 'supplier_rfq',
        'supplier_quotation', 'quotation_sent', 'negotiations', 'won',
        'purchasing', 'delivery', 'delivery_complete', 'lost',
    ];

    /** §4.3: "source | Outlook/WhatsApp · Outdoor visit · Employee entry". */
    private const SOURCES = ['outlook_whatsapp', 'outdoor_visit', 'employee_entry'];

    /** §4.3: "service_type | Product · Service" — the request's own split, not the catalog's. */
    private const SERVICE_TYPES = ['product', 'service'];

    /** §4.3: "approval_status | Pending · Approved · Rejected". */
    private const APPROVAL_STATUSES = ['pending', 'approved', 'rejected'];

    public function up(): void
    {
        Schema::create('deals', function (Blueprint $table): void {
            $table->standardColumns();

            // §4.3 "Automatic". NOT NULL and UNIQUE for what a deal always has
            // once it exists; the generator that fills it is Step 2's.
            $table->string('code', 20)->unique();

            $table->uuid('customer_id');

            // §4.3: no "Required" marker, so nullable — see the class docblock.
            $table->string('title', 255)->nullable();

            $table->string('source', 32)->nullable();

            $table->string('service_type', 16)->nullable();

            // §4.4: a deal is never in no state. Flow 1 step 2 names where it starts.
            $table->string('status', 32)->default('lead');

            $table->uuid('owner_id')->nullable();

            // NULL = not applicable (a TL-entered deal was never submitted for
            // approval), distinct from all three named values. See the docblock.
            $table->string('approval_status', 16)->nullable();

            // Mandatory only for the rejection §4.3 names next to it —
            // `approval_status = 'rejected'`. See the docblock on `Lost`.
            $table->text('rejection_reason')->nullable();

            // §4.3: drives `J-03`. No DB default — Step 2's create-deal use
            // case always sets it to the creation moment, the same way
            // `customers.name` has none and relies on the write path instead.
            $table->timestampTz('last_activity_at');

            $table->standardActorForeignKeys();

            $table->foreign('customer_id')->references('id')->on('customers');
            $table->foreign('owner_id')->references('id')->on('users')->nullOnDelete();
        });

        $statuses = "'".implode("', '", self::STATUSES)."'";
        DB::statement("ALTER TABLE deals ADD CONSTRAINT deals_known_status CHECK (status IN ({$statuses}))");

        $sources = "'".implode("', '", self::SOURCES)."'";
        DB::statement(
            "ALTER TABLE deals ADD CONSTRAINT deals_known_source CHECK (source IS NULL OR source IN ({$sources}))"
        );

        $serviceTypes = "'".implode("', '", self::SERVICE_TYPES)."'";
        DB::statement(
            'ALTER TABLE deals ADD CONSTRAINT deals_known_service_type '
            ."CHECK (service_type IS NULL OR service_type IN ({$serviceTypes}))"
        );

        $approvalStatuses = "'".implode("', '", self::APPROVAL_STATUSES)."'";
        DB::statement(
            'ALTER TABLE deals ADD CONSTRAINT deals_known_approval_status '
            ."CHECK (approval_status IS NULL OR approval_status IN ({$approvalStatuses}))"
        );

        // §4.3: "rejection_reason | Mandatory on rejection" — tied to the one
        // rejection this table names. A blank string is not a reason, the same
        // reading `customers_name_not_blank` and `catalog_items_name_not_blank`
        // already gave a mandatory-when-present text field.
        DB::statement(
            'ALTER TABLE deals ADD CONSTRAINT deals_rejection_reason_required_when_rejected '
            ."CHECK (approval_status IS DISTINCT FROM 'rejected' OR (rejection_reason IS NOT NULL AND btrim(rejection_reason) <> ''))"
        );

        DB::statement('CREATE INDEX deals_by_customer ON deals (customer_id) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX deals_by_status ON deals (status) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX deals_by_last_activity ON deals (last_activity_at) WHERE deleted_at IS NULL');

        Schema::table('deals', function (Blueprint $table): void {
            $table->scopeIndex('owner_id');
        });

        // The debt `2026_08_22_000000_create_files_and_attachment_pivots`
        // recorded: the column and its index existed, the key could not.
        Schema::table('deal_files', function (Blueprint $table): void {
            $table->foreign('deal_id')->references('id')->on('deals');
        });
    }

    public function down(): void
    {
        // The key on `deal_files` must go first — `deals` cannot be dropped
        // while it is referenced.
        Schema::table('deal_files', function (Blueprint $table): void {
            $table->dropForeign(['deal_id']);
        });

        Schema::dropIfExists('deals');
    }
};
