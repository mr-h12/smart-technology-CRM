<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

/**
 * DEV-01 separates development from the other environments. Laravel does not
 * hold that separation for the database, and the gap is silent.
 *
 * LoadEnvironmentVariables::setEnvironmentFilePath returns false when the file
 * for --env is absent, and checkForSpecificEnvironmentFile then simply returns,
 * leaving .env loaded. So on a machine with no .env.testing:
 *
 *     php artisan migrate:fresh --env=testing   →   env=testing   db=crm
 *
 * It reports the testing environment while dropping the development schema.
 * That is not hypothetical — it was run during the Module 0 audit, and only the
 * absence of seed data (DEV-08) kept it from costing anything.
 *
 * phpunit.xml forces DB_DATABASE=crm_test, but that covers `php artisan test`
 * and nothing else — not tinker, not migrate, not a queue worker, not a
 * scheduler run. This closes the rest: a testing-mode boot pointed at a
 * database that is not a test database refuses to boot at all.
 *
 * It fails closed. An empty or unreadable database name is treated as unsafe,
 * because the danger is a name that turns out to be the development one.
 */
final class TestingDatabaseGuard
{
    /**
     * Test databases are identified by suffix rather than by an exact name, so
     * that a second suite, a parallel worker or a throwaway probe database can
     * exist without being listed here.
     */
    public const SUFFIX = '_test';

    public static function enforce(Application $app): void
    {
        // Only the testing environment is governed here. Development and
        // production keep their own databases and are not this guard's
        // business — narrowing that would break `php artisan` for everyone.
        if (! self::isTesting($app)) {
            return;
        }

        $database = self::configuredDatabase();

        if (str_ends_with($database, self::SUFFIX)) {
            return;
        }

        throw new RuntimeException(
            "Refusing to boot: the testing environment is pointed at the database '{$database}', "
            ."which does not end in '".self::SUFFIX."'. A destructive command such as "
            .'migrate:fresh would have run against it.'
            ."\n\nLaravel falls back to .env without warning when the file for --env is missing. "
            .'Copy .env.testing.example to .env.testing and set DB_DATABASE to a database whose '
            ."name ends in '".self::SUFFIX."'.",
        );
    }

    /**
     * Two different values answer "is this testing?", and they disagree.
     *
     * $app->environment() returns the console --env option when one is given,
     * ignoring APP_ENV entirely; config('app.env') always reports APP_ENV from
     * whichever file was loaded. So `--env=testing` with no .env.testing reads
     * "testing" from the first and "local" from the second, while an .env.ci
     * carrying APP_ENV=testing reads the reverse.
     *
     * Either one naming testing is enough. This was found by probing: the first
     * version of this guard checked only $app->environment() and let a file
     * with APP_ENV=testing run migrate:fresh against the development database.
     */
    private static function isTesting(Application $app): bool
    {
        if ($app->environment('testing')) {
            return true;
        }

        return config('app.env') === 'testing';
    }

    private static function configuredDatabase(): string
    {
        $connection = config('database.default');

        if (! is_string($connection) || $connection === '') {
            return '';
        }

        $database = config("database.connections.{$connection}.database");

        return is_string($database) ? $database : '';
    }
}
