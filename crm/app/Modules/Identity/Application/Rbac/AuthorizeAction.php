<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Rbac;

use App\Modules\Identity\Domain\Contracts\PermissionRepositoryInterface;
use App\Modules\Identity\Domain\Rbac\Actor;
use App\Modules\Identity\Domain\Rbac\AuthorizationRefused;
use App\Modules\Identity\Domain\Rbac\PermissionDecision;
use App\Modules\Identity\Domain\Rbac\Scope;

/**
 * `SEC-07`'s engine: the one place that answers "may this caller do this".
 *
 * ── The order of the two questions ─────────────────────────────────────────
 *
 * 1. **Is the role exempt?** §3.1 gives Super Admin access that is not in the
 *    cells, which is why §3.3–§3.10 have no Super Admin column to read.
 * 2. **Otherwise, what do the grant rows say?**
 *
 * Nothing else. No `Gate::define` matrix in code (`SEC-07`), no fallback to
 * `PermissionMatrix` (§3.12 rule 5 — that is the seed, not the authority), and
 * no third answer between yes and no.
 *
 * ── Default deny ───────────────────────────────────────────────────────────
 *
 * Every path that is not an explicit grant returns {@see PermissionDecision::denied()}:
 * a user who no longer exists, a role that holds nothing, a scope that does not
 * reach. §3's `—` and `❌` are the same thing, because inventing a difference
 * between "not applicable" and "refused" puts a second meaning into an
 * authorisation table.
 *
 * ── Not cached ─────────────────────────────────────────────────────────────
 *
 * §3.12 rule 5: "changing this matrix is a configuration change, not a
 * deployment." A cache with any lifetime turns that into "a deployment, or a
 * wait". `EloquentPermissionRepository` memoises **within one request** only,
 * which cannot outlive the change.
 */
final readonly class AuthorizeAction
{
    public function __construct(private PermissionRepositoryInterface $permissions) {}

    /**
     * The decision, without throwing. Use this to draw a menu.
     *
     * `$requiredScope` is the reach the *row* needs (`SEC-08`); pass null when
     * the question is only whether the caller may reach the endpoint at all.
     */
    public function decide(
        string $userId,
        string $resource,
        string $action,
        ?Scope $requiredScope = null,
    ): PermissionDecision {
        $actor = $this->permissions->actorFor($userId);

        if (! $actor instanceof Actor) {
            return PermissionDecision::denied();
        }

        if ($actor->hasUnconditionalAccess()) {
            return PermissionDecision::unconditional();
        }

        $decision = PermissionDecision::granted(
            $this->permissions->scopesFor($actor->roleId, $resource, $action),
        );

        if ($requiredScope instanceof Scope && ! $decision->allows($requiredScope)) {
            // Holding `own` when the row needs `team` is not a narrower yes.
            // It is a no, and SEC-08 is the reason it has to be one.
            return PermissionDecision::denied();
        }

        return $decision;
    }

    /**
     * The decision, or the refusal `SEC-09` requires. Use this at the boundary.
     *
     * Two methods rather than a boolean flag: an endpoint must fail closed, and
     * a caller that has to remember to check a return value is a caller that
     * one day will not.
     *
     * @throws AuthorizationRefused
     */
    public function authorize(
        string $userId,
        string $resource,
        string $action,
        ?Scope $requiredScope = null,
    ): PermissionDecision {
        $decision = $this->decide($userId, $resource, $action, $requiredScope);

        if (! $decision->granted) {
            throw AuthorizationRefused::of($resource, $action, $requiredScope);
        }

        return $decision;
    }
}
