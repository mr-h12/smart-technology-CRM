<?php

declare(strict_types=1);

namespace Tests\Feature\Quotations;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Module 7, Point 6.8 — `user_term_suggestions` (Step 6 Q5, the owner's
 * default of 2026-09-13): the terms a person has typed into a quotation, kept
 * per person and per field so the builder can offer them again. `Design
 * System §6.3`: "reusable suggestions; suggestions never force a structured
 * payment schedule".
 *
 * Three things are proved here: the table (and `DEV-03`'s rollback), the
 * write — saving a quotation upserts `(user_id, field, term)` inside the same
 * transaction — and the read, `GET /api/v1/user-term-suggestions?field=`,
 * which returns the caller's own rows only, newest first, capped at 20.
 */
final class UserTermSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/user-term-suggestions';

    private const QUOTATIONS = '/api/v1/quotations';

    private const PASSWORD = 'Passw0rd123';

    /** @var array<string, User> */
    private array $users = [];

    private string $customerId;

    private string $dealId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->currency();
        $this->customerId = $this->customer();
        $this->dealId = $this->deal($this->customerId);
    }

    // ───────────────────────────────────────────────────────────────── schema

    public function test_that_the_table_exists_with_its_unique_key(): void
    {
        self::assertTrue(Schema::hasTable('user_term_suggestions'));

        foreach (['id', 'user_id', 'field', 'term', 'last_used_at', 'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at'] as $column) {
            self::assertTrue(Schema::hasColumn('user_term_suggestions', $column), $column.' is missing (`DB-01`, `DB-02`).');
        }

        $userId = $this->userWith(RoleName::Manager)->getKey();
        self::assertIsString($userId);

        $this->row($userId, 'warranty', 'One year');

        $this->expectException(QueryException::class);
        $this->row($userId, 'warranty', 'One year');
    }

    // ────────────────────────────────────────────────────────────────── DEV-03

    public function test_that_the_migration_rolls_back_and_forward(): void
    {
        Artisan::call('migrate:reset', ['--force' => true]);
        self::assertFalse(Schema::hasTable('user_term_suggestions'));

        self::assertSame(0, Artisan::call('migrate'));
        self::assertTrue(Schema::hasTable('user_term_suggestions'));
    }

    // ────────────────────────────────────────────────────────────── the write

    public function test_that_saving_a_quotation_remembers_its_terms_for_the_actor(): void
    {
        $this->postJson(self::QUOTATIONS, $this->payload([
            'payment_terms' => '50% advance',
            'warranty' => 'One year',
            'delivery_terms' => '',
        ]), $this->bearerFor(RoleName::Manager))->assertStatus(201);

        $userId = $this->userWith(RoleName::Manager)->getKey();

        $rows = DB::table('user_term_suggestions')->where('user_id', $userId)->orderBy('field')->get(['field', 'term']);

        self::assertSame(
            [['field' => 'payment_terms', 'term' => '50% advance'], ['field' => 'warranty', 'term' => 'One year']],
            $rows->map(fn ($row): array => (array) $row)->all(),
            'an empty term is not a suggestion.',
        );
    }

    public function test_that_a_repeated_term_moves_last_used_at_and_adds_no_row(): void
    {
        $this->postJson(self::QUOTATIONS, $this->payload(['warranty' => 'One year']), $this->bearerFor(RoleName::Manager))->assertStatus(201);

        $first = DB::table('user_term_suggestions')->where('term', 'One year')->value('last_used_at');
        self::assertIsString($first);

        $this->travel(1)->minutes();

        $this->postJson(self::QUOTATIONS, $this->payload(['warranty' => 'One year']), $this->bearerFor(RoleName::Manager))->assertStatus(201);

        self::assertSame(1, DB::table('user_term_suggestions')->where('term', 'One year')->count());
        self::assertGreaterThan($first, DB::table('user_term_suggestions')->where('term', 'One year')->value('last_used_at'));
    }

    /** The column is 500 characters; a term past it is skipped, never a failed save. */
    public function test_that_a_term_too_long_to_be_a_suggestion_is_skipped(): void
    {
        $this->postJson(self::QUOTATIONS, $this->payload(['warranty' => str_repeat('x', 501)]), $this->bearerFor(RoleName::Manager))
            ->assertStatus(201);

        self::assertSame(0, DB::table('user_term_suggestions')->count());
    }

    public function test_that_editing_a_quotation_remembers_its_terms_too(): void
    {
        $id = $this->postJson(self::QUOTATIONS, $this->payload(), $this->bearerFor(RoleName::Manager))->assertStatus(201)->json('data.id');
        self::assertIsString($id);
        $etag = $this->getJson(self::QUOTATIONS.'/'.$id, $this->bearerFor(RoleName::Manager))->json('data.etag');
        self::assertIsString($etag);

        // `deal_id` and `customer_id` are `prohibited` on an edit.
        $body = array_diff_key($this->payload(['delivery_terms' => 'Ex works']), ['deal_id' => 0, 'customer_id' => 0]);

        $this->patchJson(self::QUOTATIONS.'/'.$id, $body, [...$this->bearerFor(RoleName::Manager), 'If-Match' => $etag])
            ->assertStatus(200);

        self::assertSame(1, DB::table('user_term_suggestions')->where('field', 'delivery_terms')->where('term', 'Ex works')->count());
    }

    // ─────────────────────────────────────────────────────────────── the read

    public function test_that_an_unauthenticated_caller_is_refused(): void
    {
        $this->getJson(self::ENDPOINT.'?field=warranty')->assertStatus(401);
    }

    /** §3.5: the CEO holds no `quotation.create`, so has nothing to be reminded of. */
    public function test_that_a_role_without_quotation_create_is_refused(): void
    {
        $this->getJson(self::ENDPOINT.'?field=warranty', $this->bearerFor(RoleName::Ceo))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'permission_denied');
    }

    public function test_that_it_lists_the_callers_own_terms_newest_first(): void
    {
        $mine = $this->userWith(RoleName::Manager)->getKey();
        $theirs = $this->userWith(RoleName::IndoorSales)->getKey();
        self::assertIsString($mine);
        self::assertIsString($theirs);

        $this->row($mine, 'warranty', 'Older', now()->subDay());
        $this->row($mine, 'warranty', 'Newer', now());
        $this->row($mine, 'payment_terms', 'Other field', now());
        $this->row($theirs, 'warranty', 'Somebody else', now());

        $this->getJson(self::ENDPOINT.'?field=warranty', $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.0.term', 'Newer')
            ->assertJsonPath('data.1.term', 'Older')
            ->assertJsonCount(2, 'data');
    }

    /** `DB-01`: a retired suggestion stays in the table and leaves the list. */
    public function test_that_a_soft_deleted_term_is_not_listed(): void
    {
        $mine = $this->userWith(RoleName::Manager)->getKey();
        self::assertIsString($mine);

        $this->row($mine, 'warranty', 'Retired');
        DB::table('user_term_suggestions')->update(['deleted_at' => now()]);

        $this->getJson(self::ENDPOINT.'?field=warranty', $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_that_the_list_is_capped_at_twenty(): void
    {
        $mine = $this->userWith(RoleName::Manager)->getKey();
        self::assertIsString($mine);

        for ($i = 0; $i < 25; $i++) {
            $this->row($mine, 'payment_terms', 'Term '.$i, now()->subMinutes($i));
        }

        $this->getJson(self::ENDPOINT.'?field=payment_terms', $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('data.0.term', 'Term 0');
    }

    /** `OpenAPI §6.2`: an unknown parameter value is a `400 invalid_request`, not ignored. */
    public function test_that_an_unknown_field_is_a_400(): void
    {
        $this->getJson(self::ENDPOINT.'?field=notes', $this->bearerFor(RoleName::Manager))
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'invalid_request')
            ->assertJsonPath('error.details.0.field', 'field');

        $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::Manager))->assertStatus(400);
    }

    // ──────────────────────────────────────────────────────────────── helpers

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'deal_id' => $this->dealId,
            'customer_id' => $this->customerId,
            'currency' => 'EGP',
            'default_margin' => '20',
            'discount_percent' => '0',
            'lines' => [],
            'additional_items' => [],
        ], $overrides);
    }

    private function row(string $userId, string $field, string $term, ?\DateTimeInterface $lastUsedAt = null): void
    {
        DB::table('user_term_suggestions')->insert([
            'id' => Uuid::uuid4()->toString(),
            'user_id' => $userId,
            'field' => $field,
            'term' => $term,
            'last_used_at' => $lastUsedAt ?? now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function currency(): void
    {
        DB::table('currencies')->insert([
            'id' => Uuid::uuid4()->toString(),
            'code' => 'EGP',
            'rounding_unit' => '1',
            'rounding_enabled' => false,
            'is_base' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function customer(): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('customers')->insert([
            'id' => $id,
            'name' => 'Nile Trading',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function deal(string $customerId): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('deals')->insert([
            'id' => $id,
            'code' => 'DL-'.now()->format('Y').'-'.substr($id, 0, 4),
            'customer_id' => $customerId,
            'owner_id' => null,
            'last_activity_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function userWith(RoleName $role): User
    {
        if (isset($this->users[$role->value])) {
            return $this->users[$role->value];
        }

        $row = Role::query()->where('slug', $role->value)->firstOrFail();

        $user = new User;
        $user->fill([
            'name' => 'Test '.$role->label(),
            'email' => str_replace('_', '.', $role->value).'@example.test',
            'password' => self::PASSWORD,
            'role_id' => $row->id,
            'is_active' => true,
            'is_hidden' => $role->isHidden(),
        ]);
        $user->save();

        return $this->users[$role->value] = $user;
    }

    /** @return array<string, string> */
    private function bearerFor(RoleName $role): array
    {
        $user = $this->userWith($role);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertStatus(201)->json('data.token');

        self::assertIsString($token);

        return ['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => Uuid::uuid4()->toString()];
    }
}
