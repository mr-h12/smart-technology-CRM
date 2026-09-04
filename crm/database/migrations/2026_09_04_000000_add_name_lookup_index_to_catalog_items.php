<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Module 6, Point 3.2 — the index for the lookup Point 3.1 introduced.
 *
 * ── Why an index exists now, when Point 1.2 argued none should ────────────
 *
 * `create_catalog_items` closed with "No index yet": `DB-09` names "customer ·
 * owner · deal status · dates · entity codes" and this table has none of them,
 * so "an index for a query that does not exist yet is a guess that costs every
 * write". Point 3.1 wrote the query. `CatalogItemDirectory::findProductIdByName()`
 * runs `lower(name) = lower(?)` once for every line of every supplier-quotation
 * save (`D-22`, Points 3.4 and 3.5) — inside the write transaction `PRF-01`
 * caps at 500 ms. `Coding_Standards_EN.md` §7 asks for an index on "each new
 * join, permission scope, filter, sort, and common lookup", verified "with
 * realistic query plans". That is this migration, and `DB-09` is not claimed
 * for it: its list does not name a product name.
 *
 * ── `lower(name)`, because that is the expression the lookup applies ───────
 *
 * A btree over `name` cannot answer `lower(name) = ?`; PostgreSQL needs the
 * index built over the same expression. Blueprint has no functional-index API,
 * so this is written as `DB::statement` — the shape `customers_by_owner`,
 * `deals_by_status` and `enum_lists_ordered` already use.
 *
 * ── `id` is the second column, and that was measured, not assumed ──────────
 *
 * Point 3.1 ends its lookup with `orderBy('id')` and reads one row, which makes
 * `ORDER BY id ASC LIMIT 1` part of the query. A name-only index loses to
 * `catalog_items_pkey` for exactly that reason: walking the primary key in
 * order returns the first match without a sort, so the planner prefers it and
 * filters the whole table on the way. Measured on 50k rows before this file was
 * written — with `(lower(name))` alone, and again with `(lower(name), kind)`,
 * both plans were `Index Scan using ..._pkey`, 15,469 rows removed by filter,
 * 15,521 buffers, 3.6 ms; identical to having no index at all. With `id` as the
 * second column the same query is `Index Scan using ..._by_lower_name`, 4
 * buffers, 0.012 ms. The column is what makes the index reachable.
 *
 * ── `kind` is deliberately not in it ───────────────────────────────────────
 *
 * The lookup also carries `kind = 'product'`, but that is a two-value column
 * applied to the handful of rows an equality on the name has already found —
 * the plan above shows it as a `Filter`, not a missing `Index Cond`. It was
 * measured (plan B) and changed nothing.
 *
 * ── Partial, because `DB-01` means the lookup never reads a dead row ───────
 *
 * `SoftDeletes` puts `deleted_at is null` in every one of these queries, so the
 * deleted rows have no reason to be in the index. Same `WHERE deleted_at IS
 * NULL` as `customers_by_owner`.
 *
 * ── The ceiling ───────────────────────────────────────────────────────────
 *
 * Not `CONCURRENTLY`: a migration runs inside a transaction and PostgreSQL
 * refuses `CREATE INDEX CONCURRENTLY` there. The catalog is a small,
 * human-maintained table (§7.3), so the write lock this takes is measured in
 * milliseconds. A table large enough for that to matter needs its own
 * migration outside a transaction, not a change here.
 *
 * `DEV-03`: `down()` drops it, and `CatalogItemSchemaMigrationTest` runs the
 * whole `migrate:reset` / `migrate` cycle rather than trusting it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE INDEX catalog_items_by_lower_name ON catalog_items (lower(name), id) '
            .'WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        // No `IF EXISTS`: this runs before `create_catalog_items` rolls the
        // table away, so the index is there. A missing one is a defect to hear
        // about, not to pass over.
        DB::statement('DROP INDEX catalog_items_by_lower_name');
    }
};
