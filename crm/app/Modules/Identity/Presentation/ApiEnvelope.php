<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation;

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
 * ── Why it lives in Identity and not somewhere shared ──────────────────────
 *
 * Because Identity is the only module with endpoints today. The moment a second
 * one has them, this belongs in a shared layer that `deptrac` names — which is
 * a boundary change, and boundary changes are their own point rather than a
 * side effect of this one.
 */
final class ApiEnvelope
{
    /** Mirrors `App\Http\Middleware\AddRequestId::ATTRIBUTE`. */
    public const REQUEST_ATTRIBUTE = 'request_id';

    /** @param  array<string, mixed>  $data */
    public static function single(Request $request, array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse(
            [
                'data' => $data,
                'meta' => ['request_id' => self::requestId($request)],
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
