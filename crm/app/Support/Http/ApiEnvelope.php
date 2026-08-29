<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `OpenAPI §4.1` — the single-resource success envelope, `meta.request_id`
 * included.
 *
 * ── Why the attribute name is repeated here ────────────────────────────────
 *
 * `AddRequestId` lives in `app/Http`, outside `deptrac`'s `./app/Modules` path,
 * so naming its constant from inside a module turns a clean gate into an
 * uncovered dependency. `RequestAuditContext` hit this first and answered it
 * the same way: duplicate the string, and let a test assert the two are
 * identical. `AuthenticationTest` is that test here.
 *
 * ── Why it lives here, and what moving it cost ─────────────────────────────
 *
 * It began in `Identity\Presentation` and predicted its own move: *"the moment
 * a second module has endpoints, this belongs in a shared layer that deptrac
 * names — which is a boundary change, and boundary changes are their own point
 * rather than a side effect of this one"*. Module 2 made the second copy in
 * `Admin` and recorded the debt as owed **before a third module needs one**.
 * Module 3's customers endpoints are that third module, so this is that point.
 *
 * The two copies had already drifted, which is the argument settled by
 * measurement rather than taste: Admin's copy had no `error()` at all, so the
 * `OpenAPI §5` error envelope existed for one module and silently not the other.
 *
 * Living in `App\Support\Http` also fixes a dependency that pointed the wrong
 * way. {@see ApiExceptionRenderer} is wired from `bootstrap/app.php` and had to
 * reach *into* `Identity\Presentation` to build an error body; now both sit in
 * the same shared place and no module owns the envelope every module returns.
 *
 * `deptrac` names it through `SharedContracts` in both configs, and
 * `Presentation` gained that layer in its ruleset — the narrowest edge that
 * makes this legal, rather than opening Presentation to `App\Support` at large.
 *
 * ⚠️ **The list-query half of the same debt did not move, and that was measured.**
 * `InvalidListQuery` and `InvalidListingQuery` live in **Domain**, whose deptrac
 * ruleset is empty on purpose — "may depend on nothing", the load-bearing rule of
 * `deptrac.layers.yaml`. A shared base in `App\Support` would be a Domain edge,
 * so that half stays duplicated and stays on the register.
 */
final class ApiEnvelope
{
    /** Mirrors `App\Http\Middleware\AddRequestId::ATTRIBUTE`. */
    public const REQUEST_ATTRIBUTE = 'request_id';

    /**
     * `OpenAPI §4.1`'s single-resource envelope — "`GET` detail, `POST` create,
     * and successful `PATCH`/action responses".
     *
     * ⚠️ **`$meta` merges *under* `request_id`, never over it.** §3.3 makes the
     * request id the one thing every response carries, and a caller that
     * happened to pass a `request_id` key would otherwise replace the value the
     * whole audit trail is correlated by. Module 3 is the first caller: `D-35`'s
     * duplicate warning is about the save rather than about the customer, so it
     * belongs beside the request id and not inside `data`.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta  extra response-level metadata; omitted keys change nothing
     */
    public static function single(Request $request, array $data, int $status = 200, array $meta = []): JsonResponse
    {
        return new JsonResponse(
            [
                'data' => $data,
                'meta' => [...$meta, 'request_id' => self::requestId($request)],
            ],
            $status,
        );
    }

    /**
     * `OpenAPI §4.2` — the collection envelope.
     *
     * "Every list endpoint is paginated; an endpoint must never return an
     * unbounded collection", so there is no overload of this that omits the
     * pagination block. All six keys are required by the example in §4.2 and
     * all six are written, including the two the caller could compute, because
     * a client that has to compute them will compute one of them differently.
     *
     * @param  list<array<string, mixed>>  $data
     * @param  array{page: int, per_page: int, total: int, total_pages: int, has_next_page: bool, has_previous_page: bool}  $pagination
     */
    public static function collection(Request $request, array $data, array $pagination): JsonResponse
    {
        return new JsonResponse([
            'data' => $data,
            'meta' => [
                'pagination' => $pagination,
                'request_id' => self::requestId($request),
            ],
        ]);
    }

    /**
     * `OpenAPI §5` — the unified error envelope.
     *
     * `$details` carries the specific stable code §5.1 asks for, which is how a
     * closed set of HTTP codes still says exactly what went wrong.
     *
     * @param  list<array{field?: string, code: string, message: string}>  $details
     */
    public static function error(
        Request $request,
        int $status,
        string $code,
        string $message,
        array $details = [],
    ): JsonResponse {
        $error = ['code' => $code, 'message' => $message];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return new JsonResponse(
            [
                'error' => $error,
                'meta' => ['request_id' => self::requestId($request)],
            ],
            $status,
        );
    }

    private static function requestId(Request $request): ?string
    {
        $id = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        return is_string($id) ? $id : null;
    }
}
