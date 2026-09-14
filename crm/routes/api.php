<?php

declare(strict_types=1);

use App\Http\Middleware\AddRequestId;
use App\Modules\Admin\Presentation\CurrencyController;
use App\Modules\Admin\Presentation\FxRateController;
use App\Modules\Admin\Presentation\ManagedListController;
use App\Modules\Admin\Presentation\SettingsController;
use App\Modules\Admin\Presentation\SystemLimitController;
use App\Modules\Catalog\Presentation\CatalogItemController;
use App\Modules\Customers\Presentation\CustomerController;
use App\Modules\Deals\Presentation\DealController;
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
use App\Modules\Quotations\Presentation\QuotationController;
use App\Modules\Storage\Presentation\DownloadFileController;
use App\Modules\SupplierQuotations\Presentation\SupplierQuotationController;
use App\Modules\Suppliers\Presentation\SupplierController;
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

    // §13 screen 2 — "last login · devices · IP · browser", and its force
    // logout. `SEC-05` gives every person their own device list at
    // `/auth/sessions`; this is the administrative counterpart, and the two are
    // deliberately different endpoints because they answer to different rows of
    // §3.11.
    //
    // ⚠️ **On the two permission names.** The read carries
    // `admin.create_user` — `D-78`'s mapping, the same row `GET /users/{user}`
    // already names, because §3.11 has no "view user" row to name instead. The
    // termination carries `admin.deactivate_user`, and that is the narrower
    // reading rather than the convenient one: §3.11's deactivate row is the
    // authority that already ends **every** session an account holds (`D-34`),
    // so ending one of them is strictly less than what that row permits.
    // Neither choice can over-grant — §3.11 gives both rows to exactly the same
    // two roles — which is the same argument that made `D-78` defensible.
    Route::get('/{user}/sessions', [UserController::class, 'sessions'])
        ->middleware('permission:admin.create_user');

    Route::delete('/{user}/sessions/{session}', [UserController::class, 'terminateSession'])
        ->middleware('permission:admin.deactivate_user');

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
// Manager columns are both `—`, plus §13 screen 3's "create new roles".
// §3.12 rule 5 is what these endpoints exist for: "Permissions live in the
// database — changing this matrix is a configuration change, not a deployment."
//
// ⚠️ **The listing is the one exception, and it is the owner's decision of
// 2026-08-25 (Point 4.2).** Every other route here carries
// `admin.manage_roles`, a row §3.11 gives to the Super Admin alone. `GET
// /roles` carries `admin.create_user` instead, and `ListRoles` then narrows the
// page to `RoleAssignmentPolicy::assignableBy()` for any caller who does not
// *also* hold `admin.manage_roles`.
//
// That closes a documented grant nobody could exercise: §3.11 lets the Manager
// create users with four named roles, and until now the Manager could not read
// a single role to obtain a `role_id`. It does **not** widen the matrix screen
// — a Manager still sees four rows and no permission triples they may not
// confer — and §3.12 rule 1 is untouched, because `CreateUser` and `UpdateUser`
// re-ask rule 7 about whatever `role_id` comes back. Raised as an owner
// question at Points 3.2, 4.1, 5.2 and 5.3; answered here.
//
// ⚠️ **`DELETE /roles/{role}` is a capability §3.11 does not name.** That row
// reads "create / edit role · permissions" and says nothing about retiring one.
// The endpoint archives (`DB-01` — no row is removed) and carries the same
// Super-Admin-only `admin.manage_roles` as the edit routes, so it cannot widen
// who administers roles. Recorded in CHECKLIST.md as a decision awaiting a
// `D-xx` number rather than absorbed into the matrix silently.
Route::middleware('auth')->group(function (): void {
    Route::get('/roles', [RoleController::class, 'index'])
        ->middleware('permission:admin.create_user');

    Route::post('/roles', [RoleController::class, 'store'])
        ->middleware('permission:admin.manage_roles');

    // ⚠️ **Registered before the wildcard `{role}` routes below, and must stay
    // first** — `/roles/{role}` would otherwise capture nothing here today, but
    // the ordering is written down because `auth/impersonate` needed exactly
    // this care and the reason is not obvious from either file.
    Route::get('/roles/{role}', [RoleController::class, 'show'])
        ->middleware('permission:admin.manage_roles');

    // The labels and the description. The slug is not editable — `UpdateRole`
    // explains why: it is what `Role::tryFrom()` matches on, so changing it
    // silently re-answers §3.12 rule 7 for every Manager.
    Route::patch('/roles/{role}', [RoleController::class, 'update'])
        ->middleware('permission:admin.manage_roles');

    Route::delete('/roles/{role}', [RoleController::class, 'destroy'])
        ->middleware('permission:admin.manage_roles');

    // §7.2's action suffix: "a clear action suffix only when an action is not a
    // normal resource update". A role's grants are a separate collection with
    // their own mandatory audit event, not a field on the role.
    Route::patch('/roles/{role}/permissions', [RoleController::class, 'updatePermissions'])
        ->middleware('permission:admin.manage_roles');

    Route::get('/permissions', [PermissionController::class, 'index'])
        ->middleware('permission:admin.manage_roles');
});

// Module 2 §13 screen 4 — "System Settings".
//
// §3.11 gives `system settings` to the Super Admin and `—` to every other role,
// including the Manager, who holds `FX rates` on the row below it. The
// permission name is the one Point 7.2 seeded, not a new one.
//
// One resource, two verbs: §7.2's "a clear action suffix only when an action is
// not a normal resource update", and setting a field is exactly a normal update.
Route::middleware(['auth', 'permission:admin.system_settings'])->group(function (): void {
    Route::get('/settings', [SettingsController::class, 'index']);
    Route::patch('/settings', [SettingsController::class, 'update']);
});

// §13 screen 5's rounding half — §5.3 files it under "System Settings →
// Currencies", so it carries `admin.system_settings` and not `admin.fx_rates`.
// The Manager holds the second and not the first; the rates themselves are
// Point 3.3, and they are the row the Manager may touch.
Route::middleware(['auth', 'permission:admin.system_settings'])->group(function (): void {
    Route::get('/currencies', [CurrencyController::class, 'index']);
    Route::patch('/currencies/{code}', [CurrencyController::class, 'update']);
});

// §13 screen 5's other half — "manual rate per currency · rate history",
// behind §3.11's **FX rates** row.
//
// ⚠️ **This is the row above's opposite, and the difference is the point.**
// §3.11 gives `system settings` to the Super Admin and `—` to the Manager,
// while `FX rates` on the next line is `✅ Super Admin · ✅ Manager`. So the
// Manager who is refused by `PATCH /currencies/{code}` immediately above is
// **allowed** here, and `FxRateEndpointTest` asserts both directions — a route
// that carried `admin.system_settings` by copy-paste would look right and
// silently delete §3.11's grant to the Manager.
//
// **No `PATCH` and no `DELETE`, by design.** `AP-06` makes a rate append-only
// and Point 1.2 enforces that in the database; a new price is a `POST`. The
// two verbs registered here are the whole resource, and a test asserts the
// router answers 405 to anything else on it.
Route::middleware(['auth', 'permission:admin.fx_rates'])->group(function (): void {
    Route::get('/fx-rates', [FxRateController::class, 'index']);
    Route::post('/fx-rates', [FxRateController::class, 'store']);
});

// §13 screen 6 — "Limits & SLAs", behind §3.11's own row for them.
//
// ⚠️ **`admin.system_limits`, not `admin.system_settings`.** §3.11 lists
// "system settings" and "system limits (SLAs, thresholds)" as two rows. Both
// belong to the Super Admin today and to nobody else, so the two names select
// the same callers — which is exactly why the distinction has to be made now
// rather than when it first matters: §3.12 rule 5 makes regranting a row a
// configuration change, and the day a Manager is given the limits row, an
// endpoint that had quietly named the settings row would not follow.
//
// Point 1.1 made them two tables for the same reason, in its own words: "a
// matrix row may be regranted without a deployment, so the split is what keeps
// the authorisation check at the table instead of inside a WHERE".
Route::middleware(['auth', 'permission:admin.system_limits'])->group(function (): void {
    Route::get('/system-limits', [SystemLimitController::class, 'index']);
    Route::patch('/system-limits', [SystemLimitController::class, 'update']);
});

// `DB-05`'s four lists — §4.2's sectors, §7.3's units and service types, and
// delivery terms.
//
// ⚠️ **The read carries no permission, and the write carries the Super
// Admin's. That asymmetry is a decision, not an omission.** §3.11 has **no
// row** for managed lists — neither for reading one nor for editing one — so
// neither verb has a name to quote, and the two halves are answered
// differently:
//
//   * **Reading is authentication alone**, the way `GET /auth/me` and
//     `POST /auth/change-password` are. §8 puts Customers on six roles' screens
//     and Catalog on five, and not one of those screens can render without a
//     sector or a unit. Behind `admin.system_settings` this module's acceptance
//     criterion would be unreachable: the new sector would appear in settings
//     and nowhere else, which is the opposite of "appears in the customer
//     form". Nothing here is confidential — a sector list is a list of words
//     printed on every quotation §16 generates.
//   * **Writing is `admin.system_settings`**, the Super Admin's row, because
//     changing what the whole company may file a customer under is a settings
//     action and §13 files these lists in screen 4's neighbourhood.
//
// Recorded in `CHECKLIST.md` as a decision awaiting a `D-xx`, on the same terms
// as `DELETE /roles/{role}`: the endpoint is defensible, the matrix does not
// name it, and inventing a permission row would be worse than saying so.
//
// **`DELETE` archives; the only `PATCH` is a restore.** `DB-01` forbids physical
// deletion, and the `DELETE` here does not perform one: it soft-deletes, which
// is why the response says `archived`. This route replaces a comment that used
// to refuse it outright — the refusal's first half was always about a *hard*
// delete, and its second half ("withdrawing a sector customers are filed under
// is a decision with consequences") was a decision the owner has now made the
// other way (2026-08-31, in `CHECKLIST.md` awaiting a `D-xx`). Point 5.1 forced
// the question: `companies` is seeded empty and filled by hand, so a mistyped
// company name was permanent until this existed.
//
// **Nothing cascades** — owner's option (أ): a row already filed under a code
// keeps it, and the code merely stops being offered. §10.4's line about a
// deactivated catalog item, applied to the list that names one.
//
// Renaming a label is still owed and named in `CHECKLIST.md`.
Route::middleware('auth')->group(function (): void {
    Route::get('/managed-lists/{list}', [ManagedListController::class, 'index']);

    Route::post('/managed-lists/{list}', [ManagedListController::class, 'store'])
        ->middleware('permission:admin.system_settings');

    Route::delete('/managed-lists/{list}/{code}', [ManagedListController::class, 'destroy'])
        ->middleware('permission:admin.system_settings');

    // Step 6 Point 6.2b. **One permission for both directions**, the reading
    // `PATCH /customers/{customer}/restore` already applies below: §3.3 line
    // 223 writes the row as a single merged `archive / restore`, so a separate
    // permission here would be a matrix row no document contains. §3.12 rule 4
    // names "restore from archive" among the mandatory audit entries, and §7's
    // Flow 7 gives archiving a **Restore** column — this half was described
    // before it was built.
    //
    // The archived collection carries the same permission and the live one
    // carries none; `ManagedListController::archived()` sets out why.
    Route::get('/managed-lists/{list}/archived', [ManagedListController::class, 'archived'])
        ->middleware('permission:admin.system_settings');

    Route::patch('/managed-lists/{list}/{code}/restore', [ManagedListController::class, 'restore'])
        ->middleware('permission:admin.system_settings');
});

// ── §10 Customers ──────────────────────────────────────────────────────────
//
// `OpenAPI §7.1`'s conventional resource routes, and §3.3's own permission row
// — `customer.view` exists in the matrix, so unlike `D-78`'s user endpoints
// there is nothing to map and nothing to invent.
//
// **No scope argument on the middleware.** `permission:customer.view` asks only
// whether the caller may reach the endpoint at all; which *rows* they get is
// `SEC-08`, and that is answered per row by `CustomerRowScope` from the reach
// the middleware leaves behind. Naming a scope here would ask the wrong
// question — a caller holding `own` would be refused the endpoint outright
// rather than shown their own customers.
Route::middleware('auth')->prefix('customers')->group(function (): void {
    Route::get('/', [CustomerController::class, 'index'])
        ->middleware('permission:customer.view');

    Route::get('/{customer}', [CustomerController::class, 'show'])
        ->middleware('permission:customer.view');

    // §3.3 gives `create` and `edit` their own rows, with different scopes on
    // the same roles — the Team Leader creates under `All` and edits under
    // `Team` — so they are two permissions here and not one `customer.write`.
    //
    // No `Idempotency-Key`. `OpenAPI §9.1` requires it for "deals, quotations,
    // supplier quotations, purchase orders, reports, versions, and actions that
    // change irreversible-equivalent business state"; a customer is on none of
    // those lists and is archivable rather than irreversible (`DB-01`). Read
    // before it was omitted, rather than omitted and explained afterwards.
    //
    // No `If-Match` either: `OpenAPI §9.2` scopes optimistic concurrency to
    // quotations and says the pattern reaches other resources "only through a
    // documented contract update".
    Route::post('/', [CustomerController::class, 'store'])
        ->middleware('permission:customer.create');

    Route::patch('/{customer}', [CustomerController::class, 'update'])
        ->middleware('permission:customer.edit');

    // §3.3's `import (Excel)` row — the Manager alone, with a dash in every
    // other column including the Team Leader's. The permission keeps the
    // document's name; the owner's narrowing of 2026-08-29 is about the file
    // format (CSV through `fgetcsv`, no library), not about who may import.
    //
    // No `Idempotency-Key`, on Point 3.3's reading of `OpenAPI §9.1`: a
    // customer is on none of the resources that require one, and is archivable
    // rather than irreversible (`DB-01`).
    Route::post('/import', [CustomerController::class, 'import'])
        ->middleware('permission:customer.import');

    // Flow 10 · `OpenAPI §7.2`'s suffix, verbatim. §3.3 grants `assign`
    // `All · Team · — · — · — · — · —` — its own row beside `edit`, which five
    // roles hold — so this checks `customer.assign` and never `customer.edit`.
    //
    // ⚠️ Nobody is notified. Flow 10 says "both employees notified" and §18.1
    // limits the MVP to badge counters (customers are not among them) while
    // §18.2's email list is closed at `MAIL-01`…`MAIL-05`. The narrowing is
    // recorded in `CHECKLIST.md` awaiting a `D-xx` (owner, 2026-08-30).
    Route::patch('/{customer}/assign', [CustomerController::class, 'assign'])
        ->middleware('permission:customer.assign');

    // Flow 7 · `OpenAPI §7.2`'s action suffixes. **One permission for both**:
    // §3.3 writes the row as a single merged `archive / restore` granted
    // `All · Team · — · — · — · — · —`, and `PermissionMatrix` carries one
    // `customer.archive` with no `customer.restore` beside it. Inventing a
    // second permission here would be a matrix row no document contains.
    //
    // ⚠️ A Team Leader holds `Team`, which has no mechanism today, so half of
    // Flow 7's "Manager / TL only" is unreachable — the owner's deferral of
    // 2026-08-29, tested rather than left to be discovered.
    Route::patch('/{customer}/archive', [CustomerController::class, 'archive'])
        ->middleware('permission:customer.archive');

    Route::patch('/{customer}/restore', [CustomerController::class, 'restore'])
        ->middleware('permission:customer.archive');
});

// §8 puts a *Suppliers* screen on five roles' lists and §7.1 describes what it
// shows. The permission is §3.7's `view` row — and §3.7 is one table covering
// **both** the catalog and its suppliers, which is why the resource is
// `catalog` and no `supplier.*` permission exists anywhere in the seeded
// matrix. Inventing one here would be a permission no role holds.
//
// **No scope argument on the middleware**, and for a different reason than the
// customers group gives. There it is omitted so a caller holding `own` still
// reaches the screen; here §3.7 grants `All` to every role in both its columns,
// so there is no narrower scope for anyone to hold.
//
// ⚠️ Two roles reach a screen §8 does not list for them: §3.7 grants the CEO
// and the Outdoor Supervisor `catalog.view`, while §8 gives the CEO no catalog
// or supplier screen at all and the Outdoor Supervisor a Catalog but no
// Suppliers. The API follows §3.7 because §3.12 rule 1 makes the API the
// enforcement point; the divergence is recorded in `CHECKLIST.md` awaiting a
// `D-xx`, and it is the sidebar — not this route — that Point 4.1 must decide.
Route::middleware('auth')->prefix('suppliers')->group(function (): void {
    Route::get('/', [SupplierController::class, 'index'])
        ->middleware('permission:catalog.view');

    Route::get('/{supplier}', [SupplierController::class, 'show'])
        ->middleware('permission:catalog.view');

    // §3.7's write row is a single cell — "create · edit · deactivate · set
    // colour ✅" — so all four are one permission and there is no `/deactivate`
    // or `/set-colour` action. `OpenAPI §7.2` reserves an action suffix for
    // what is "not a normal resource update", and both of those are a field on
    // the row. Module 3 needed `/archive` and `/assign` because §3.3 made each
    // of them a separate permission with its own grants; §3.7 does not.
    //
    // **No `Idempotency-Key`.** `OpenAPI §9.1` requires one for "critical POST
    // commands, including creation of deals, quotations, supplier quotations,
    // purchase orders, reports, versions" — a supplier is on none of that list,
    // the same reading Module 3 applied to a customer.
    //
    // **And no DELETE, at any permission.** §3.12 rule 3 forbids hard-deleting
    // a supplier, and `catalog.delete` is seeded with an empty grant array so
    // no role can ever hold it. Deactivation is `is_active` on the PATCH above.
    Route::post('/', [SupplierController::class, 'store'])
        ->middleware('permission:catalog.manage');

    Route::patch('/{supplier}', [SupplierController::class, 'update'])
        ->middleware('permission:catalog.manage');
});

// §7.3's catalog — Module 4 Point 3.1.
//
// The same two permissions as the supplier group above, because §3.7 is **one**
// table covering the catalog and its suppliers: there is no `catalog_item.*`
// resource in the matrix, and a catalog read is authorised by `catalog.view`.
//
// **No scope argument**, for the reason the supplier group gives: every grant
// in §3.7 is `Scope::All`, so there is no narrower scope for anyone to hold.
//
// ⚠️ The same §3.7-vs-§8 divergence applies here and lands differently. §8
// gives the Outdoor Supervisor a Catalog screen and no Suppliers, and gives the
// CEO neither — while §3.7 grants both roles `catalog.view`. The API follows
// §3.7 (§3.12 rule 1 makes the API the enforcement point); the sidebar is
// Point 4.3's decision, and the divergence is recorded in `CHECKLIST.md`
// awaiting a `D-xx`.
//
// Two GET routes and nothing else at this point. `POST` and `PATCH` are Point
// 3.2's, and there is **no DELETE at any permission**: §3.12 rule 3 forbids
// hard-deleting catalog data and `catalog.delete` is seeded with an empty grant
// array, so no role can ever hold it. Deactivation is `is_active` on the row.
Route::middleware('auth')->prefix('catalog-items')->group(function (): void {
    Route::get('/', [CatalogItemController::class, 'index'])
        ->middleware('permission:catalog.view');

    Route::get('/{catalogItem}', [CatalogItemController::class, 'show'])
        ->middleware('permission:catalog.view');

    // Point 3.2. §3.7's write row is a single cell — "create · edit ·
    // deactivate · set colour ✅" — so all of it is one permission and there is
    // no `/deactivate` action: `OpenAPI §7.2` reserves an action suffix for
    // what is "not a normal resource update", and `is_active` is a field on the
    // row. Module 3 needed `/archive` because §3.3 made it a separate
    // permission with its own grants; §3.7 does not.
    //
    // **No `Idempotency-Key`.** `OpenAPI §9.1` requires one for "critical POST
    // commands, including creation of deals, quotations, supplier quotations,
    // purchase orders, reports, versions" — a catalog item is on none of that
    // list, the same reading Points 2.2 and Module 3 applied.
    Route::post('/', [CatalogItemController::class, 'store'])
        ->middleware('permission:catalog.manage');

    Route::patch('/{catalogItem}', [CatalogItemController::class, 'update'])
        ->middleware('permission:catalog.manage');
});

// §3.4 Requests / Deals — Module 5 Points 2.2–2.3.
//
// `OpenAPI §7.1`'s conventional resource routes, and §3.4's own rows —
// `deal.view` for the two reads, `deal.create` and `deal.edit` for the two
// writes, each its own permission with its own scopes rather than one
// `deal.write`. **No scope argument on any of the four**, on `customers`'s
// precedent: the middleware asks only whether the caller may reach the
// endpoint at all, and which *rows* — or, on create, which owner — is `SEC-08`,
// answered by `DealRowScope` from the reach the middleware leaves behind.
//
// The action routes (`/assign`, `/approve`, `/reject`, `/status`, `/documents`)
// were later points in this step; there is no DELETE at any permission, on
// `DB-01` and §3.12 rule 3's usual reading — a deal is deactivated or
// archived, never physically removed, and nothing in §3.4 seeds a `delete`
// grant to spend.
Route::middleware('auth')->prefix('deals')->group(function (): void {
    Route::get('/', [DealController::class, 'index'])
        ->middleware('permission:deal.view');

    Route::get('/{deal}', [DealController::class, 'show'])
        ->middleware('permission:deal.view');

    // §4.4's timeline, read back out of `audit_log` (Point 5.2). **Its own
    // permission, not `deal.view`**: §3.4 gives `view timeline` its own row,
    // and the middleware resolves the scopes for the permission the route
    // names — so a matrix where the two columns diverge finds this route
    // already correct rather than quietly reusing the wrong one. They hold the
    // same seven grants today.
    //
    // Seeded since Module 1 and checked by nothing until now: `view_timeline`
    // appeared in `PermissionMatrix` and one docblock, and nowhere else.
    Route::get('/{deal}/timeline', [DealController::class, 'timeline'])
        ->middleware('permission:deal.view_timeline');

    Route::post('/', [DealController::class, 'store'])
        ->middleware('permission:deal.create');

    Route::patch('/{deal}', [DealController::class, 'update'])
        ->middleware('permission:deal.edit');

    // §3.4 grants `assign_owner` `All · Team · — · — · — · — · —` — its own
    // row beside `edit`, which five roles hold — so this checks
    // `deal.assign_owner` and never `deal.edit`, on `customers`'s precedent.
    //
    // ⚠️ `Team` has no mechanism (Point 2.1): a Team Leader holding only that
    // scope reaches the use case for every deal in the company and finds
    // none of them reachable — half of this permission row is currently
    // unreachable, the same debt `customer.assign`'s `Team` grant already
    // carries.
    Route::patch('/{deal}/assign', [DealController::class, 'assign'])
        ->middleware('permission:deal.assign_owner');

    // Flow 3's decision. §3.4 seeds one permission, `deal.approve`, for both
    // directions — there is no `deal.reject` row to check instead, on
    // `customer.archive`'s precedent for `archive`/`restore` sharing one row.
    Route::patch('/{deal}/approve', [DealController::class, 'approve'])
        ->middleware('permission:deal.approve');

    Route::patch('/{deal}/reject', [DealController::class, 'reject'])
        ->middleware('permission:deal.approve');

    // §4.4's transition. The route carries only `deal.change_status` — the
    // one edge needing a second permission, Delivery → Delivery Complete
    // (`D-14`, `deal.mark_delivery_complete`), is checked inside
    // `ChangeDealStatus` because which permission applies depends on the
    // request body's target status, not on the URL a middleware can see.
    Route::patch('/{deal}/status', [DealController::class, 'changeStatus'])
        ->middleware('permission:deal.change_status');

    // §17's upload flow, its parent chosen. §3.4 seeds no separate "attach
    // document" row, so this carries `deal.edit` — the closest documented
    // permission, and the one AttachDealDocument's own docblock explains
    // choosing over inventing a new grant nothing in §3 asks for.
    Route::post('/{deal}/documents', [DealController::class, 'uploadDocument'])
        ->middleware('permission:deal.edit');
});

// §3.6 Supplier Quotations — Module 6 Point 2.2.
//
// `OpenAPI §7.1`'s conventional resource route, and §3.6's own rows:
// `supplier_quotation.create` for the write. **No scope argument**, on the
// catalog's precedent rather than the deals': §3.6 grants `Scope::All` to every
// role in every column it fills — "a shared screen — not restricted by
// ownership" — so there is no row scoping for the middleware to leave behind
// and no owner for a create to be filed under.
//
// **§3.6 has two documented negative cases and neither needs inventing.** The
// CEO holds `view` and a dash under `create / edit`; the Outdoor Supervisor
// holds a dash in every column. Both are asserted as 403s.
//
// ⚠️ **No `Idempotency-Key`, and this time the omission is a gap rather than a
// reading.** `OpenAPI §9.1` names "supplier quotations" outright among the
// critical POSTs that require one — unlike a customer, a supplier or a catalog
// item, each of which the earlier modules correctly found absent from that
// list. §9.1 also requires a persisted store (actor, route, key, request hash,
// final status and response), replay of the original response, and
// `409 idempotency_conflict` on a reused key with a changed payload. **No such
// infrastructure exists anywhere in this codebase**, and building it inside
// this point would rebuild the oversized point the owner's four-way split just
// removed. Recorded in `CHECKLIST.md` and proposed as its own point.
//
// **And no DELETE, at any permission.** §3.6 seeds no `delete` grant, and
// `DB-01` forbids physically removing business data.
Route::middleware('auth')->prefix('supplier-quotations')->group(function (): void {
    Route::post('/', [SupplierQuotationController::class, 'store'])
        ->middleware('permission:supplier_quotation.create');

    // Point 4.3. §7.2's screen as a list, on the same `view` grant the detail
    // carries — §3.6's read row is `All` for six roles and a dash for the
    // Outdoor Supervisor. Registered before `{supplierQuotation}` so a literal
    // path segment can never be read as an id.
    //
    // No `RowScope`: §3.6 is "a shared screen — not restricted by ownership".
    Route::get('/', [SupplierQuotationController::class, 'index'])
        ->middleware('permission:supplier_quotation.view');

    // Point 2.3. `view`, not `create` — §3.6's two rows are not the same set:
    // the CEO holds `view` as `All` and a dash under `create / edit`, so the
    // caller Point 2.2 refuses is served here. Registered after the collection
    // route so `/` cannot be swallowed by `{supplierQuotation}`.
    Route::get('/{supplierQuotation}', [SupplierQuotationController::class, 'show'])
        ->middleware('permission:supplier_quotation.view');

    // Point 2.4. §3.6's write row is a single cell, "create / edit ✅", so the
    // edit is the create grant — `SupplierController` reads §3.7's write cell
    // the same way. No `If-Match`: `OpenAPI §9.2` scopes optimistic concurrency
    // to quotations and allows adopting it elsewhere only through a documented
    // contract update, and §9.1 lists supplier quotations separately.
    Route::patch('/{supplierQuotation}', [SupplierQuotationController::class, 'update'])
        ->middleware('permission:supplier_quotation.create');

    // Point 5.3 — §17's upload flow with `AttachmentParent::SupplierQuotation`
    // as the parent, carrying §7.2's `pdf_file`.
    //
    // ⚠️ **A third grant, not `create`.** §3.6 seeds `upload_attachment`
    // alongside `view` and `create / edit` — unlike §3.4, which seeds no such
    // row for deals and left `POST /deals/{id}/documents` borrowing `deal.edit`.
    // Here the row exists, so it is the one the route carries, and the CEO —
    // `view` as `All`, no cell under `upload_attachment` — is refused.
    Route::post('/{supplierQuotation}/documents', [SupplierQuotationController::class, 'uploadDocument'])
        ->middleware('permission:supplier_quotation.upload_attachment');
});

// §3.5 Customer Quotations — Module 7 Point 3.4.
//
// `OpenAPI §7.1`'s conventional resource route, the write first. **No scope
// argument**, on the deals' precedent rather than the catalog's: §3.5's
// `create` row is scoped (`All · Team · — · Own · Own · — · —`), the middleware
// asks only whether the caller may reach the endpoint, and which *deal* a
// scoped caller may quote is `SEC-08`, answered inside `CreateQuotation` from
// the reach the middleware leaves behind — a quotation has no owner column, so
// the owner ruled (2026-09-11) that "own" is the deal's `owner_id`.
//
// §3.5's negative cases are all asserted as 403s: the CEO (`view` only), the
// Outdoor Supervisor (a dash in every column) and Procurement (`Asgn` on
// `view`, nothing under `create`). The Team Leader's `Team` is a 403 too, for
// the recorded reason that `team` resolves to nothing until a team entity
// exists — fail-closed, not granted.
//
// `GET /{id}` (3.5), `PATCH /{id}` (3.6) and the `OpenAPI §7.2` actions
// (Step 4) follow in this group; every one that mutates carries `If-Match`
// (§9.2) and answers `409` on a stale token (`DB-12`).
Route::middleware('auth')->prefix('quotations')->group(function (): void {
    // `OpenAPI §9.1` — `idempotency` runs after `permission:` so a replay is
    // still refused when the grant has gone (Point 3.7).
    Route::post('/', [QuotationController::class, 'store'])
        ->middleware(['permission:quotation.create', 'idempotency']);
    // Point 5.4 — the list; the row scope is resolved in `ListQuotations`.
    Route::get('/', [QuotationController::class, 'index'])
        ->middleware('permission:quotation.view');
    Route::get('/{quotation}', [QuotationController::class, 'show'])
        ->middleware('permission:quotation.view');
    Route::patch('/{quotation}', [QuotationController::class, 'update'])
        ->middleware('permission:quotation.edit');
    // Point 4.2 — no `Idempotency-Key` (the owner's Q6 ruling): a repeated
    // submit is already a `409` by the token, and a submit is undone by a return.
    Route::patch('/{quotation}/submit-for-approval', [QuotationController::class, 'submit'])
        ->middleware('permission:quotation.submit_for_approval');
    // Module 8 Point 1.1 — `If-Match` only, as the submit; the Team Leader's
    // `Team` fails closed (`D-a`) until a team entity exists.
    Route::patch('/{quotation}/approve', [QuotationController::class, 'approve'])
        ->middleware('permission:quotation.approve');
    // Module 8 Point 1.2 — the note is the body (`ReturnQuotationRequest`), the
    // rest is the approve's.
    Route::patch('/{quotation}/return', [QuotationController::class, 'return'])
        ->middleware('permission:quotation.return_with_note');
    // Point 4.3 — §9.1 names "versions" among the POSTs that carry the key;
    // `quotation.edit`, because whoever may edit the next draft may open it.
    Route::post('/{quotation}/new-version', [QuotationController::class, 'newVersion'])
        ->middleware(['permission:quotation.edit', 'idempotency']);
    // Point 4.4 — the first business `DELETE`: `D-46` says delete, so it is
    // not an `archive` action; recorded on #103 (Q5) as a contract addition
    // to `OpenAPI §7.1`. `If-Match` only (Q6), as the submit.
    Route::delete('/{quotation}', [QuotationController::class, 'destroy'])
        ->middleware('permission:quotation.delete');
});

// Module 7 Point 6.8 — SmartTermInput's read (Step 6 Q5). Behind
// `quotation.create` because the builder is the only reader; the rows are the
// caller's own, so the scope on the grant does not narrow anything further.
// Not in `OpenAPI §7`: flagged on the point list as a new requirement.
Route::middleware(['auth', 'permission:quotation.create'])
    ->get('user-term-suggestions', [QuotationController::class, 'termSuggestions']);
