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
}
