<?php

declare(strict_types=1);

namespace Tests\Feature\Seed;

use App\Modules\Admin\Domain\Money\Currencies;
use App\Modules\Admin\Domain\Reference\ManagedList;
use App\Modules\Admin\Domain\Reference\ManagedLists;
use App\Modules\Identity\Domain\PasswordPolicy;
use App\Modules\Identity\Domain\Personas\TestPersona;
use App\Modules\Identity\Domain\Personas\TestPersonas;
use App\Modules\Identity\Domain\Rbac\PermissionMatrix;
use App\Modules\Identity\Domain\Rbac\Role;
use App\Support\Seeding\GuardedSeeder;
use App\Support\Seeding\ProductionSeedRefused;
use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Point 7.5 — one test user per role, and proof that Step 7's four definitions
 * can actually be seeded.
 *
 * `DEV-08` lists test users beside roles, permissions, managed lists and
 * currencies. This is the one item on that list that must **never** reach
 * production, and Point 7.1's `GuardedSeeder` is what makes that structural
 * rather than remembered.
 *
 * **No password appears in any definition.** `SEC-17` keeps secrets out of
 * code, and a literal here would be a credential in the repository that also
 * happens to work — the seeder takes one from the environment, and
 * `.env.example` carries the key with an empty value, the same rule
 * `DB_PASSWORD` and `REDIS_PASSWORD` are already held to. What the definitions
 * do carry is the rule the password must satisfy (`D-28`, `SEC-02`).
 *
 * **The second half of this file is the wiring.** Step 7 has produced four
 * registries and not one row, and "a seeder can consume these" is a claim worth
 * testing before the step is closed. Two stand-in seeders below read the
 * registries through their public API and write rows — with **no edit to any
 * definition**, which is asserted rather than asserted-in-a-comment: the
 * definition namespaces are scanned and must contain no reference to seeding,
 * persistence or the framework at all.
 */
final class TestUsersDataTest extends TestCase
{
    use RefreshDatabase;

    /** Supplied by a test, never stored. The real one comes from the environment. */
    private const PROBE_PASSWORD = 'Seed1234';

    protected function setUp(): void
    {
        parent::setUp();

        // Module 1's `users` and Module 2's `currencies`, `enum_lists` and
        // `permissions` do not exist. These stand in for them so the wiring can
        // be exercised against a real database rather than an array.
        Schema::create('seed_probe_permissions', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->string('role');
            $table->string('scope');
        });

        Schema::create('seed_probe_currencies', function (Blueprint $table): void {
            $table->string('code')->primary();
            $table->string('rounding_unit');
            $table->boolean('rounding_enabled');
        });

        Schema::create('seed_probe_lists', function (Blueprint $table): void {
            $table->string('code')->primary();
            $table->string('list');
            $table->string('label_en');
            $table->string('label_ar');
        });

        Schema::create('seed_probe_users', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('name');
            $table->string('role');
        });
    }

    // ──────────────────────────────────────────── the eight personas

    public function test_there_is_one_test_user_for_each_documented_role(): void
    {
        self::assertSame(
            array_map(static fn (Role $r): string => $r->value, Role::cases()),
            array_map(static fn (TestPersona $p): string => $p->role()->value, TestPersonas::all()),
        );
        self::assertCount(8, TestPersonas::all());
    }

    public function test_every_role_resolves_to_a_persona(): void
    {
        foreach (Role::cases() as $role) {
            self::assertNotNull(TestPersonas::for($role), "No test user covers {$role->value}.");
        }
    }

    public function test_emails_are_unique_and_unmistakably_not_real(): void
    {
        $emails = array_map(static fn (TestPersona $p): string => $p->email(), TestPersonas::all());

        self::assertSame(array_unique($emails), $emails, 'Two personas share an email.');

        foreach ($emails as $email) {
            // RFC 6761 reserves .test as never-resolvable. A real-looking
            // company address in seed data is one mistyped environment away
            // from a password-reset mail to a customer.
            self::assertStringEndsWith('@example.test', $email);
            self::assertNotFalse(filter_var($email, FILTER_VALIDATE_EMAIL), "{$email} is not an address.");
        }
    }

    public function test_every_persona_is_named(): void
    {
        foreach (TestPersonas::all() as $persona) {
            self::assertNotSame('', trim($persona->name()));
        }
    }

    public function test_the_hidden_role_still_has_a_persona(): void
    {
        // §3.12 rule 6 hides Super Admin from every user list. Hidden is not
        // absent — the developer account has to exist to be hidden.
        $persona = TestPersonas::for(Role::SuperAdmin);

        self::assertNotNull($persona);
        self::assertTrue($persona->role()->isHidden());
    }

    // ────────────────────────────────────────────── D-28 / SEC-02

    /** @return array<string, array{0: string, 1: bool}> */
    public static function passwords(): array
    {
        // "8 characters minimum, letters and numbers" (D-28, SEC-02).
        return [
            'eight with both' => ['Seed1234', true],
            'long with both' => ['a-very-long-passphrase-9', true],
            'seven characters' => ['Seed123', false],
            'letters only' => ['password', false],
            'digits only' => ['12345678', false],
            'empty' => ['', false],
            'symbols and digits, no letter' => ['1234-567', false],
        ];
    }

    #[DataProvider('passwords')]
    public function test_the_password_rule_is_the_documented_one(string $password, bool $valid): void
    {
        self::assertSame($valid, PasswordPolicy::isSatisfiedBy($password));
    }

    public function test_no_definition_carries_a_password(): void
    {
        // SEC-17. The persona says who the user is; the environment says how to
        // log in as them.
        foreach (self::definitionFiles() as $file) {
            $source = (string) file_get_contents($file);

            self::assertDoesNotMatchRegularExpression(
                '/(password|secret)\s*(=>|=)\s*[\'"][^\'"]+[\'"]/i',
                $source,
                basename($file).' assigns a literal credential.',
            );
        }
    }

    public function test_the_environment_template_offers_a_home_for_the_password(): void
    {
        $template = (string) file_get_contents(base_path('.env.example'));

        // Present, so nobody invents a literal; empty, the rule DB_PASSWORD and
        // REDIS_PASSWORD are already held to.
        self::assertMatchesRegularExpression('/^SEED_TEST_USER_PASSWORD=$/m', $template);
    }

    // ──────────────────────────────────── the wiring, end to end

    public function test_a_seeder_can_carry_every_definition_into_tables(): void
    {
        $this->invoke($this->referenceSeeder());
        $this->invoke($this->personaSeeder());

        // 217, counted by the code rather than by hand. The 7.2 report and the
        // CHECKLIST entry both said 200, which was my arithmetic over the
        // section tables and was wrong; this assertion is what found it, and
        // both places have been corrected. 57 permissions, 143 explicit scopes
        // and 69 bare checkmarks — then D-80's `currency.view` added 5 explicit
        // scopes (2026-09-16): 58, 148, 69 → 217; D-85's `catalog.import` added one
        // bare checkmark (2026-09-21): → 218.
        self::assertSame(218, DB::table('seed_probe_permissions')->count());
        self::assertSame(3, DB::table('seed_probe_currencies')->count());
        self::assertSame(13, DB::table('seed_probe_lists')->count());
        self::assertSame(8, DB::table('seed_probe_users')->count());

        // Spot the far end of each chain, not just the count.
        // The probe's primary key is permission|role, because a permission has
        // one row per granted role.
        // `all` since D-91 (2026-09-23); `team` before.
        self::assertSame('all', DB::table('seed_probe_permissions')
            ->where('key', 'quotation.view|team_leader')->value('scope'));
        self::assertSame('1', DB::table('seed_probe_currencies')->where('code', 'EGP')->value('rounding_unit'));
        self::assertSame('حكومي', DB::table('seed_probe_lists')->where('code', 'government')->value('label_ar'));
        self::assertSame('super_admin', DB::table('seed_probe_users')
            ->where('email', TestPersonas::for(Role::SuperAdmin)?->email())->value('role'));
    }

    public function test_seeding_does_not_mutate_the_canonical_definitions(): void
    {
        $permissions = PermissionMatrix::all();
        $currencies = Currencies::all();
        $lists = ManagedLists::all();
        $personas = TestPersonas::all();

        $this->invoke($this->referenceSeeder());
        $this->invoke($this->personaSeeder());

        self::assertEquals($permissions, PermissionMatrix::all());
        self::assertEquals($currencies, Currencies::all());
        self::assertEquals($lists, ManagedLists::all());
        self::assertEquals($personas, TestPersonas::all());
    }

    public function test_running_the_reference_seeder_twice_changes_nothing(): void
    {
        $this->invoke($this->referenceSeeder());
        $first = $this->probeDigest();

        $this->invoke($this->referenceSeeder());

        self::assertSame($first, $this->probeDigest(),
            'Coding Standards §7: a second run must leave the database as the first did.');
        self::assertNotSame('', $first, 'The digest is empty, so it is comparing nothing.');
    }

    public function test_the_test_user_seeder_is_refused_in_production(): void
    {
        $this->app->instance('env', 'production');

        try {
            $this->invoke($this->personaSeeder());
            self::fail('Eight fictional employees were seeded into production.');
        } catch (ProductionSeedRefused $refused) {
            self::assertStringContainsString('production', $refused->getMessage());
        }

        self::assertSame(0, DB::table('seed_probe_users')->count());
    }

    public function test_the_reference_seeder_still_runs_in_production(): void
    {
        // Roles, permissions, lists and currencies are what make a fresh
        // production database usable. A guard that stopped these would be
        // discovered on deployment day.
        $this->app->instance('env', 'production');

        $this->invoke($this->referenceSeeder());

        self::assertSame(3, DB::table('seed_probe_currencies')->count());
    }

    public function test_no_definition_knows_anything_about_persistence(): void
    {
        // The decoupling claim, checked. If a registry imported a Seeder, a
        // connection or Illuminate, "consumed without modification" would be
        // true only until the first schema change.
        $offences = [];

        foreach (self::definitionFiles() as $file) {
            // Comments stripped first. The first version read raw text and
            // reported TestPersonas.php for the sentence in its docblock
            // explaining that GuardedSeeder refuses it in production — a
            // scanner that flags the documentation of the rule it enforces is
            // one people learn to ignore. Same fix as the float scanner in
            // Point 7.3: read tokens, not prose.
            $code = self::codeWithoutComments((string) file_get_contents($file));

            foreach (['Illuminate\\', 'Database\\Seeders', 'GuardedSeeder', 'DB::', '->table('] as $needle) {
                if (str_contains($code, $needle)) {
                    $offences[] = basename($file).' references '.$needle;
                }
            }
        }

        self::assertSame([], $offences, implode("\n", $offences));
    }

    public function test_the_definition_scanner_reads_every_registry(): void
    {
        // Two of the assertions above pass on an empty file list.
        $names = array_map('basename', self::definitionFiles());

        foreach (['PermissionMatrix.php', 'Currencies.php', 'ManagedLists.php', 'TestPersonas.php'] as $expected) {
            self::assertContains($expected, $names);
        }

        self::assertGreaterThan(10, count($names));
    }

    // ─────────────────────────────────────────────────────── helpers

    /** Reference data: roles, permissions, lists, currencies. Belongs in production. */
    private function referenceSeeder(): GuardedSeeder
    {
        return $this->standIn(testData: false, body: function (): void {
            foreach (PermissionMatrix::all() as $permission) {
                foreach ($permission->grants() as $role => $grant) {
                    DB::table('seed_probe_permissions')->updateOrInsert(
                        ['key' => $permission->key().'|'.$role],
                        ['role' => $role, 'scope' => $grant->scope()->value],
                    );
                }
            }

            foreach (Currencies::all() as $currency) {
                DB::table('seed_probe_currencies')->updateOrInsert(
                    ['code' => $currency->code()->value],
                    [
                        'rounding_unit' => $currency->rounding()->unit(),
                        'rounding_enabled' => $currency->rounding()->isEnabled(),
                    ],
                );
            }

            foreach (ManagedList::cases() as $list) {
                foreach (ManagedLists::for($list) as $entry) {
                    DB::table('seed_probe_lists')->updateOrInsert(
                        ['code' => $entry->code()],
                        [
                            'list' => $list->value,
                            'label_en' => $entry->labelEn(),
                            'label_ar' => $entry->labelAr(),
                        ],
                    );
                }
            }
        });
    }

    /** Test users. Never production (`DEV-08`). */
    private function personaSeeder(): GuardedSeeder
    {
        return $this->standIn(testData: true, body: function (): void {
            // The rule is checked before anything is written, not assumed.
            if (! PasswordPolicy::isSatisfiedBy(self::PROBE_PASSWORD)) {
                throw new \RuntimeException('D-28: the configured seed password does not satisfy the policy.');
            }

            foreach (TestPersonas::all() as $persona) {
                DB::table('seed_probe_users')->updateOrInsert(
                    ['email' => $persona->email()],
                    ['name' => $persona->name(), 'role' => $persona->role()->value],
                );
            }
        });
    }

    /** @param Closure(): void $body */
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

    private function invoke(GuardedSeeder $seeder): void
    {
        $seeder->setContainer($this->app);
        $seeder->__invoke();
    }

    private function probeDigest(): string
    {
        /** @var object{digest: string|null} $row */
        $row = DB::selectOne(<<<'SQL'
            SELECT md5(string_agg(x, '|' ORDER BY x)) AS digest FROM (
                SELECT p::text AS x FROM seed_probe_permissions p
                UNION ALL SELECT c::text FROM seed_probe_currencies c
                UNION ALL SELECT l::text FROM seed_probe_lists l
            ) rows
            SQL);

        return $row->digest ?? '';
    }

    private static function codeWithoutComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (! is_array($token)) {
                $code .= $token;

                continue;
            }

            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }

            $code .= $token[1];
        }

        return $code;
    }

    /**
     * Every canonical definition Step 7 produced.
     *
     * @return list<string>
     */
    private static function definitionFiles(): array
    {
        $root = dirname(__DIR__, 3).'/app/Modules';
        /** @var list<string> $files */
        $files = [];

        foreach ([
            '/Identity/Domain/Rbac',
            '/Identity/Domain/Personas',
            '/Identity/Domain',
            '/Admin/Domain/Money',
            '/Admin/Domain/Reference',
        ] as $directory) {
            foreach (glob($root.$directory.'/*.php') ?: [] as $file) {
                $files[] = $file;
            }
        }

        sort($files);

        return array_values(array_unique($files));
    }
}
