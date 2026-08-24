<?php

declare(strict_types=1);

use App\Http\Middleware\AddRequestId;
use App\Modules\Identity\Presentation\ChangePasswordController;
use App\Modules\Identity\Presentation\LoginController;
use App\Modules\Identity\Presentation\LogoutController;
use App\Modules\Identity\Presentation\MeController;
use App\Modules\Identity\Presentation\UserController;
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
// `auth` resolves the bearer session Module 1 issues (config/auth.php, guard
// `api`). An unauthenticated caller gets 401; an authenticated one who may not
// see the parent gets 404, not 403 — OpenAPI does not let a refusal confirm
// that the file exists.
Route::middleware('auth')->get('/files/{file}/download', DownloadFileController::class);

// Module 1 — §9 Flow 0. `SEC-01`: there is no sign-up route here and there
// never will be; accounts are created by the Manager or Super Admin, which is
// Module 1's user CRUD and not this file.
Route::prefix('auth')->group(function (): void {
    // `SEC-11` — "Rate limiting on login and the API". The limiter is named
    // rather than inline (`throttle:60,1`) because `OpenAPI §10` makes the
    // concrete limit a configurable system setting; see AppServiceProvider.
    Route::post('/login', LoginController::class)->middleware('throttle:login');

    Route::middleware('auth')->group(function (): void {
        Route::post('/logout', LogoutController::class);
        Route::get('/me', MeController::class);

        // §9 Flow 0. No permission middleware: this changes the caller's own
        // credential, and the right to do that is having a session. Changing
        // somebody else's is §3.11's `admin.*`, a different endpoint.
        Route::post('/change-password', ChangePasswordController::class);
    });
});

// Module 1 §3.11 — user administration. §9 Flow 9's employee management, and
// the first listing `User::scopeListable()` actually guards (§3.12 rule 6).
//
// ⚠️ **On the permission names.** §3.11 is the authoritative table and it has
// exactly two user rows — "create user" and "deactivate user" — held by Super
// Admin and Manager, with `—` for every other role. It has **no** row for
// viewing, editing or reactivating a user, so there is no documented
// `user.view.*`, `user.update.*` or `user.reactivate.*` to name here, and
// inventing them would add permissions the seeded matrix does not contain:
// every Manager would be refused while Super Admin passed on unconditional
// access alone, silently deleting §3.11's grant to the Manager.
//
// So the six endpoints are mapped onto the two documented rows. That choice
// cannot over-grant — both rows are held by exactly the same two roles, so the
// set of callers is §3.11's regardless of which of the two a route names — but
// the labels are a judgement call and are **recorded as proposed decision D-78,
// pending owner approval**, not treated as settled.
Route::middleware('auth')->prefix('users')->group(function (): void {
    Route::get('/', [UserController::class, 'index'])
        ->middleware('permission:admin.create_user');

    Route::post('/', [UserController::class, 'store'])
        ->middleware('permission:admin.create_user');

    Route::get('/{user}', [UserController::class, 'show'])
        ->middleware('permission:admin.create_user');

    Route::patch('/{user}', [UserController::class, 'update'])
        ->middleware('permission:admin.create_user');

    // §7.2's action suffix: "a clear action suffix only when an action is not a
    // normal resource update". D-34's switch is exactly that — it revokes every
    // session and writes its own mandatory audit event (§3.12 rule 4), which a
    // PATCH on a boolean field would hide inside a generic update.
    Route::patch('/{user}/deactivate', [UserController::class, 'deactivate'])
        ->middleware('permission:admin.deactivate_user');

    Route::patch('/{user}/reactivate', [UserController::class, 'reactivate'])
        ->middleware('permission:admin.deactivate_user');
});
