<?php

declare(strict_types=1);

namespace App\Modules\Admin\Presentation;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `OpenAPI §4.1` — the single-resource success envelope, `meta.request_id`
 * included.
 *
 * ── Why this is a second copy ──────────────────────────────────────────────
 *
 * {@see \App\Modules\Identity\Presentation\ApiEnvelope} is the first, and its
 * docblock predicted this moment: *"the moment a second module has endpoints,
 * this belongs in a shared layer that deptrac names — which is a boundary
 * change, and boundary changes are their own point rather than a side effect of
 * this one"*. That is still true, and `CLAUDE.md`'s module-isolation rule now
 * says the same thing from the other direction: a point working inside Admin
 * does not go and edit twelve files in Identity.
 *
 * So the envelope is duplicated **once**, deliberately, and the debt is written
 * down in `CHECKLIST.md`: the move to a shared layer is owed as its own point
 * before a third module needs one. `SettingsEndpointTest` asserts the shape
 * this produces against the documented envelope, so the two copies cannot
 * drift silently in the meantime.
 *
 * The request-id attribute name is duplicated for the reason the first copy
 * records: `AddRequestId` lives outside `deptrac`'s `./app/Modules` path, and
 * naming its constant from inside a module turns a clean gate into an uncovered
 * dependency.
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

    private static function requestId(Request $request): ?string
    {
        $id = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        return is_string($id) ? $id : null;
    }
}
