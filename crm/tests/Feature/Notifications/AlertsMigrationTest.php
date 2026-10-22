<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Module 16, Point 1 — the `alerts` table (`D-153`).
 *
 * An alert is addressed to one user. `D-153`'s five kinds are the only ones the
 * database accepts. One alert stays open per user, kind and record: a repeat
 * raises `occurrences` on the open row rather than adding a second one (§18.3's
 * aggregation), and a cleared row (`cleared_at`) stays — nothing is deleted.
 */
final class AlertsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public const KINDS = ['quotation_expiring', 'account_locked', 'job_failed', 'storage_over_limit', 'backup_failed'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_that_alerts_has_its_columns_and_keys(): void
    {
        self::assertTrue(Schema::hasTable('alerts'), 'D-153: alerts does not exist.');
        foreach (['id', 'user_id', 'kind', 'subject_id', 'details', 'occurrences', 'first_raised_at', 'last_raised_at', 'read_at', 'cleared_at', 'created_at', 'updated_at'] as $column) {
            self::assertTrue(Schema::hasColumn('alerts', $column), "alerts has no {$column}.");
        }

        self::assertSame('uuid', $this->scalar('select data_type from information_schema.columns where table_name = ? and column_name = ?', ['alerts', 'id']));
        self::assertSame('jsonb', $this->scalar('select data_type from information_schema.columns where table_name = ? and column_name = ?', ['alerts', 'details']));
        self::assertSame('users', $this->foreignTable('alerts', 'user_id'));
        foreach (['user_id', 'kind', 'details', 'occurrences', 'first_raised_at', 'last_raised_at'] as $required) {
            self::assertSame('NO', $this->scalar('select is_nullable from information_schema.columns where table_name = ? and column_name = ?', ['alerts', $required]), "alerts.{$required} is required.");
        }
        self::assertFalse(Schema::hasColumn('alerts', 'deleted_at'), 'D-153: an alert is cleared, never deleted — it has no soft delete either.');
    }

    public function test_that_each_kind_d153_names_is_accepted(): void
    {
        $user = $this->user();

        foreach (self::KINDS as $kind) {
            $this->alert($user, $kind, (string) Str::uuid());
        }

        self::assertSame(count(self::KINDS), DB::table('alerts')->count());
    }

    public function test_that_a_kind_d153_does_not_name_is_refused(): void
    {
        $user = $this->user();

        try {
            DB::transaction(fn () => $this->alert($user, 'price_changed', (string) Str::uuid()));
            self::fail('An alert of an unknown kind was stored.');
        } catch (QueryException $e) {
            self::assertSame('23514', $e->getCode());
        }
    }

    public function test_that_occurrences_below_one_is_refused(): void
    {
        $user = $this->user();

        try {
            DB::transaction(fn () => $this->alert($user, 'job_failed', 'job-1', ['occurrences' => 0]));
            self::fail('An alert that never happened was stored.');
        } catch (QueryException $e) {
            self::assertSame('23514', $e->getCode());
        }
    }

    /** §18.3's aggregation: a second open alert for the same user, kind and record is refused; the repeat is the counter. */
    public function test_that_one_alert_stays_open_per_user_kind_and_record(): void
    {
        $user = $this->user();
        $this->alert($user, 'quotation_expiring', 'q-1');

        try {
            DB::transaction(fn () => $this->alert($user, 'quotation_expiring', 'q-1'));
            self::fail('Two open alerts for one user, kind and record were stored.');
        } catch (QueryException $e) {
            self::assertSame('23505', $e->getCode());
        }

        // Another person, another record, or the same record once cleared: each is its own alert.
        $this->alert($this->user(RoleName::TeamLeader), 'quotation_expiring', 'q-1');
        $this->alert($user, 'quotation_expiring', 'q-2');
        DB::table('alerts')->where('user_id', $user->id)->where('subject_id', 'q-1')->update(['cleared_at' => now()]);
        $this->alert($user, 'quotation_expiring', 'q-1');

        self::assertSame(4, DB::table('alerts')->count());
    }

    /** A storage or backup alert has no record of its own; it is still one per user and kind. */
    public function test_that_an_alert_without_a_record_is_also_one_per_user_and_kind(): void
    {
        $user = $this->user();
        $this->alert($user, 'storage_over_limit', null);

        try {
            DB::transaction(fn () => $this->alert($user, 'storage_over_limit', null));
            self::fail('Two open storage alerts for one user were stored.');
        } catch (QueryException $e) {
            self::assertSame('23505', $e->getCode());
        }
    }

    /** DEV-03: this point's migration, found by the table it creates, rolled back by its path. */
    public function test_that_the_migration_rolls_back_and_forward(): void
    {
        self::assertTrue(Schema::hasTable('alerts'), 'alerts is missing before the rollback.');

        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => $this->migration()]));
        self::assertFalse(Schema::hasTable('alerts'), 'down() left alerts behind (DEV-03).');
        self::assertTrue(Schema::hasTable('users'), 'down() must not touch users.');

        self::assertSame(0, Artisan::call('migrate'));
        self::assertTrue(Schema::hasTable('alerts'), 'alerts did not come back.');
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $overrides */
    private function alert(User $user, string $kind, ?string $subject, array $overrides = []): void
    {
        DB::table('alerts')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'kind' => $kind,
            'subject_id' => $subject,
            'details' => '{}',
            'occurrences' => 1,
            'first_raised_at' => now(),
            'last_raised_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }

    private function user(RoleName $role = RoleName::IndoorSales): User
    {
        $user = new User;
        $user->fill([
            'name' => 'Test '.$role->label(),
            'email' => Str::random(8).'@example.test',
            'password' => 'Passw0rd123',
            'role_id' => Role::query()->where('slug', $role->value)->firstOrFail()->id,
            'is_active' => true,
            'is_hidden' => $role->isHidden(),
        ]);
        $user->save();

        return $user;
    }

    /** The one migration file that creates `alerts`, as a path relative to the project root. */
    private function migration(): string
    {
        $found = [];
        foreach (glob(base_path('database/migrations/*.php')) ?: [] as $file) {
            if (str_contains((string) file_get_contents($file), "Schema::create('alerts'")) {
                $found[] = 'database/migrations/'.basename($file);
            }
        }
        self::assertCount(1, $found, 'Exactly one migration should create alerts.');

        return $found[0];
    }

    /** @param array<int, string> $bindings */
    private function scalar(string $sql, array $bindings): ?string
    {
        $row = DB::selectOne($sql, $bindings);

        /** @var list<string|null> $values */
        $values = is_object($row) ? array_values((array) $row) : [];

        return is_object($row) ? (string) ($values[0] ?? '') : null;
    }

    private function foreignTable(string $table, string $column): ?string
    {
        $row = DB::selectOne(
            "select ccu.table_name from information_schema.table_constraints tc
             join information_schema.key_column_usage kcu on kcu.constraint_name = tc.constraint_name
             join information_schema.constraint_column_usage ccu on ccu.constraint_name = tc.constraint_name
             where tc.table_name = ? and tc.constraint_type = 'FOREIGN KEY' and kcu.column_name = ?",
            [$table, $column],
        );

        return is_object($row) && is_string($row->table_name ?? null) ? $row->table_name : null;
    }
}
