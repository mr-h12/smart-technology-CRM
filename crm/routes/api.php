<?php

declare(strict_types=1);

use App\Http\Middleware\AddRequestId;
use App\Modules\Storage\Presentation\DownloadFileController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

/*
| Every route here is already prefixed /api/v1 by bootstrap/app.php.
|
| API-02 requires versioning from day one, and OpenAPI §2.1 is explicit that a
| breaking change means /api/v2 rather than silently repurposing a field. The
| prefix is set once at registration so no route can accidentally be published
| unversioned.
*/

// A liveness probe for the SPA, and the first thing to demonstrate the response
// envelope OpenAPI §4.1 requires: a `data` object and a `meta.request_id`.
// It is deliberately not /health — ST-08 wants a health endpoint that reports
// each dependency, and that belongs with the modules that own those services.
Route::get('/ping', function (): JsonResponse {
    return new JsonResponse([
        'data' => [
            'service' => 'crm',
            'time' => now()->toIso8601String(),   // DB-08: UTC, converted for display only
        ],
        'meta' => [
            'request_id' => request()->attributes->get(AddRequestId::ATTRIBUTE),
        ],
    ]);
});

// §17: "No direct access — every file served through a permission-checking API".
// D-38 makes that check the parent entity's, which is why the route carries no
// permission name of its own: the answer belongs to whichever module owns the
// deal, quotation, purchase order or report the file hangs from.
//
// `auth` is the session guard, because that is the only guard there is until
// Module 1. An unauthenticated caller gets 401; an authenticated one who may
// not see the parent gets 404, not 403 — OpenAPI does not let a refusal confirm
// that the file exists.
Route::middleware('auth')->get('/files/{file}/download', DownloadFileController::class);
