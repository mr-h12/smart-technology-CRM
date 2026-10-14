<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Database\Seeders\ManagedListSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `D-113` (F-44 · 2, owner 2026-10-14): a sector typed in the customer form
 * joins the `sectors` list (§4.2 "extendable", `DB-05`) under the save's own
 * permission, and a sector already listed — by code or by either label — is
 * reused, never added twice.
 */
final class CustomerSectorTypedTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/customers';

    private const PASSWORD = 'Passw0rd123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ManagedListSeeder::class);
    }

    private function sectors(): int
    {
        return DB::table('enum_lists')->where('list', 'sectors')->whereNull('deleted_at')->count();
    }

    public function test_that_a_new_sector_joins_the_list_under_customer_create_alone(): void
    {
        $before = $this->sectors();

        $code = $this->postJson(self::ENDPOINT, ['name' => 'Delta Build', 'sector' => 'مقاولات'], $this->bearerFor(RoleName::IndoorSales))
            ->assertStatus(201)
            ->json('data.sector');

        self::assertIsString($code);
        self::assertSame($before + 1, $this->sectors());
        $row = DB::table('enum_lists')->where('list', 'sectors')->where('code', $code)->first();
        self::assertNotNull($row);
        self::assertSame('مقاولات', $row->label_ar);
        self::assertSame('مقاولات', $row->label_en, 'One name typed, both labels — owner 2026-10-14.');
        $this->assertDatabaseHas('customers', ['name' => 'Delta Build', 'sector' => $code]);
        $this->assertDatabaseHas('audit_log', ['event' => 'LIST_ENTRY_ADDED', 'user_id' => $this->userWith(RoleName::IndoorSales)->id]);
    }

    /** @return array<string, array{string}> */
    public static function spellingsOfMedical(): array
    {
        return [
            'the code' => ['medical'],
            'the Arabic label' => ['طبي'],
            'the English label' => ['Medical'],
            'another case, padded' => ['  MEDICAL '],
        ];
    }

    #[DataProvider('spellingsOfMedical')]
    public function test_that_a_listed_sector_is_reused_by_code_or_label(string $typed): void
    {
        $before = $this->sectors();

        $this->postJson(self::ENDPOINT, ['name' => 'Nile Clinic', 'sector' => $typed], $this->bearerFor(RoleName::IndoorSales))
            ->assertStatus(201)
            ->assertJsonPath('data.sector', 'medical');

        self::assertSame($before, $this->sectors(), "'{$typed}' is the seeded Medical sector, not a new one.");
    }

    public function test_that_the_same_new_sector_typed_twice_is_one_entry(): void
    {
        $bearer = $this->bearerFor(RoleName::IndoorSales);
        $before = $this->sectors();

        $first = $this->postJson(self::ENDPOINT, ['name' => 'A', 'sector' => 'مقاولات'], $bearer)->assertStatus(201)->json('data.sector');
        $second = $this->postJson(self::ENDPOINT, ['name' => 'B', 'sector' => 'مقاولات'], $bearer)->assertStatus(201)->json('data.sector');

        self::assertSame($first, $second);
        self::assertSame($before + 1, $this->sectors());
    }

    public function test_that_an_edit_adds_a_typed_sector_too(): void
    {
        $bearer = $this->bearerFor(RoleName::IndoorSales);
        $id = $this->postJson(self::ENDPOINT, ['name' => 'Gamma'], $bearer)->assertStatus(201)->json('data.id');
        self::assertIsString($id);
        $before = $this->sectors();

        $code = $this->patchJson(self::ENDPOINT.'/'.$id, ['sector' => 'Education'], $bearer)
            ->assertStatus(200)
            ->json('data.sector');

        self::assertSame('education', $code);
        self::assertSame($before + 1, $this->sectors());
    }

    public function test_that_a_caller_without_customer_create_adds_no_sector(): void
    {
        $before = $this->sectors();

        $this->postJson(self::ENDPOINT, ['name' => 'Refused', 'sector' => 'مقاولات'], $this->bearerFor(RoleName::Procurement))
            ->assertStatus(403);

        self::assertSame($before, $this->sectors());
    }

    public function test_that_an_invalid_save_adds_no_sector(): void
    {
        $before = $this->sectors();

        $this->postJson(self::ENDPOINT, ['name' => '', 'sector' => 'مقاولات'], $this->bearerFor(RoleName::IndoorSales))
            ->assertStatus(422);

        self::assertSame($before, $this->sectors());
    }

    /** The sector is resolved inside the save's transaction: an edit of a missing customer rolls it back. */
    public function test_that_an_edit_of_a_missing_customer_adds_no_sector(): void
    {
        $before = $this->sectors();

        $this->patchJson(self::ENDPOINT.'/'.\Illuminate\Support\Str::uuid7(), ['sector' => 'مقاولات'], $this->bearerFor(RoleName::IndoorSales))
            ->assertStatus(404);

        self::assertSame($before, $this->sectors());
    }

    /** `own` reaches only the caller's rows: an edit of somebody else's customer is a 404 and writes no sector. */
    public function test_that_an_own_scoped_edit_of_another_owners_customer_adds_no_sector(): void
    {
        $id = $this->postJson(self::ENDPOINT, ['name' => 'Theirs'], $this->bearerFor(RoleName::Manager))->assertStatus(201)->json('data.id');
        self::assertIsString($id);
        $before = $this->sectors();

        $this->patchJson(self::ENDPOINT.'/'.$id, ['sector' => 'مقاولات'], $this->bearerFor(RoleName::IndoorSales))
            ->assertStatus(404);

        self::assertSame($before, $this->sectors());
    }

    /** Longer than the old 64 and within the label's own 128. */
    public function test_that_a_sector_name_up_to_the_label_limit_is_accepted(): void
    {
        $this->postJson(self::ENDPOINT, ['name' => 'Long', 'sector' => str_repeat('س', 100)], $this->bearerFor(RoleName::IndoorSales))
            ->assertStatus(201);
    }

    private function userWith(RoleName $role): User
    {
        $existing = User::query()->where('email', $role->value.'@example.test')->first();

        if ($existing instanceof User) {
            return $existing;
        }

        $user = new User;
        $user->fill([
            'name' => 'Test '.$role->label(),
            'email' => $role->value.'@example.test',
            'password' => self::PASSWORD,
            'role_id' => Role::query()->where('slug', $role->value)->firstOrFail()->id,
            'is_active' => true,
            'is_hidden' => $role->isHidden(),
        ]);
        $user->save();

        return $user;
    }

    /** @return array<string, string> */
    private function bearerFor(RoleName $role): array
    {
        $user = $this->userWith($role);
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertStatus(201)->json('data.token');
        self::assertIsString($token);

        return ['Authorization' => 'Bearer '.$token];
    }
}
