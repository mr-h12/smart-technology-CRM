<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The stack §14.2 names, asserted rather than assumed.
 *
 * Every value here is already configured correctly. That is exactly why this
 * exists: configuration that nothing enforces drifts, and each of these has a
 * plausible-looking wrong value that would not announce itself. A cache quietly
 * on the filesystem still serves pages; a database quietly on sqlite still
 * passes tests, right up until it silently truncates a total.
 */
final class EnvironmentTest extends TestCase
{
    public function test_the_database_is_postgresql(): void
    {
        // §14.2 chose PostgreSQL for ACID, JSONB and partitioning. DB-10 needs
        // the last of those, and D-68's NUMERIC precision needs a real decimal
        // type — sqlite's are advisory and stored as doubles.
        self::assertSame('pgsql', config('database.default'));
        self::assertSame('pgsql', DB::connection()->getDriverName());
    }

    public function test_tests_never_touch_the_development_database(): void
    {
        // This failed silently for a while and is the reason the guard exists.
        // PHPUnit's <env> only sets a variable that is not already set, and the
        // compose stack injects DB_DATABASE into the container, so phpunit.xml
        // was ignored and the suite ran against the development database. Every
        // test still passed — which is precisely why it was dangerous. The first
        // test to use RefreshDatabase would have truncated development data.
        $database = DB::connection()->getDatabaseName();

        self::assertStringEndsWith('_test', $database,
            "The suite is connected to '{$database}'. Test databases must end in _test so a "
            .'destructive test cannot reach development data.');
    }

    public function test_the_database_enforces_numeric_precision(): void
    {
        // The reason the driver matters, stated as a test rather than a comment.
        DB::statement('create temporary table _precision_probe (m numeric(18,6))');
        DB::statement("insert into _precision_probe values ('999999999999.123456')");

        $stored = DB::table('_precision_probe')->value('m');
        self::assertIsScalar($stored);

        self::assertSame(
            '999999999999.123456',
            (string) $stored,
            'The database must store D-68 precision exactly, not round it.',
        );
    }

    public function test_redis_backs_cache_sessions_and_queues(): void
    {
        // §14.2 puts cache, sessions, queues, rate limiting and locks on Redis.
        // Tests themselves use array/sync drivers for isolation, so this asserts
        // what the application is configured to use, not what the suite uses.
        $env = self::parseEnvFile();

        self::assertSame('redis', $env['CACHE_STORE'] ?? null);
        self::assertSame('redis', $env['SESSION_DRIVER'] ?? null);
        self::assertSame('redis', $env['QUEUE_CONNECTION'] ?? null);
    }

    public function test_timestamps_are_utc_end_to_end(): void
    {
        // DB-08: stored in UTC, converted for display only. Both halves matter —
        // an application on UTC talking to a database on local time still writes
        // the wrong instant.
        self::assertSame('UTC', config('app.timezone'));
        /** @var object{TimeZone: string}|null $row */
        $row = DB::selectOne('show timezone');
        self::assertNotNull($row);
        self::assertSame('UTC', $row->TimeZone);
    }

    public function test_the_env_template_hands_a_fresh_setup_the_documented_stack(): void
    {
        // .env is gitignored, so .env.example is what a new machine and CI both
        // start from. The skeleton shipped it with sqlite and database-backed
        // sessions, cache and queues — every value §14.2 rules out. Guarding the
        // template matters more than guarding .env, because .env is derived
        // from it and a wrong template is inherited silently.
        $template = self::parseEnvFile('.env.example');

        self::assertSame('pgsql', $template['DB_CONNECTION'] ?? null);
        self::assertSame('redis', $template['CACHE_STORE'] ?? null);
        self::assertSame('redis', $template['SESSION_DRIVER'] ?? null);
        self::assertSame('redis', $template['QUEUE_CONNECTION'] ?? null);
        self::assertSame('UTC', $template['APP_TIMEZONE'] ?? null);

        // SEC-17: a template carrying a real credential would ship it to every
        // checkout.
        self::assertSame('', $template['DB_PASSWORD'] ?? null);
        self::assertSame('', $template['REDIS_PASSWORD'] ?? null);
    }

    /** @return array<string, string> */
    private static function parseEnvFile(string $file = '.env'): array
    {
        $path = base_path($file);
        self::assertFileExists($path, "The application needs {$file}.");

        $values = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (str_starts_with(trim($line), '#') || ! str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $values[trim($key)] = trim($value);
        }

        return $values;
    }
}
