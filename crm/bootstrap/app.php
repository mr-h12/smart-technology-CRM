<?php

declare(strict_types=1);

use App\Http\Middleware\SetLocaleFromRequest;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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
    ->withMiddleware(function (Middleware $middleware): void {
        // Runs on every request, web and API alike: §14.2 requires Arabic and
        // English from the first release, and a locale resolved per request is
        // what makes RTL/LTR a function of the request rather than of duplicated
        // screens (Coding Standards §11).
        $middleware->append(SetLocaleFromRequest::class);

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
        $middleware->redirectGuestsTo(
            fn (Request $request): ?string => $request->is('api/*') ? null : '/',
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
