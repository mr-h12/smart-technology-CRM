<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
| One route. D-67 makes the frontend a Vue SPA that consumes /api/v1, so the
| server's only job here is to return the shell and let the router take over.
| Every path that is not an API route or a real file lands here, which is what
| makes deep links work without a hash fragment.
|
| **The api/ exclusion is load-bearing, and it was missing.** The comment here
| used to say the catch-all excluded nothing because "/api/v1 is registered
| separately and matched first" — true only of API routes that exist. Anything
| else under /api/ fell through to this route and came back as 200 with the SPA
| shell in it: measured, `GET /api/v1/does-not-exist` returned 200 text/html.
| A client cannot tell a removed endpoint from a working one, and a test for a
| route that was never registered passes. Found in Point 5.4, when two tests
| asserting 404 went green before the endpoint existed.
|
| It also shadowed Laravel's own `storage/{path}` route, which is registered in
| a service provider — that is, after this file — and so was unreachable by
| accident rather than by decision. The `local` disk now sets serve => false,
| so it is unreachable by decision.
*/
Route::view('/{any?}', 'welcome')
    ->where('any', '^(?!api(/|$)).*$')
    ->name('spa');
