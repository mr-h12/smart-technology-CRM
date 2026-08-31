<?php

declare(strict_types=1);

namespace App\Support\Settings;

/**
 * One tunable number, read from the database with configuration as its floor.
 *
 * **Why this lives in `Support` and not in `Admin`.** `AP-08` makes limits
 * configuration and `D-75` moves them into Module 2's tables, but the modules
 * that *consume* a limit are everyone else — Identity's lockout duration is the
 * first. `deptrac.modules.yaml` gives every module an empty ruleset on purpose:
 * "no module may depend on any other", and each crossing is meant to be a named
 * exception with a reason rather than a habit. Putting the contract in
 * `app/Support` — which is outside both deptrac configs, as `QueueName` and
 * `IdempotentSeeder` already are — lets Admin implement it and Identity consume
 * it without either module learning the other's name.
 *
 * **The alternative, recorded rather than taken:** splitting Admin into
 * `AdminContract` / `AdminDriver` the way Storage and Audit are split, and
 * allowing `IdentityContract → AdminContract`. That is the shape the boundary
 * config anticipates, and it is a decision that needs a `D-xx` and the owner's
 * approval — not something to introduce as a side-effect of a seeding point.
 *
 * **Configuration is the fallback, not the loser.** A missing row, an archived
 * row, or a row somebody typed a word into all fall back to the configured
 * default. `config/identity.php` documents *why* 30 minutes is 30 minutes;
 * the table exists so an administrator can change it without a deployment.
 * Failing closed on a malformed value would turn a typo in a settings screen
 * into a system that locks accounts for zero minutes.
 */
interface SettingReader
{
    /** The stored limit for $key, or the configured value when there is none. */
    public function integer(string $key): int;

    /**
     * The stored limit for $key as a decimal string, or **null when it is unset**.
     *
     * ⚠️ **This one has no configuration floor, and the asymmetry with
     * {@see integer()} is the contract rather than an oversight.** `integer()`
     * serves limits that must always answer — a lockout with no value is a
     * lockout of zero minutes, so configuration underneath it is a safety
     * floor. `OD-08`'s similarity threshold is the opposite case: the
     * documentation says the value is *"empirical, tuned after the first 100
     * customers"*, so **there is no defensible default to fall back to**, and
     * inventing one would put a number nobody chose in front of `D-35`'s
     * warning. `null` means "not configured yet", and the caller is required to
     * do nothing rather than to guess.
     *
     * A string and not a float: `DB-07`. The comparison belongs in the database,
     * which is where the score is produced.
     */
    public function decimal(string $key): ?string;

    /**
     * The stored limit for $key as an integer, or **null when it is unset**.
     *
     * `D-17`'s stale-deal threshold (Module 5, `SystemLimit::StaleDealDays`)
     * is `integer`-typed like {@see integer()}'s callers, but carries
     * {@see decimal()}'s asymmetry: `SystemLimit`'s own docblock lists it
     * among the limits **deliberately left unseeded**, with no defensible
     * number to fall back to — the same reasoning `OD-08` already gave, on a
     * different type. Adding a `config/limits.php` default here would be
     * exactly the thing that docblock warns against, on the technicality that
     * the default lives in a PHP file instead of a database row. `null` means
     * "not configured yet", and — like {@see decimal()} — the caller does
     * nothing rather than guess.
     */
    public function nullableInteger(string $key): ?int;
}
