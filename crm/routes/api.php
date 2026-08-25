<?php

declare(strict_types=1);

use App\Http\Middleware\AddRequestId;
use App\Modules\Identity\Presentation\ChangePasswordController;
use App\Modules\Identity\Presentation\ImpersonateController;
use App\Modules\Identity\Presentation\LeaveImpersonationController;
use App\Modules\Identity\Presentation\LoginController;
use App\Modules\Identity\Presentation\LogoutController;
use App\Modules\Identity\Presentation\MeController;
use App\Modules\Identity\Presentation\PasswordChallengeController;
use App\Modules\Identity\Presentation\PermissionController;
use App\Modules\Identity\Presentation\RoleController;
use App\Modules\Identity\Presentation\SessionController;
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
        //
        // `SEC-04` — as of Point 3.3 the body must also carry the
        // `verification_code` mailed by the challenge endpoint below. §9 Flow 0
        // reads "password change → verification code by email → new password →
        // log in again", and this is the third step.
        Route::post('/change-password', ChangePasswordController::class);

        // `SEC-04` step one, and `SEC-11`'s limit on it. The limiter is named
        // rather than inline because `OpenAPI §10` makes the concrete limit a
        // configurable system setting; see AppServiceProvider. It is keyed per
        // account, so this bounds how much mail one session can make the server
        // send — the only abuse an authenticated, target-less endpoint offers.
        Route::post('/change-password/challenge', PasswordChallengeController::class)
            ->middleware('throttle:password-challenge');

        // `SEC-05` — "8-hour session timeout + **active device list** + force
        // logout". The account is the caller's, read from the guard, so there
        // is no permission middleware for the same reason `change-password`
        // carries none: the right to manage your own devices is having a
        // session. Somebody else's devices are §13 screen 2, a different screen
        // with a different permission, and none of these routes takes a target.
        //
        // ⚠️ **On `DELETE`, where D-34 chose `PATCH`.** §7.2 names `POST` and
        // `PATCH` and is silent on `DELETE`; `PATCH /users/{id}/deactivate` is
        // a `PATCH` because a user account is business data `DB-01` forbids
        // deleting, so "deactivate" is a state change and not a removal. A
        // session is the opposite: it is not business data, its whole lifecycle
        // is create and destroy, and the row is soft-deleted underneath exactly
        // as every other revocation in this module is. The verb the owner
        // specified therefore describes what happens, and `DB-01` is untouched
        // — no session row is ever physically removed.
        //
        // ⚠️ **The collection route is registered before the member route, and
        // must stay first.** Laravel matches in registration order; with them
        // swapped, `DELETE /auth/sessions` still resolves correctly because the
        // paths differ in segment count — but the pairing is written down here
        // because the impersonation routes above needed exactly this care and
        // the reason is not obvious from either file.
        Route::get('/sessions', [SessionController::class, 'index']);
        Route::delete('/sessions', [SessionController::class, 'destroyOthers']);
        Route::delete('/sessions/{session}', [SessionController::class, 'destroy']);

        // `SEC-10` — "Login As restricted to Super Admin, with mandatory
        // logging", and one of §3.12 rule 4's nine mandatory audit entries.
        //
        // ⚠️ **`leave` is registered first, and must stay first.** Laravel
        // matches in registration order, so with the two swapped the literal
        // `leave` is captured by `{user}`. Measured with the routes swapped on
        // purpose: the answer is **403**, not the 404 that seemed obvious —
        // the wildcard route's `permission:admin.login_as` refuses the
        // impersonated session, which holds no `admin.*` grant, before anything
        // looks for a user called "leave". Either way the Super Admin is stuck
        // inside somebody else's account until D-29's eight idle hours expire
        // the session. `ImpersonationTest` pins the order.
        //
        // It also carries **no** permission middleware, and that is not an
        // omission: while impersonating, the authenticated user is the target,
        // who holds no `admin.*` grant. Requiring one here would make the
        // impersonation impossible to exit through the API. Authorisation is
        // being in an impersonation session, which only the guard can say.
        Route::post('/impersonate/leave', LeaveImpersonationController::class);

        Route::post('/impersonate/{user}', ImpersonateController::class)
            ->middleware('permission:admin.login_as');
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
// set of callers is §3.11's regardless of which of the two a route names — and
// the labelling is **decision D-78**, recorded in §2.8 and approved with
// Point 3.2.
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

// Module 1 §3.11 — "create / edit role · permissions", the row whose Others and
// Manager columns are both `—`. §3.12 rule 5 is what these endpoints exist for:
// "Permissions live in the database — changing this matrix is a configuration
// change, not a deployment."
//
// ⚠️ **All three carry `admin.manage_roles`, including the two reads, and that
// is narrower than it may look.** §3.11 gives that row to the Super Admin
// alone. Guarding the listings with `admin.create_user` instead would have let
// a Manager read the whole authorisation matrix, which no row in §3.11 grants —
// and D-78's mapping was defensible precisely because it *could not widen
// access*. The same reasoning that permitted D-78 forbids it here.
//
// The cost, stated rather than hidden: a Manager may create users (§3.11) but
// cannot list the roles to pick a `role_id` from. That gap is **pre-existing**
// — Point 3.2 shipped the create endpoint with no role listing at all — and
// closing it needs either a new §3.11 row or an assignable-roles endpoint
// scoped to `admin.create_user`. Recorded as an owner question in CHECKLIST.md,
// not decided here.
Route::middleware('auth')->group(function (): void {
    Route::get('/roles', [RoleController::class, 'index'])
        ->middleware('permission:admin.manage_roles');

    Route::get('/roles/{role}', [RoleController::class, 'show'])
        ->middleware('permission:admin.manage_roles');

    // §7.2's action suffix: "a clear action suffix only when an action is not a
    // normal resource update". A role's grants are a separate collection with
    // their own mandatory audit event, not a field on the role.
    Route::patch('/roles/{role}/permissions', [RoleController::class, 'updatePermissions'])
        ->middleware('permission:admin.manage_roles');

    Route::get('/permissions', [PermissionController::class, 'index'])
        ->middleware('permission:admin.manage_roles');
});
