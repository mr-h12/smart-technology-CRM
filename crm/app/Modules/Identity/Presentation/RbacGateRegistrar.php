<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation;

use App\Modules\Identity\Application\Rbac\AuthorizeAction;
use App\Modules\Identity\Domain\Rbac\Scope;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Puts `SEC-07`'s matrix behind Laravel's own `Gate`, so `$user->can()`,
 * `@can` and `authorize()` all answer from the database.
 *
 * ── Why `before()` and not 143 `define()` calls ────────────────────────────
 *
 * `SEC-07` is explicit that permissions come from the database, and `CLAUDE.md`
 * repeats it: "a policy may read the database but must never embed the matrix
 * in code." `Gate::define('customer.view', …)` once per triple is the matrix in
 * code — 143 lines that go stale the moment an administrator adds a permission,
 * which §3.12 rule 5 says they may do without a deployment. Defining them from
 * the database at boot is worse still: a query on every request, before anyone
 * has asked a question.
 *
 * `before()` is the only hook Laravel offers that sees an ability it was never
 * told about, which is exactly what a database-driven matrix needs.
 *
 * ── Why it returns null rather than false on a denial ──────────────────────
 *
 * `false` from `before()` is final and would stop any policy a later module
 * registers from *adding* a row-level refusal on top. `null` means "no
 * opinion", and an ability nothing has defined is denied anyway — so the
 * default is still deny, and it stays composable. **A grant here is not a
 * complete authorisation:** it answers `resource.action`, and `SEC-08`'s row
 * check is a separate question the caller must still ask.
 */
final class RbacGateRegistrar
{
    public static function register(Gate $gate, AuthorizeAction $authorize): void
    {
        $gate->before(function (Authenticatable $user, string $ability) use ($authorize): ?bool {
            $parts = explode('.', $ability);

            // Not one of ours. A gate name that is not `resource.action` — a
            // policy ability like `view` on a model — must fall through
            // untouched rather than be denied by a matrix that has no row for it.
            if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
                return null;
            }

            $userId = $user->getAuthIdentifier();

            if (! is_string($userId) && ! is_int($userId)) {
                return null;
            }

            $decision = $authorize->decide((string) $userId, $parts[0], $parts[1]);

            // true grants; null declines to answer, which leaves the default
            // deny in place without closing the door on a later policy.
            return $decision->granted ? true : null;
        });
    }

    /**
     * The scope-aware form, for a caller that has a row in hand (`SEC-08`).
     *
     * Separate from the gate because Laravel's `before()` signature carries the
     * ability as one string, and folding a scope into it would mean parsing
     * `customer.view.team` there — which reintroduces the triple as a magic
     * string in every call site instead of a typed {@see Scope}.
     */
    public static function allowsScope(
        AuthorizeAction $authorize,
        string $userId,
        string $resource,
        string $action,
        Scope $required,
    ): bool {
        return $authorize->decide($userId, $resource, $action, $required)->granted;
    }
}
