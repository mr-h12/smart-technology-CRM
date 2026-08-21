<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Support\Database\TestingDatabaseGuard;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * DEV-01 keeps development, staging and production apart. Laravel does not keep
 * their databases apart, and the way it fails is silent: when the file for
 * --env does not exist, LoadEnvironmentVariables leaves .env loaded and says
 * nothing. `php artisan migrate:fresh --env=testing` then reports the testing
 * environment while dropping the development schema.
 *
 * phpunit.xml already forces DB_DATABASE=crm_test, but only for `php artisan
 * test`. Everything below the first two tests therefore runs the real binary in
 * a subprocess — the path phpunit.xml does not cover is the whole point, and a
 * test that stayed inside PHPUnit would be checking the half that was never
 * broken.
 */
final class TestingDatabaseGuardTest extends TestCase
{
    private const PROBE = '.env.guard-probe';

    protected function tearDown(): void
    {
        // The probe is synthetic and holds no credential, but it does declare
        // APP_ENV=testing — left behind, `--env=guard-probe` would keep working
        // and the next reader would take it for a real environment file.
        $path = base_path(self::PROBE);

        if (is_file($path)) {
            unlink($path);
        }

        parent::tearDown();
    }

    // ── Happy path ───────────────────────────────────────────────────────────

    public function test_the_running_suite_is_on_the_test_database_and_the_guard_accepts_it(): void
    {
        self::assertSame('crm_test', DB::connection()->getDatabaseName(),
            'phpunit.xml forces DB_DATABASE=crm_test; the suite is not where it thinks it is.');

        // Throwing here would fail the test on its own; the assertion above is
        // the observable half.
        TestingDatabaseGuard::enforce($this->app);
    }

    public function test_development_still_reaches_the_development_database(): void
    {
        // The guard must not be a blunt instrument. Plain `php artisan`, no
        // --env, has to keep working against crm exactly as before.
        $process = self::runArtisan([], 'echo DB::connection()->getDatabaseName();');

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('crm', $process->getOutput());
        self::assertStringNotContainsString('crm_test', $process->getOutput());
    }

    public function test_the_committed_template_points_a_fresh_checkout_at_a_test_database(): void
    {
        // .env.testing is gitignored because it carries the same credentials as
        // .env, so .env.testing.example is what a new machine copies. A template
        // naming the development database would hand every checkout the defect
        // back, and the guard would then refuse to boot instead of working.
        $template = base_path('.env.testing.example');
        self::assertFileExists($template, 'A developer needs a template to copy to .env.testing.');

        $contents = (string) file_get_contents($template);

        self::assertMatchesRegularExpression('/^APP_ENV=testing$/m', $contents);
        self::assertMatchesRegularExpression(
            '/^DB_DATABASE=\S*'.preg_quote(TestingDatabaseGuard::SUFFIX, '/').'$/m',
            $contents,
            'The template must name a database the guard accepts.',
        );
        // SEC-17: the same rule .env.example is already held to.
        self::assertMatchesRegularExpression('/^DB_PASSWORD=$/m', $contents,
            'A committed template must not carry a credential.');
    }

    // ── Failure and edge cases ───────────────────────────────────────────────

    /** @return array<string, array{0: string}> */
    public static function unsafeDatabases(): array
    {
        return [
            'the development database' => ['crm'],
            'empty, from an unreadable config' => [''],
            'postgres maintenance database' => ['postgres'],
            // Suffix, not substring: these all contain "test" and none of them
            // is a test database.
            'test as a prefix' => ['test_crm'],
            'test inside the name' => ['crm_testing'],
            'a backup of the test database' => ['crm_test_backup'],
        ];
    }

    #[DataProvider('unsafeDatabases')]
    public function test_the_guard_refuses_a_database_that_is_not_a_test_database(string $database): void
    {
        config(['database.connections.pgsql.database' => $database]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("the database '{$database}'");

        TestingDatabaseGuard::enforce($this->app);
    }

    /** @return array<string, array{0: string}> */
    public static function safeDatabases(): array
    {
        return [
            'this project' => ['crm_test'],
            'a parallel worker' => ['crm_worker_2_test'],
            'a throwaway' => ['scratch_test'],
        ];
    }

    #[DataProvider('safeDatabases')]
    public function test_the_guard_accepts_any_database_named_as_a_test_database(string $database): void
    {
        config(['database.connections.pgsql.database' => $database]);

        $this->expectNotToPerformAssertions();
        TestingDatabaseGuard::enforce($this->app);
    }

    public function test_the_guard_leaves_non_testing_environments_alone(): void
    {
        // Development runs on 'crm' by design. A guard that threw here would
        // stop every local artisan command.
        $this->app->instance('env', 'local');
        config(['app.env' => 'local', 'database.connections.pgsql.database' => 'crm']);

        $this->expectNotToPerformAssertions();
        TestingDatabaseGuard::enforce($this->app);
    }

    // ── Regression: the defect itself, through the real binary ───────────────

    public function test_a_testing_env_file_pointed_at_development_refuses_to_boot(): void
    {
        // The audit ran `php artisan migrate:fresh --env=testing` with no
        // .env.testing on disk. Laravel fell back to .env and the command
        // dropped the development schema while calling itself "testing".
        self::writeProbe('crm');

        $process = self::runArtisan(['--env=guard-probe'], 'echo "booted";');

        self::assertNotSame(0, $process->getExitCode(),
            'A testing environment aimed at the development database must not boot.');
        self::assertStringContainsString('Refusing to boot', $process->getOutput().$process->getErrorOutput());
        self::assertStringContainsString("the database 'crm'", $process->getOutput().$process->getErrorOutput());
    }

    public function test_a_destructive_command_is_stopped_before_it_reaches_the_database(): void
    {
        // Not a boot check in the abstract: the actual command that would have
        // done the damage, refused, with the development schema still standing.
        self::writeProbe('crm');

        $before = DB::connection()->getDatabaseName();
        $process = self::runProcess(['php', 'artisan', 'migrate:fresh', '--env=guard-probe', '--force']);
        $output = $process->getOutput().$process->getErrorOutput();

        // The exit code is not the evidence. The probe carries no credentials,
        // so this command would also fail on a refused connection — the point
        // is that it never got that far. Only the guard's own message shows it
        // stopped during boot, and only that assertion fails if the guard goes.
        self::assertStringContainsString('Refusing to boot', $output,
            'migrate:fresh must be stopped by the guard, not by anything downstream of it.');
        self::assertStringNotContainsString('Dropping all tables', $output);
        self::assertNotSame(0, $process->getExitCode());
        self::assertSame($before, DB::connection()->getDatabaseName());
    }

    // ── Behavioural / black box ──────────────────────────────────────────────

    public function test_a_correctly_pointed_testing_env_file_reaches_the_test_database(): void
    {
        // The other half of the fix. Refusing to boot is not enough on its own —
        // testing-mode artisan commands have to actually work, against crm_test.
        self::writeProbe('crm_test');

        $process = self::runArtisan(['--env=guard-probe'], 'echo DB::connection()->getDatabaseName();');

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('crm_test', $process->getOutput());
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * An environment file named something other than "testing" that declares
     * APP_ENV=testing. The console --env option and APP_ENV are two different
     * answers to "which environment is this", and the first version of the
     * guard read only one of them — this shape is what caught that.
     *
     * Synthetic, and deliberately not a copy of .env. Five lines are all the
     * dangerous case needs: a boot that believes it is testing, aimed at
     * $database. Connection::getDatabaseName() reads configuration and does not
     * open a connection, so no host, user or password belongs here — and a
     * probe holding no credential cannot leave one on disk if a run is killed
     * before tearDown.
     *
     * The probe holding no host or credentials is necessary but was not, on its
     * own, sufficient — see runProcess(), which has to clear them from the
     * inherited environment too.
     */
    private static function writeProbe(string $database): void
    {
        file_put_contents(base_path(self::PROBE), implode("\n", [
            'APP_ENV=testing',
            'APP_KEY=',
            'APP_DEBUG=false',
            'DB_CONNECTION=pgsql',
            'DB_DATABASE='.$database,
            '',
        ]));
    }

    /**
     * The subprocess must not inherit the suite's own environment.
     *
     * phpunit.xml sets DB_DATABASE=crm_test as a real environment variable, and
     * a variable that is already set wins over the env file — the same rule
     * that caused the original defect. Inherited, it would override the probe
     * file and every one of these checks would pass without proving anything.
     *
     * @param  list<string>  $options
     */
    private static function runArtisan(array $options, string $code): Process
    {
        return self::runProcess(['php', 'artisan', 'tinker', ...$options, '--execute='.$code]);
    }

    /**
     * Every connection variable is cleared, not only the ones the probe sets.
     *
     * The first version cleared four, and DB_HOST, DB_USERNAME and DB_PASSWORD
     * came through from the parent — Dotenv had put .env's values into the
     * environment when the suite booted. The probe then supplied the database
     * name and the parent supplied working credentials, so with the guard
     * removed `migrate:fresh --env=guard-probe` connected and dropped the
     * development schema. Nothing was lost, because DEV-08 seed data does not
     * exist yet, and that is the only reason.
     *
     * Cleared, the connection falls back to config/database.php's 127.0.0.1
     * where nothing listens. The guard is still what these tests assert; this
     * is the barrier behind it, so that breaking the guard on purpose costs a
     * failed connection instead of a database.
     *
     * @param  list<string>  $command
     */
    private static function runProcess(array $command): Process
    {
        $process = new Process($command, base_path(), [
            'APP_ENV' => false,
            'DB_CONNECTION' => false,
            'DB_DATABASE' => false,
            'DB_URL' => false,
            'DB_HOST' => false,
            'DB_PORT' => false,
            'DB_USERNAME' => false,
            'DB_PASSWORD' => false,
        ]);
        $process->setTimeout(120);
        $process->run();

        return $process;
    }
}
