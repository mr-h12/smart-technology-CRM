<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Database\Schema\Blueprint;

/**
 * The column block §4.8 requires on every business table.
 *
 * Registered as Blueprint macros so a migration states the rule instead of
 * repeating it. Repetition is how a table quietly ends up without soft deletes
 * or without an actor, and DB-01 and DB-02 are not per-table decisions.
 *
 * Infrastructure tables are exempt — DATABASE.md §1 names cache, cache_locks,
 * jobs, job_batches, failed_jobs, sessions and migrations. Soft-deleting a
 * cache row is meaningless.
 */
final class StandardColumns
{
    public static function register(): void
    {
        // D-61: a UUID primary key, time-ordered so inserts stay sequential and
        // the index does not fragment. Laravel's HasUuids generates v7, which is
        // monotonic — verified rather than assumed. Not an auto-increment: row
        // scoping makes enumerable identifiers a real exposure, and ERP-01 needs
        // a module to be extractable later without ID collisions.
        Blueprint::macro('standardId', function (): void {
            /** @var Blueprint $this */
            $this->uuid('id')->primary();
        });

        // DB-02 mandates who and when, and DB-01 forbids physical deletion.
        //
        // The actor columns carry no foreign key yet. DATABASE.md specifies
        // REFERENCES users(id), but the users table Module 1 builds does not
        // exist — Laravel's default one is replaced there, not extended, so a
        // key added now would point at a table that is going away. Module 1
        // adds the constraint with standardActorForeignKeys().
        Blueprint::macro('standardAudit', function (): void {
            /** @var Blueprint $this */
            $this->uuid('created_by')->nullable()->index();
            $this->uuid('updated_by')->nullable();
            $this->timestampsTz();          // DB-08: stored UTC
            $this->softDeletesTz();         // DB-01
        });

        // Everything a business table needs, in the order DATABASE.md lists it.
        Blueprint::macro('standardColumns', function (): void {
            /** @var Blueprint $this */
            $this->standardId();
            $this->standardAudit();
        });

        // ── D-68 precisions ──────────────────────────────────────────────
        //
        // Named rather than spelled out, because Laravel's decimal() defaults to
        // (8,2). Anyone reaching for the obvious method gets two decimal places
        // and silent truncation, which is exactly the defect D-68 was recorded
        // to prevent. money() cannot be got wrong the way decimal() can.

        Blueprint::macro('money', function (string $column, bool $nullable = false) {
            /** @var Blueprint $this */
            $col = $this->decimal($column, Precision::MONEY_TOTAL, Precision::MONEY_SCALE);

            return $nullable ? $col->nullable() : $col;
        });

        Blueprint::macro('fxRate', function (string $column, bool $nullable = false) {
            /** @var Blueprint $this */
            $col = $this->decimal($column, Precision::FX_TOTAL, Precision::FX_SCALE);

            return $nullable ? $col->nullable() : $col;
        });

        Blueprint::macro('percentage', function (string $column, bool $nullable = false) {
            /** @var Blueprint $this */
            $col = $this->decimal($column, Precision::PERCENT_TOTAL, Precision::PERCENT_SCALE);

            return $nullable ? $col->nullable() : $col;
        });

        Blueprint::macro('quantity', function (string $column, bool $nullable = false) {
            /** @var Blueprint $this */
            $col = $this->decimal($column, Precision::QUANTITY_TOTAL, Precision::QUANTITY_SCALE);

            return $nullable ? $col->nullable() : $col;
        });

        // DB-06 and §5.6: every amount stores what it is, in what currency, at
        // what rate, and its base equivalent. Kept together so a table cannot
        // record an amount and forget the context that makes it meaningful —
        // D-09 fixes the rate at creation and forbids recomputing it later, and
        // that is only possible if the rate was stored.
        Blueprint::macro('moneyWithContext', function (string $column): void {
            /** @var Blueprint $this */
            $this->money($column);
            $this->string($column.'_currency', 3);
            $this->fxRate($column.'_fx_rate_at_time');
            $this->money($column.'_base');
        });

        // Called by Module 1, once the real users table exists.
        Blueprint::macro('standardActorForeignKeys', function (string $usersTable = 'users'): void {
            /** @var Blueprint $this */
            $this->foreign('created_by')->references('id')->on($usersTable)->nullOnDelete();
            $this->foreign('updated_by')->references('id')->on($usersTable)->nullOnDelete();
        });
    }
}
