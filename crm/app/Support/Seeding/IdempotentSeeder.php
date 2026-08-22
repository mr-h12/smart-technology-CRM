<?php

declare(strict_types=1);

namespace App\Support\Seeding;

/**
 * What every seeder in this project promises.
 *
 * `Coding Standards §7`: "Seed data is versioned and repeatable: roles,
 * permissions, managed lists, currencies, and test users must be reproducible
 * in non-production environments." Two obligations hide in that sentence, and
 * they pull in opposite directions:
 *
 * - **Repeatable** — running the seeder again must not duplicate a row, bump a
 *   counter, or overwrite an edit somebody made deliberately. Deployment runs
 *   it; a restore runs it; a developer runs it hourly.
 * - **Non-production** — but only for *part* of the list. `DEV-08` names roles,
 *   permissions, sectors, units and currencies alongside test users, and the
 *   first four are exactly what a production database needs on day one. Only
 *   the test users must never arrive there.
 *
 * Implementing this interface is a claim, not an enforcement. What enforces it
 * is `SeederContractTest`, which snapshots every table, runs the seeder twice,
 * and compares — and which refuses to accept a seeder that changed nothing,
 * because doing nothing is the cheapest way to look idempotent.
 *
 * @see GuardedSeeder the base class that makes the production half unavoidable
 */
interface IdempotentSeeder
{
    /**
     * True when this seeder writes fixtures rather than the company's own data.
     *
     * A seeder answering true is refused in production (`DEV-08`). A seeder
     * answering false is expected there: the roles and permissions matrix
     * (`SEC-07`), the managed lists (`DB-05`) and the currencies (`D-52`) are
     * how a fresh production database becomes usable at all.
     */
    public function seedsTestData(): bool;
}
