<?php

declare(strict_types=1);

namespace App\Support\Seeding;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Seeder;

/**
 * The base every seeder in this project extends.
 *
 * `run()` is `final` on purpose. A guard a subclass can forget to call is a
 * guard that will be forgotten — so the only extension point is `seed()`, and
 * reaching it means the environment check has already happened.
 *
 * @see IdempotentSeeder for what implementing this obliges a seeder to
 */
abstract class GuardedSeeder extends Seeder implements IdempotentSeeder
{
    /**
     * `$app` is injected: `Seeder::__invoke()` resolves `run()` through
     * `Container::call()`, so the environment arrives rather than being
     * fetched. That is what lets this be tested against an application whose
     * environment the test chose.
     */
    final public function run(Application $app): void
    {
        if ($this->seedsTestData() && self::isProduction($app)) {
            throw new ProductionSeedRefused(static::class);
        }

        $this->seed();
    }

    /** Idempotent by contract: writes must survive being made twice. */
    abstract protected function seed(): void;

    /**
     * Two values answer "is this production?", and they can disagree.
     *
     * `$app->environment()` reports the console `--env` option when one is
     * given and ignores `APP_ENV`; `config('app.env')` always reports `APP_ENV`
     * from whichever file was loaded. `TestingDatabaseGuard` was written after
     * that gap let a run call itself testing while pointed at the development
     * database, and the same gap sits under this guard: either value naming
     * production is enough to refuse.
     */
    private static function isProduction(Application $app): bool
    {
        if ($app->environment('production')) {
            return true;
        }

        return config('app.env') === 'production';
    }
}
