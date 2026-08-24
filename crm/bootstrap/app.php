<?php

declare(strict_types=1);

use App\Http\Middleware\ForgetResolvedGuards;
use App\Http\Middleware\SetLocaleFromRequest;
use App\Modules\Audit\Presentation\EnsureAuditPartitionsCommand;
use App\Modules\Identity\Domain\Administration\InvalidListQuery;
use App\Modules\Identity\Domain\Administration\UserAdministrationRefused;
use App\Modules\Identity\Domain\Authentication\AuthenticationRefused;
use App\Modules\Identity\Domain\Authentication\PasswordChangeRefused;
use App\Modules\Identity\Domain\Rbac\AuthorizationRefused;
use App\Modules\Identity\Presentation\AuthorizePermission;
use App\Support\Http\ApiExceptionRenderer;
use App\Support\Performance\MeasureApiLatencyCommand;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // API-02: versioned from day one. Set here rather than per route, so
        // nothing can be published unversioned by omission.
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Laravel discovers commands in app/Console/Commands and nowhere else, and
    // this project puts a module's entry points inside the module (AP-02). So
    // every module command is named here; without this the class exists, the
    // scheduler resolves its signature, and `artisan` still answers "The
    // command ... does not exist".
    ->withCommands([
        EnsureAuditPartitionsCommand::class,
        // PRF-01's measurement tool. Not a module command — it belongs to no
        // business domain and sits in app/Support, which Laravel discovers just
        // as little as it discovers app/Modules.
        MeasureApiLatencyCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // Runs on every request, web and API alike: §14.2 requires Arabic and
        // English from the first release, and a locale resolved per request is
        // what makes RTL/LTR a function of the request rather than of duplicated
        // screens (Coding Standards §11).
        $middleware->append(SetLocaleFromRequest::class);

        // Before anything can ask who the caller is. A RequestGuard memoises
        // the user it resolved, and AuthManager memoises the guard, so without
        // this a long-lived worker answers the second request as the first
        // request's user — measured on logout, which returned 200 and then let
        // the revoked token straight back in.
        $middleware->append(ForgetResolvedGuards::class);

        // OpenAPI §3.3 — every response carries a server-generated request id.
        $middleware->append(App\Http\Middleware\AddRequestId::class);

        // An unauthenticated API call must be answered, not redirected.
        // Laravel's default sends a guest to route('login'); this application
        // has no such route (D-67 puts login in the SPA), so the redirect threw
        // RouteNotFoundException and the caller received **500 instead of 401**
        // — measured in Point 5.4, on the first request to a route behind
        // `auth`. Returning null keeps the AuthenticationException unresolved
        // into a redirect, which the handler then renders as the 401 JSON
        // OpenAPI §4 requires.
        // SEC-09 · §3.12 rule 1 — the API-level permission check, as a route
        // alias so a protected route reads `permission:customer.view` and the
        // ability it needs is visible in routes/api.php rather than buried in a
        // controller.
        $middleware->alias([AuthorizePermission::ALIAS => AuthorizePermission::class]);

        $middleware->redirectGuestsTo(
            fn (Request $request): ?string => $request->is('api/*') ? null : '/',
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => ApiExceptionRenderer::applies($request),
        );

        // OpenAPI §5: every non-2xx answer uses the unified envelope. Laravel's
        // defaults do not — a validation failure is `{"message":…,"errors":…}`
        // and a 401 is `{"message":"Unauthenticated."}`, neither of which has a
        // `meta.request_id` a support report can quote. These four are the
        // shapes Module 1 can produce; §5.1's other nine arrive with the
        // modules that can raise them.
        $exceptions->render(
            fn (AuthenticationRefused $e, Request $request): ?JsonResponse => ApiExceptionRenderer::applies($request)
                ? ApiExceptionRenderer::refusal($e, $request)
                : null,
        );

        $exceptions->render(
            fn (AuthorizationRefused $e, Request $request): ?JsonResponse => ApiExceptionRenderer::applies($request)
                ? ApiExceptionRenderer::authorization($e, $request)
                : null,
        );

        $exceptions->render(
            fn (PasswordChangeRefused $e, Request $request): ?JsonResponse => ApiExceptionRenderer::applies($request)
                ? ApiExceptionRenderer::passwordChange($e, $request)
                : null,
        );

        $exceptions->render(
            fn (UserAdministrationRefused $e, Request $request): ?JsonResponse => ApiExceptionRenderer::applies($request)
                ? ApiExceptionRenderer::administration($e, $request)
                : null,
        );

        $exceptions->render(
            fn (InvalidListQuery $e, Request $request): ?JsonResponse => ApiExceptionRenderer::applies($request)
                ? ApiExceptionRenderer::invalidListQuery($e, $request)
                : null,
        );

        $exceptions->render(
            fn (ValidationException $e, Request $request): ?JsonResponse => ApiExceptionRenderer::applies($request)
                ? ApiExceptionRenderer::validation($e, $request)
                : null,
        );

        $exceptions->render(
            fn (AuthenticationException $e, Request $request): ?JsonResponse => ApiExceptionRenderer::applies($request)
                ? ApiExceptionRenderer::unauthenticated($e, $request)
                : null,
        );

        // Registered before ValidationException would ever see it: this is a
        // subclass of HttpException, not of ValidationException, but the order
        // of `render` callbacks is the order they are tried, and the throttle
        // response carries a `Retry-After` §5.1 makes mandatory.
        $exceptions->render(
            fn (ThrottleRequestsException $e, Request $request): ?JsonResponse => ApiExceptionRenderer::applies($request)
                ? ApiExceptionRenderer::throttled($e, $request)
                : null,
        );
    })->create();
