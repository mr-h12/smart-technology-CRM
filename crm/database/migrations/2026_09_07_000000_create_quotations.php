<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 7, Point 1.1 — `quotations`, the table §6.2 publishes and §5 prices.
 *
 * `design/DATABASE.md` §10 calls this "the most constrained table in the
 * system" and lists its columns by group. This migration builds that list.
 * The §5.2 money identities are deliberately **not** here — they are Point
 * 1.2's own `ALTER TABLE`, so a reviewer reads the pricing invariants without
 * thirty columns in the diff.
 *
 * ── `status` is a constrained column, not a managed enum table ─────────────
 *
 * `design/DATABASE.md` §14 **Q-6** asked which of the system's six status
 * columns become `enum_lists` rows, and noted it "needs one policy, not six
 * separate calls". Approved 2026-09-07: **a value a state machine depends on
 * stays a constrained column.** `DB-05` names exactly four managed lists —
 * sectors · units · service types · delivery terms — and `enum_lists` CHECKs
 * those four literals, so a fifth list is a migration either way. The three
 * status columns already shipped (`deals.status`, `deals.approval_status`,
 * `customers.customer_status`) are all `string` + CHECK; this is the fourth,
 * not a new convention. Q-6's own reasoning is the citation: a workflow whose
 * states can be edited from a settings screen is a workflow with no
 * guarantees, and Modules 8 and 10 are graded on those transitions.
 *
 * ── Nine literals, not ten — "Returned" is not a status ────────────────────
 *
 * §6.1 is headed "Statuses (9)" and lists nine. §6.3 names "Returned" beside
 * Partial and Counter, which reads like a tenth; §6.4 resolves it —
 * `return with note ──► Draft (v2)`. A return produces a **Draft**, so there
 * is no Returned row to store and the CHECK below has nine literals.
 *
 * ── `customer_id` alongside `deal_id`, and both NOT NULL ───────────────────
 *
 * §10's Core group lists both. Approved 2026-09-07 as a **snapshot of the
 * addressee**, the same reason this table captures the FX rate (`D-09`) and
 * the rounding setting (`D-65`) rather than reading them live: reassigning a
 * deal's customer must not silently re-address a quotation that was already
 * issued. `deal_id` is NOT NULL because §4.1 draws `Deal └── Quotations` and,
 * unlike `supplier_quotations.deal_id`, no `D-51` exists to make it optional.
 *
 * ── The currency is an id; the rounding setting is a copy ──────────────────
 *
 * `currency_id` follows `supplier_quotations` for the same recorded reason:
 * `currencies_code_unique_alive` is a **partial** unique index and PostgreSQL
 * cannot point a foreign key at one. It is NOT NULL here because `D-09`
 * forces it — "a quotation uses one currency".
 *
 * `rounding_unit` and `rounding_enabled` are **copied onto the row**, not read
 * from `currencies` at print time. §5.3 is explicit: changing either "affects
 * new quotations only, never one that has already been issued". Reading them
 * live is precisely the defect that sentence forbids.
 *
 * ── `tax_percent` and `default_margin`: NULL is a real state, 0 is not ─────
 *
 * `tax_percent` is nullable **with no default**, and `tax_percent = 0` is made
 * unstorable by the CHECK below. `D-63` distinguishes *no tax* from *zero
 * tax*: an exempt quotation renders no tax line at all, not a zero line, and
 * a `0` would render one. `default_margin` is the opposite — NOT NULL, because
 * `D-03` lets a line's `margin_percent` be NULL meaning *inherit*, and an
 * inheritance chain needs somewhere to terminate. Neither gets `->default(0)`;
 * that would break two named acceptance criteria with no database error.
 *
 * `discount_percent` does default to `0`, because unlike tax a zero discount
 * is an ordinary state that renders nothing either way (`D-07`).
 *
 * ── `version` and `version_token` are two different counters ───────────────
 *
 * `version` is the document version — `DB-03`, `§6.3`, "linked via `parent_id`
 * + `version`", the thing a user sees under "Previous Quotations".
 * `version_token` is the optimistic lock — `DB-12`, `API-12`, and OpenAPI
 * §9.2's `"etag": "quotation:uuid:7"`. Naming them apart is not cosmetic: put
 * the document version in the ETag and two users editing v2 both satisfy
 * `If-Match`, so the later write silently wins — which is the exact criterion
 * §6.4 grades this module on ("employee and Team Leader edit simultaneously →
 * 409 Conflict"). §10 calls the column `version_token`; that name is kept.
 *
 * `version_token` lives on the parent only. The builder edits lines and
 * additional items through the quotation, so the parent's token covers them —
 * which is an obligation the write path inherits: **a child-only write must
 * still bump it**, or two users clobber each other's lines while both
 * `If-Match` checks pass. A trigger cannot do this; only Step 3 can.
 *
 * ── What §6.2 names that is not a column here ──────────────────────────────
 *
 * "Suppliers 1 → 10" is not a table and must not become one: it is `DISTINCT`
 * over `quotation_items → supplier_quotation_items → supplier_quotations`, and
 * the cap is a cross-row rule no CHECK can express. "Created by" and "last
 * edit and by whom" are `created_by`/`updated_by`, which `standardColumns()`
 * already provides under `DB-02` — a second column for the same fact is the
 * duplicate the waste audit exists to catch. `is_self_approved` and `sent_at`
 * *are* here and start empty: §6.2 lists them as quotation fields, and
 * `deals.approval_status` set the precedent of building the documented field
 * in Step 1 and leaving Module 8 to fill it.
 *
 * ── `DB-09`'s categories, as this table has them ───────────────────────────
 *
 * `design/DATABASE.md` §13 names three indexes for this table: the version
 * chain, the approval/expiry queue, and the "previous quotations" panel. The
 * third is served by the leading column of §10's `UNIQUE (parent_id, version)`
 * rather than by a second index on the same column.
 *
 * **No `scopeIndex()` yet, and that is a decision.** §13's last row wants the
 * *scope* column indexed — but §3.5's `Own` for a quotation is undefined:
 * `created_by` (who built it) or the deal's `owner_id` (who owns the deal)?
 * §6.2 says "created by", §6.6 groups "by employee", `DealRowScope` uses
 * `owner_id`. Step 3 must pick, and indexing a column before knowing whether
 * it is the scope column is an index for a query that does not exist. The
 * same applies to `customer_id`, whose query is §6.6's "by company" view in
 * Step 5.
 */
return new class extends Migration
{
    /** §6.1 "Statuses (9)", in the order it lists them. See the docblock on "Returned". */
    private const STATUSES = [
        'draft', 'pending', 'approved', 'sent', 'accepted',
        'partial', 'counter', 'rejected', 'expired',
    ];

    /** §6.3: the two statuses whose reason is mandatory "before the status change is accepted". */
    private const REASON_REQUIRED_STATUSES = ['rejected', 'counter'];

    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table): void {
            $table->standardColumns();

            // §4.7 "QT-2026-0001". NOT NULL and UNIQUE for what a quotation
            // always has once it exists; the generator is Point 1.7's.
            $table->string('code', 20)->unique();

            // §4.1 "Deal └── Quotations", and §10's Core group. See the docblock.
            $table->uuid('deal_id');
            $table->uuid('customer_id');

            // §6.2 marks neither Required; `supplier_quotations.offer_date` read
            // the same silence the same way. `valid_until` drives `J-01`.
            $table->date('quotation_date')->nullable();
            $table->date('valid_until')->nullable();

            // §6.1. A quotation is never in no state, and §6.4 starts at Draft.
            $table->string('status', 32)->default('draft');

            // `D-09`: one currency per quotation, so NOT NULL. An id, not a
            // char(3) code — see the docblock.
            $table->uuid('currency_id');

            // `D-03` terminates a line's inherited margin here, so NOT NULL.
            $table->percentage('default_margin');

            // `D-07`. NOT NULL with no database default, like every other
            // numeric column here: the write path states the discount even when
            // it is zero. Zero *is* an ordinary discount — contrast
            // `tax_percent` directly below, where zero is refused outright.
            $table->percentage('discount_percent');

            // `D-63`. Nullable, no default, and `0` is refused by CHECK.
            $table->percentage('tax_percent', true);

            // §5.3 · `D-65` — captured, not read live. See the docblock.
            $table->money('rounding_unit');
            $table->boolean('rounding_enabled');

            // §5.2's totals (§10's "Totals" group). `tax_amount` is nullable to
            // pair with `tax_percent` under `D-63`; the pairing itself and the
            // four additive identities are Point 1.2's CHECKs.
            $table->money('subtotal');
            $table->money('additional_total');
            $table->money('discount_amount');
            $table->money('tax_base');
            $table->money('tax_amount', true);
            $table->money('net_amount');
            $table->money('total_before_round');
            $table->money('final_total');
            // `D-06`: stored, never computed on read. `0` when rounding is off.
            $table->money('rounding_diff');

            // §6.2's Terms group. `D-26` makes payment free text; §10 groups
            // delivery with it, and the build plan calls the whole group
            // SmartTermInput. `text` rather than a code column because the
            // enum-or-free-text question is still open and `text` is the
            // reading that does not foreclose the other.
            $table->text('payment_terms')->nullable();
            $table->text('warranty')->nullable();
            $table->text('delivery_terms')->nullable();
            // §6.2 "show delivery terms in PDF (yes/no)". The default is chosen,
            // not documented — the form needs an initial state and showing
            // agreed terms reveals nothing §6.2 protects.
            $table->boolean('show_delivery_terms')->default(true);

            // `DB-03` · §6.3.
            $table->unsignedInteger('version')->default(1);
            $table->uuid('parent_id')->nullable();
            // §6.3: "Rejection and counter reasons are mandatory before the
            // status change is accepted" — enforced by CHECK below, the same
            // shape as `deals_rejection_reason_required_when_rejected`.
            $table->text('rejection_reason')->nullable();

            // §6.2's Tracking group. Both start empty; Modules 8 and 9 fill them.
            $table->timestampTz('sent_at')->nullable();
            $table->boolean('is_self_approved')->default(false);

            // `DB-12` · `API-12` · OpenAPI §9.2. Not `version`. See the docblock.
            $table->unsignedInteger('version_token')->default(1);

            $table->standardActorForeignKeys();

            $table->foreign('deal_id')->references('id')->on('deals');
            $table->foreign('customer_id')->references('id')->on('customers');
            $table->foreign('currency_id')->references('id')->on('currencies')->restrictOnDelete();
        });

        // The self-reference is its own statement, after the table exists.
        // `standardId()` declares the primary key at column level, and Laravel
        // appends that `ALTER TABLE ... ADD PRIMARY KEY` *after* the foreign
        // keys a `Schema::create` closure collects — so a key pointing at this
        // table's own `id` is compiled before the constraint it needs and
        // PostgreSQL refuses it with `SQLSTATE 42830`. No other table in the
        // project references itself, so nothing had met this ordering before;
        // `supplier_quotations` already used a trailing `Schema::table()` for
        // a key it could not declare inline, and this follows it.
        //
        // `DB-01` forbids physically deleting a quotation, so this cannot fire
        // in practice; it is stated so a version chain cannot be pointed at a
        // row that never existed.
        Schema::table('quotations', function (Blueprint $table): void {
            $table->foreign('parent_id')->references('id')->on('quotations');
        });

        $statuses = "'".implode("', '", self::STATUSES)."'";
        DB::statement("ALTER TABLE quotations ADD CONSTRAINT quotations_known_status CHECK (status IN ({$statuses}))");

        // `D-63`: `0` must be impossible to store — it is the only thing
        // standing between an exempt quotation and a rendered zero tax line.
        DB::statement(
            'ALTER TABLE quotations ADD CONSTRAINT quotations_tax_percent_not_zero '
            .'CHECK (tax_percent IS NULL OR tax_percent > 0)'
        );

        // §10's constraint list. 100% off is not a discount, it is a giveaway
        // with no documented meaning; `D-07` makes it a percentage of subtotal.
        DB::statement(
            'ALTER TABLE quotations ADD CONSTRAINT quotations_discount_percent_in_range '
            .'CHECK (discount_percent >= 0 AND discount_percent < 100)'
        );

        // `currencies_rounding_unit_positive` said the same about the source
        // column; a captured copy is no less required to be a real unit.
        DB::statement(
            'ALTER TABLE quotations ADD CONSTRAINT quotations_rounding_unit_positive CHECK (rounding_unit > 0)'
        );

        // `DB-03` counts from 1, and OpenAPI §9.2's token is an ETag nobody can
        // hold a value below.
        DB::statement('ALTER TABLE quotations ADD CONSTRAINT quotations_version_positive CHECK (version >= 1)');
        DB::statement(
            'ALTER TABLE quotations ADD CONSTRAINT quotations_version_token_positive CHECK (version_token >= 1)'
        );

        // An offer that expires before it is written is not a validity window.
        // Both nullable, so the constraint only speaks when both are present.
        DB::statement(
            'ALTER TABLE quotations ADD CONSTRAINT quotations_validity_window_ordered '
            .'CHECK (valid_until IS NULL OR quotation_date IS NULL OR valid_until >= quotation_date)'
        );

        $reasonRequired = "'".implode("', '", self::REASON_REQUIRED_STATUSES)."'";
        // §6.3, and a blank string is not a reason — the reading
        // `deals_rejection_reason_required_when_rejected` already gave.
        DB::statement(
            'ALTER TABLE quotations ADD CONSTRAINT quotations_reason_required_when_rejected_or_counter '
            ."CHECK (status NOT IN ({$reasonRequired}) OR (rejection_reason IS NOT NULL AND btrim(rejection_reason) <> ''))"
        );

        // §10: "UNIQUE (parent_id, version) — DB-03: one v2 per parent".
        // Partial because `DB-01` soft-deletes and a plain UNIQUE would let an
        // archived version reserve its number forever. NULL `parent_id` rows
        // are distinct in PostgreSQL, so this does not cap root quotations.
        // Its leading column also serves §13's "previous quotations" panel,
        // which is why there is no second index on `parent_id`.
        DB::statement(
            'CREATE UNIQUE INDEX quotations_version_unique_alive '
            .'ON quotations (parent_id, version) WHERE deleted_at IS NULL'
        );

        // §13: "the version chain".
        DB::statement(
            'CREATE INDEX quotations_by_deal_version ON quotations (deal_id, version) WHERE deleted_at IS NULL'
        );
        // §13: "approval queue and expiry job J-01".
        DB::statement(
            'CREATE INDEX quotations_by_status_validity ON quotations (status, valid_until) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        // `DEV-03`. The constraints and all three indexes go with the table;
        // `parent_id`'s self-reference needs no separate drop for the same
        // reason.
        Schema::dropIfExists('quotations');
    }
};
