<?php

declare(strict_types=1);

namespace App\Modules\Idempotency\Presentation;

use App\Modules\Idempotency\Domain\IdempotencyRefused;
use App\Modules\Idempotency\Domain\IdempotencyStoreInterface;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `OpenAPI §9.1` — `Idempotency-Key` on a critical create.
 *
 * ```php
 * Route::post('/', …)->middleware(['permission:quotation.create', 'idempotency']);
 * ```
 *
 * ── Runs after `auth` and `permission:`, on purpose ────────────────────────
 *
 * §9.1: "Idempotency does not substitute for authorization: every replay is
 * checked against an active session and current permission." Placing this
 * last in the route's chain is what makes that sentence true — a replay
 * whose grant was withdrawn never reaches the store. It also means the actor
 * is known, and §9.1 keys on "the same actor + route + key".
 *
 * ── What "the same payload" means here ─────────────────────────────────────
 *
 * A SHA-256 of the request body as sent. A retry resends the same bytes; two
 * clients spelling one document differently are two requests, and §9.1's
 * `409` is the honest answer to a key shared between them.
 * `ponytail:` no canonical JSON — add a key-sorted encode if a client that
 * re-serialises on retry is ever observed.
 *
 * ── A 5xx is not a "final status" ──────────────────────────────────────────
 *
 * §9.1 stores "the final status and response". A server failure is not one,
 * so the key is released and the client may retry with the same key. Every
 * 2xx and 4xx is final and is what a replay returns — verbatim, including the
 * original `meta.request_id`, which is what "the original response" says.
 */
final class RequireIdempotencyKey
{
    /** Laravel's alias for this middleware, registered in `bootstrap/app.php`. */
    public const ALIAS = 'idempotency';

    public function __construct(private readonly IdempotencyStoreInterface $store) {}

    /**
     * @param  Closure(Request): Response  $next
     *
     * @throws IdempotencyRefused
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || trim($key) === '') {
            throw IdempotencyRefused::keyRequired();
        }

        $userId = $request->user()?->getAuthIdentifier();

        // The route carries `auth`; this is the refusal to guess, as
        // `AuthorizePermission` puts it.
        abort_if(! is_string($userId) && ! is_int($userId), 401);

        $userId = (string) $userId;
        $route = $request->method().' '.$request->path();
        $hash = hash('sha256', $request->getContent());

        $held = $this->store->claim($userId, $route, $key, $hash);

        if ($held !== null) {
            if ($held->requestHash !== $hash || $held->inFlight()) {
                throw IdempotencyRefused::conflict();
            }

            return new JsonResponse($held->body, (int) $held->status);
        }

        // No try/catch: `Illuminate\Routing\Pipeline` renders anything the
        // route throws into a response before it reaches here, so a failure
        // arrives as the 5xx below — a catch block would be code no test can
        // reach.
        $response = $next($request);

        $body = $response instanceof JsonResponse ? $response->getData(true) : null;

        if ($response->getStatusCode() >= 500 || ! is_array($body)) {
            $this->store->release($userId, $route, $key);

            return $response;
        }

        /** @var array<string, mixed> $body */
        $this->store->complete($userId, $route, $key, $response->getStatusCode(), $body);

        return $response;
    }
}
