<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Clears the memoised guard before each request resolves a user.
 *
 * ── Why this exists ────────────────────────────────────────────────────────
 *
 * `Auth::viaRequest()` builds a `RequestGuard`, and `RequestGuard::user()`
 * caches the user it resolves. `AuthManager` then keeps that guard for the life
 * of the process. Under PHP-FPM a process serves one request and the cache is
 * invisible; under a long-lived worker, and inside a test, **the second request
 * is answered by the first request's user**.
 *
 * Measured here before this file existed: logging out returned `200`, the
 * `user_sessions` row was soft-deleted correctly, and the *next* call to
 * `/auth/me` with the revoked token still returned `200` — the guard never
 * re-ran. The same shape would have made `SEC-05`'s force-logout and `D-34`'s
 * mid-session deactivation take effect only after a process recycled.
 *
 * This is the same defect class Point 5.4 already paid for once, where
 * `Route::getController()` memoised a controller and its injected permission
 * policy outlived the request.
 *
 * ── Why the whole stack, not just the API ──────────────────────────────────
 *
 * Because the cost is one array assignment and the failure mode is a caller
 * being served as somebody else. There is no request for which keeping the
 * previous request's authenticated user is the right answer.
 */
final class ForgetResolvedGuards
{
    public function __construct(private readonly AuthFactory $auth) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        // Only when the request actually presents a credential to re-resolve.
        //
        // Narrow on purpose: `actingAs()` sets a user on the guard *before* the
        // request is made, and forgetting guards unconditionally throws that
        // away — it broke every test in FileDownloadTest on the first attempt.
        // A request carrying a bearer token is exactly the case where the
        // previous resolution must not be reused, and it is the only case a
        // real client produces.
        //
        // The interface does not declare forgetGuards(); the implementation
        // this application binds is Illuminate\Auth\AuthManager, which does.
        if ($request->bearerToken() !== null && method_exists($this->auth, 'forgetGuards')) {
            $this->auth->forgetGuards();
        }

        return $next($request);
    }
}
