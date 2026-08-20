<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Database\StandardColumns;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // DB-01 and DB-02 apply to every business table, so the columns are a
        // macro rather than something each migration remembers to repeat.
        StandardColumns::register();
    }
}
