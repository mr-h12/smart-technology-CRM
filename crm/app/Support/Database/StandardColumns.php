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

        // Called by Module 1, once the real users table exists.
        Blueprint::macro('standardActorForeignKeys', function (string $usersTable = 'users'): void {
            /** @var Blueprint $this */
            $this->foreign('created_by')->references('id')->on($usersTable)->nullOnDelete();
            $this->foreign('updated_by')->references('id')->on($usersTable)->nullOnDelete();
        });
    }
}
