<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
| One route. D-67 makes the frontend a Vue SPA that consumes /api/v1, so the
| server's only job here is to return the shell and let the router take over.
| Every path that is not an API route or a real file lands here, which is what
| makes deep links work without a hash fragment.
|
| The catch-all deliberately excludes nothing: /api/v1 is registered separately
| and matched first.
*/
Route::view('/{any?}', 'welcome')->where('any', '.*')->name('spa');
