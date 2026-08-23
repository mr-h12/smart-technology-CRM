<?php

declare(strict_types=1);

namespace Tests\Feature\Seed;

use App\Modules\Identity\Domain\Personas\TestPersona;
use App\Modules\Identity\Domain\Personas\TestPersonas;
use App\Support\Seeding\GuardedSeeder;
use App\Support\Seeding\IdempotentSeeder;
use App\Support\Seeding\ProductionSeedRefused;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\AssertionFailedError;
use ReflectionClass;
use Tests\TestCase;

/**
 * Point 7.1 — what a seeder is allowed to be in this project.
 *
 * `Coding Standards §7` asks for seed data that is "versioned and repeatable".
 * Repeatable is a property of the *mechanism*, and the mechanism is Module 0
 * infrastructure — which is the whole reason Step 7 can start before the tables
 * `DEV-08` names exist in Modules 1 and 2.
 *
 * Two guarantees, and neither is left to good intentions:
 *
 * 1. **Running a seeder twice leaves the database exactly as one run did.**
 *    Verified by snapshotting every table, not by reading the seeder.
 * 2. **A seeder carrying test users refuses to run in production.**
 *    `DEV-08` puts test users in the same list as roles and currencies, and
 *    the first two belong in production while the third never does.
 *
 * The trap this file is built around: **a seeder that does nothing is
 * trivially idempotent.** So is a snapshot that measures nothing. Every
 * assertion here therefore has a companion that proves it can fail — see
 * the three `..._is_caught` / `..._cannot_pass` tests, which run the verifier
 * against deliberately broken seeders and assert it rejects them.
 */
final class SeederContractTest extends TestCase
{
    use RefreshDatabase;

    /** Synthetic. Not one of §4.7's prefixes, so nothing reads this as real seed data. */
    private const PREFIX = 'ZZ';

    private const YEAR = 2026;

    // ───────────────────────────────────────────────────── idempotency

    public function test_a_seeder_runs_to_the_same_state_however_many_times_it_runs(): void
    {
        $this->assertIdempotent($this->standIn(testData: false, body: function (): void {
            DB::table('document_sequences')->upsert(
                [['prefix' => self::PREFIX, 'year' => self::YEAR, 'last_value' => 0]],
                ['prefix', 'year'],
                ['last_value'],
            );
        }));
    }

    public function test_a_seeder_that_inserts_again_on_the_second_run_is_caught(): void
    {
        // The verifier, verified. Without this the test above proves only that
        // one hand-written upsert happens to be idempotent.
        // insertOrIgnore, not upsert, and the distinction is the whole test.
        // The first version of this stand-in upserted last_value back to 0 and
        // then incremented it, which lands on 1 every single run — a seeder
        // written to be broken that was accidentally correct, and the verifier
        // rightly reported it as idempotent. Ignoring the existing row is what
        // lets the counter actually climb: 1, then 2.
        $failure = $this->failureFrom($this->standIn(testData: false, body: function (): void {
            DB::table('document_sequences')->insertOrIgnore(
                [['prefix' => self::PREFIX, 'year' => self::YEAR, 'last_value' => 0]],
            );
            DB::table('document_sequences')
                ->where('prefix', self::PREFIX)
                ->increment('last_value');
        }));

        self::assertNotNull($failure, 'A seeder whose second run moves a counter was accepted as idempotent.');
        self::assertStringContainsString('second run', $failure->getMessage());
    }

    public function test_a_seeder_that_does_nothing_cannot_pass_as_idempotent(): void
    {
        // The most likely way this whole file becomes decorative: an empty
        // seeder satisfies "run twice, nothing changed" perfectly.
        $failure = $this->failureFrom($this->standIn(testData: false, body: function (): void {}));

        self::assertNotNull($failure, 'A seeder that writes nothing was accepted as idempotent.');
        self::assertStringContainsString('changed nothing', $failure->getMessage());
    }

    public function test_the_snapshot_actually_reads_the_database(): void
    {
        // A snapshot over zero tables is identical to itself forever, which
        // would make every comparison above pass without touching PostgreSQL.
        $tables = array_keys($this->snapshot());

        self::assertContains('document_sequences', $tables);
        self::assertContains('files', $tables);
        self::assertGreaterThan(5, count($tables), 'The snapshot is not reading the schema.');
    }

    // ────────────────────────────────────────────── the production guard

    public function test_test_data_is_refused_in_production(): void
    {
        $this->app->instance('env', 'production');

        $seeder = $this->standIn(testData: true, body: function (): void {
            DB::table('document_sequences')->insert(
                ['prefix' => self::PREFIX, 'year' => self::YEAR, 'last_value' => 0],
            );
        });

        try {
            $this->invoke($seeder);
            self::fail('A test-data seeder ran in production.');
        } catch (ProductionSeedRefused $refused) {
            self::assertStringContainsString('production', $refused->getMessage());
        }

        // The refusal has to happen *before* the write, not after it.
        self::assertSame(0, DB::table('document_sequences')->count(),
            'The guard threw, but the seeder had already written.');
    }

    public function test_test_data_is_refused_when_only_the_configuration_says_production(): void
    {
        // TestingDatabaseGuard was written because these two answers disagree:
        // $app->environment() reports the console --env option, config('app.env')
        // reports APP_ENV from whichever file loaded. A guard reading one of them
        // is a guard with a documented hole.
        config(['app.env' => 'production']);

        $this->expectException(ProductionSeedRefused::class);

        $this->invoke($this->standIn(testData: true, body: function (): void {}));
    }

    public function test_reference_data_still_seeds_in_production(): void
    {
        // The other half. `DEV-08` wants roles, permissions, managed lists and
        // currencies *in* production — a guard that blocked everything would be
        // as broken as one that blocked nothing, and would be discovered on
        // deployment day.
        $this->app->instance('env', 'production');

        $this->invoke($this->standIn(testData: false, body: function (): void {
            DB::table('document_sequences')->insert(
                ['prefix' => self::PREFIX, 'year' => self::YEAR, 'last_value' => 0],
            );
        }));

        self::assertSame(1, DB::table('document_sequences')->count());
    }

    public function test_test_data_still_seeds_outside_production(): void
    {
        $this->app->instance('env', 'local');
        config(['app.env' => 'local']);

        $this->invoke($this->standIn(testData: true, body: function (): void {
            DB::table('document_sequences')->insert(
                ['prefix' => self::PREFIX, 'year' => self::YEAR, 'last_value' => 0],
            );
        }));

        self::assertSame(1, DB::table('document_sequences')->count());
    }

    // ──────────────────────────────────────── the contract, as a boundary

    public function test_every_seeder_carries_the_contract(): void
    {
        $seeders = $this->seederClasses();

        self::assertNotSame([], $seeders, 'The scanner found no seeders, which means it is not scanning.');

        foreach ($seeders as $class) {
            self::assertTrue(
                is_subclass_of($class, IdempotentSeeder::class),
                "{$class} is a seeder that does not implement ".IdempotentSeeder::class.'. '
                .'Coding Standards §7 requires seed data to be repeatable, and a seeder outside '
                .'the contract is one nobody has claimed that of.',
            );
        }
    }

    /**
     * **Rewritten in Point 2.1, and the change is deliberate.**
     *
     * This used to assert that `db:seed` produced *zero* users, because at the
     * time `DatabaseSeeder` called nothing and the only thing that could have
     * created one was Laravel's scaffold — `test@example.com` in a `users`
     * table `design/DATABASE.md §4` says is replaced rather than extended.
     *
     * Module 1 now seeds eight documented personas on purpose, so "zero users"
     * is no longer the property worth holding. **The property that survives is
     * the one the test was really about: nothing invented gets seeded.** Every
     * user that exists after a seed is one `TestPersonas` names, on RFC 6761's
     * `.test` domain, and the scaffold address is absent.
     */
    public function test_seeding_creates_only_documented_personas(): void
    {
        // The eight personas are test data, so the seeder needs the password
        // that DEV-08 keeps out of the repository. Set here rather than left
        // empty, because an unset key makes UserSeeder refuse — which is its
        // own test in IdentitySeederAndModelsTest, not this one.
        Config::set('seeding.test_user_password', 'Passw0rd123');

        // ->run(), not ->assertSuccessful(). Measured, after this test passed
        // with the scaffold deliberately restored: assertSuccessful() only
        // *records* an expected exit code and returns $this — PendingCommand
        // executes the command in __destruct(). Holding it in a variable, which
        // the PHPStan fix above required, moved that destruction past the count
        // below, so the assertion ran before the seeder did. run() executes now
        // and returns the exit code.
        $seed = $this->artisan('db:seed');
        self::assertInstanceOf(PendingCommand::class, $seed);
        self::assertSame(Command::SUCCESS, $seed->run());

        $expected = array_map(
            static fn (TestPersona $persona): string => $persona->email(),
            TestPersonas::all(),
        );
        sort($expected);

        $seeded = DB::table('users')->orderBy('email')->pluck('email')->all();

        self::assertSame($expected, $seeded);
        self::assertNotContains('test@example.com', $seeded, "Laravel's scaffold user is back.");
    }

    // ─────────────────────────────────────────────────────────── the runner

    /**
     * Run it, snapshot, run it again, snapshot again.
     *
     * The first comparison is the one that matters and it is not the obvious
     * one: before asking whether the second run changed anything, this asserts
     * the *first* run did. Otherwise every empty seeder passes.
     */
    private function assertIdempotent(GuardedSeeder $seeder): void
    {
        $before = $this->snapshot();
        $this->invoke($seeder);
        $afterFirst = $this->snapshot();

        self::assertNotSame($before, $afterFirst,
            'The seeder changed nothing, so there is no idempotency here to prove — '
            .'an empty seeder passes this check trivially.');

        $this->invoke($seeder);
        $afterSecond = $this->snapshot();

        self::assertSame($afterFirst, $afterSecond,
            'Coding Standards §7: the second run must leave the database exactly as the first did.');
    }

    /** @return array<string, array{rows: int, digest: string}> */
    private function snapshot(): array
    {
        $snapshot = [];

        foreach ($this->tables() as $table) {
            /** @var object{rows: int, digest: string} $state */
            // The table name comes from pg_class, not from anything a caller
            // supplies. Row-to-text plus an ordered aggregate is what makes the
            // digest independent of insertion order, so a seeder that writes
            // the same rows in a different sequence still compares equal.
            $state = DB::selectOne(<<<SQL
                SELECT count(*) AS rows,
                       md5(coalesce(string_agg(t::text, '|' ORDER BY t::text), '')) AS digest
                FROM "{$table}" t
                SQL);

            $snapshot[$table] = ['rows' => (int) $state->rows, 'digest' => $state->digest];
        }

        return $snapshot;
    }

    /**
     * Every table in the schema, partitions excluded.
     *
     * `audit_log` is a partitioned parent (D-72); selecting from it already
     * covers its partitions, and counting both would double every row.
     *
     * @return list<string>
     */
    private function tables(): array
    {
        /** @var list<object{relname: string}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT c.relname
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public'
              AND c.relkind IN ('r', 'p')
              AND NOT c.relispartition
              AND c.relname <> 'migrations'
            ORDER BY c.relname
            SQL);

        return array_map(static fn (object $row): string => $row->relname, $rows);
    }

    /** The real invocation path, container and all — not $seeder->run(). */
    private function invoke(Seeder $seeder): void
    {
        $seeder->setContainer($this->app);
        $seeder->__invoke();
    }

    /**
     * The assertion failure `assertIdempotent` produced, or null if it passed.
     *
     * Deliberately not `try { … self::fail(…) } catch (AssertionFailedError)` —
     * `self::fail()` throws that same class, so the catch would swallow the
     * failure it exists to report.
     */
    private function failureFrom(GuardedSeeder $seeder): ?AssertionFailedError
    {
        try {
            $this->assertIdempotent($seeder);
        } catch (AssertionFailedError $failure) {
            return $failure;
        }

        return null;
    }

    /**
     * A stand-in for a seeder Module 1 or Module 2 has not written yet.
     *
     * Same device as Point 6.5's stand-in module, and for the same reason: the
     * contract has to be provable before there is anything real to apply it to.
     *
     * @param  Closure(): void  $body
     */
    private function standIn(bool $testData, Closure $body): GuardedSeeder
    {
        return new class($testData, $body) extends GuardedSeeder
        {
            /** @param Closure(): void $body */
            public function __construct(
                private readonly bool $testData,
                private readonly Closure $body,
            ) {}

            public function seedsTestData(): bool
            {
                return $this->testData;
            }

            protected function seed(): void
            {
                ($this->body)();
            }
        };
    }

    /**
     * Every concrete seeder in `database/seeders`.
     *
     * @return list<class-string>
     */
    private function seederClasses(): array
    {
        $classes = [];
        $directory = base_path('database/seeders');

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            /** @var class-string $class */
            $class = 'Database\\Seeders\\'.$file->getBasename('.php');

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Seeder::class)) {
                continue;
            }

            $classes[] = $class;
        }

        sort($classes);

        return $classes;
    }
}
