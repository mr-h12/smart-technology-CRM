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

    public function test_the_database_enforces_numeric_precision(): void
    {
        // The reason the driver matters, stated as a test rather than a comment.
        DB::statement('create temporary table _precision_probe (m numeric(18,6))');
        DB::statement("insert into _precision_probe values ('999999999999.123456')");

        self::assertSame(
            '999999999999.123456',
            (string) DB::table('_precision_probe')->value('m'),
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
        self::assertSame('UTC', DB::selectOne('show timezone')->TimeZone);
    }

    /** @return array<string, string> */
    private static function parseEnvFile(): array
    {
        $path = base_path('.env');
        self::assertFileExists($path, 'The application needs a .env file.');

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
