<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation;

use App\Modules\Identity\Application\Rbac\AuthorizeAction;
use App\Modules\Identity\Domain\Rbac\AuthorizationAttribute;
use App\Modules\Identity\Domain\Rbac\AuthorizationRefused;
use App\Modules\Identity\Domain\Rbac\Scope;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * `SEC-09` and §3.12 rule 1 — "Enforcement happens at the API. Hiding a button
 * is not the same as blocking an action."
 *
 * ```php
 * Route::middleware('permission:customer.view')->get(...);        // any scope
 * Route::middleware('permission:customer.view,team')->get(...);   // needs team reach
 * ```
 *
 * ── Why the ability is a route argument and not a policy class ─────────────
 *
 * `SEC-07` puts the matrix in the database, so there is nothing for a policy
 * class to encode — the pair `resource.action` is the whole question and the
 * answer is a row. A policy per resource would be 57 classes whose bodies all
 * read "ask the database", and each one an opportunity to ask it differently.
 *
 * ── What it leaves behind ──────────────────────────────────────────────────
 *
 * The decision, on {@see AuthorizationAttribute::NAME}. `SEC-08`'s row-level
 * filtering needs the *reach*, and the middleware has already resolved it;
 * making the query resolve it again is two answers to one question.
 */
final class AuthorizePermission
{
    /** Laravel's alias for this middleware, registered in `bootstrap/app.php`. */
    public const ALIAS = 'permission';

    public function __construct(private readonly AuthorizeAction $authorize) {}

    /**
     * @param  Closure(Request): Response  $next
     * @param  string  $ability  `resource.action`, as §3.2 writes it
     * @param  string|null  $requiredScope  the reach the row needs, when the route knows it
     *
     * @throws AuthorizationRefused
     */
    public function handle(Request $request, Closure $next, string $ability, ?string $requiredScope = null): Response
    {
        [$resource, $action] = self::split($ability);

        $user = $request->user();

        // The route should carry `auth` as well, and this is not that check
        // rewritten — it is the refusal to guess. An unauthenticated caller has
        // no role, so there is no matrix row to consult and 401 is the honest
        // answer rather than a 403 that implies somebody was identified.
        abort_if($user === null, 401);

        $userId = $user->getAuthIdentifier();

        abort_if(! is_string($userId) && ! is_int($userId), 401);

        $scope = $requiredScope === null ? null : Scope::tryFrom($requiredScope);

        // A route that names a scope the enum does not have is a programming
        // error, and failing closed on it would hide the typo behind a 403 that
        // looks like a permission problem.
        if ($requiredScope !== null && ! $scope instanceof Scope) {
            throw new InvalidArgumentException(
                'Unknown scope on a route: §3.2 defines own, team, all, out and asgn.',
            );
        }

        $decision = $this->authorize->authorize((string) $userId, $resource, $action, $scope);

        $request->attributes->set(AuthorizationAttribute::NAME, $decision);

        return $next($request);
    }

    /**
     * `resource.action`, split once and validated.
     *
     * @return array{string, string}
     */
    private static function split(string $ability): array
    {
        $parts = explode('.', $ability);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new InvalidArgumentException(
                'A permission is written resource.action (§3.2); the scope is a separate argument.',
            );
        }

        return [$parts[0], $parts[1]];
    }
}
