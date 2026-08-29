<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Module 3, Point 3.3 — the trigram extension §10.2's "fuzzy match" needs.
 *
 * ── Why an extension and not PHP ───────────────────────────────────────────
 *
 * §10.2 asks for a **similarity score against a threshold**, not a substring
 * test, so `PostgresSearchDriver`'s `ILIKE` cannot answer it. PHP's two
 * candidates were measured rather than assumed and both are **byte-wise**:
 * `strlen('أحمد')` is 8 where `mb_strlen` is 4, so `levenshtein()` and
 * `similar_text()` score Arabic by its UTF-8 bytes. `pg_trgm` scores by
 * character, which is what an Arabic company name needs.
 *
 * Measured in a throwaway database before this migration was written, with the
 * same folding {@see App\Support\Search\ArabicNormalisation} declares:
 *
 * | pair | raw | folded |
 * |---|---|---|
 * | `احمد للتجاره` vs `أحمد للتجارة` | 0.44 | **1.00** |
 * | `مؤسسة الأمل` vs `شركة الوفاء`   | 0.09 | — |
 *
 * The gap between a real duplicate and an unrelated name is the whole reason a
 * threshold can exist at all, and it is `OD-08` that decides where in that gap
 * it sits — empirically, after the first hundred customers.
 *
 * ── No index, deliberately ─────────────────────────────────────────────────
 *
 * ponytail: no GIN `gin_trgm_ops` index, so the duplicate probe is a sequential
 * scan. `OD-08` scopes the tuning to "the first 100 customers" and the probe
 * runs once per save, not per keystroke. Add the index — on the folded
 * expression, which means repeating the folding constants in DDL — when a
 * measured save gets slow, not before.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
    }

    /**
     * `DEV-03` wants `migrate:rollback` to actually work.
     *
     * `IF EXISTS` rather than a bare `DROP`, because a database that was handed
     * the extension by an administrator rather than by this migration must roll
     * back without an error — and `DROP` refuses when nothing depends on it
     * only in the sense that it errors on absence, not on use. No index or
     * column depends on it: the probe calls `similarity()` at query time.
     */
    public function down(): void
    {
        DB::statement('DROP EXTENSION IF EXISTS pg_trgm');
    }
};
