<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Module 2, Point 3.2 — `GET /api/v1/currencies` and `PATCH /api/v1/currencies/{code}`.
 *
 * **§5.3 is the whole specification:** the rounding unit is per currency and
 * editable *"under System Settings → Currencies, where rounding can also be
 * switched off for a currency (`D-65`)"*. So the permission is §3.11's *system
 * settings*, the Super Admin's — **not** *FX rates*, which the Manager also
 * holds and which Point 3.3 will guard.
 *
 * **`D-65` is two columns, and the endpoint has to keep them apart.** Switching
 * rounding off leaves the unit where it was, so turning it back on does not
 * have to invent one; a test here switches off, checks the unit survived, and
 * switches back on.
 *
 * **`DB-07` at the boundary.** A unit arrives as a decimal string and is stored
 * as one. `0.005` must come back as `0.005` — a float round trip is where the
 * cent quietly disappears, and this is the one table where that is visible.
 *
 * **What cannot be tested here:** §5.3's *"changing either the unit or the
 * on/off setting affects new quotations only, never one already issued"*. There
 * are no quotations until Module 7. It stays unticked in `CHECKLIST.md`.
 */
final class CurrencyEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Passw0rd123';

    private const ENDPOINT = '/api/v1/currencies';

    protected function setUp(): void
    {
        parent::setUp();

        // SEC-07 puts the grants in the database, and §5.3's rows have to exist
        // before anything can be edited.
        $this->seed(RolePermissionSeeder::class);
        $this->seed(CurrencySeeder::class);
    }

    /** @var array<string, User> */
    private array $users = [];

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

        return ['Authorization' => 'Bearer '.$token];
    }

    // ── §3.11 ───────────────────────────────────────────────────────────────

    public function test_that_an_unauthenticated_caller_is_refused(): void
    {
        $this->getJson(self::ENDPOINT)->assertStatus(401);
    }

    /**
     * The Manager holds *FX rates* and not *system settings*, and §5.3 files
     * the rounding unit under the second. The row below is not the row above.
     */
    public function test_that_a_manager_may_not_change_a_rounding_unit(): void
    {
        $this->patchJson(
            self::ENDPOINT.'/EGP',
            ['rounding_unit' => '5'],
            $this->bearerFor(RoleName::Manager),
        )->assertStatus(403);
    }

    /**
     * D-80: `currency.view` is the §3.6 create/edit set, because a supplier
     * offer's `currency_id` is a uuid the form has to be able to look up. The
     * rounding PATCH above stays the Super Admin's.
     */
    public function test_that_an_offer_writer_may_read_the_currencies_with_their_ids(): void
    {
        foreach ([RoleName::IndoorSales, RoleName::Procurement] as $role) {
            $response = $this->getJson(self::ENDPOINT, $this->bearerFor($role))->assertStatus(200);

            $response->assertJsonStructure(['data' => ['currencies' => [['id', 'code']]]]);

            $id = $response->json('data.currencies.0.id');
            self::assertIsString($id);
            self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $id);
        }
    }

    public function test_that_the_ceo_may_not_read_the_currencies(): void
    {
        $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::Ceo))->assertStatus(403);
    }

    // ── reading ─────────────────────────────────────────────────────────────

    public function test_that_the_reading_answers_in_the_documented_envelope(): void
    {
        $response = $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::SuperAdmin))->assertStatus(200);

        $response->assertJsonStructure([
            'data' => ['currencies' => [['code', 'rounding_unit', 'rounding_enabled', 'is_base']]],
            'meta' => ['request_id'],
        ]);

        self::assertSame($response->headers->get('X-Request-Id'), $response->json('meta.request_id'));
    }

    /** §5.3's table, through the API this time. */
    public function test_that_the_reading_carries_section_5_3s_units(): void
    {
        $currencies = $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::SuperAdmin))
            ->assertStatus(200)
            ->json('data.currencies');

        self::assertIsArray($currencies);

        // Narrowed with assertions rather than casts: `json()` is `mixed` all
        // the way down, and a cast would hide a unit that arrived as a float —
        // which is the one thing DB-07 makes this test care about.
        $units = [];
        foreach ($currencies as $currency) {
            self::assertIsArray($currency);
            self::assertIsString($currency['code']);
            self::assertIsString($currency['rounding_unit'], 'DB-07: a unit leaves as a decimal string.');

            $units[$currency['code']] = $currency['rounding_unit'];
        }

        foreach (['EGP' => '1', 'USD' => '0.01', 'EUR' => '0.01'] as $code => $expected) {
            self::assertArrayHasKey($code, $units);
            self::assertTrue(is_numeric($units[$code]));
            self::assertSame(0, bccomp($expected, $units[$code], 8));
        }
    }

    public function test_that_the_reading_names_the_base_currency(): void
    {
        $currencies = $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::SuperAdmin))
            ->assertStatus(200)
            ->json('data.currencies');

        self::assertIsArray($currencies);

        $base = [];
        foreach ($currencies as $currency) {
            self::assertIsArray($currency);

            if ($currency['is_base'] === true) {
                $base[] = $currency['code'];
            }
        }

        self::assertSame(['EGP'], $base);
    }

    // ── writing ─────────────────────────────────────────────────────────────

    public function test_that_the_super_admin_may_change_a_rounding_unit(): void
    {
        $this->patchJson(self::ENDPOINT.'/EGP', ['rounding_unit' => '5'], $this->bearerFor(RoleName::SuperAdmin))
            ->assertStatus(200)
            ->assertJsonPath('data.currency.code', 'EGP');

        self::assertSame(true, DB::scalar("select rounding_unit = 5 from currencies where code = 'EGP'"));
    }

    /**
     * `D-65`: the switch moves and the unit stays.
     *
     * **EGP and not USD, deliberately.** §5.3 gives EGP a unit of `1` and the
     * other two `0.01`, so a use case that quietly substituted a default of
     * `0.01` for the unit it was not given would pass this test on USD and fail
     * it here. It did: the first version of this test used USD, and breaking
     * the fallback on purpose changed nothing.
     */
    public function test_that_rounding_may_be_switched_off_without_losing_the_unit(): void
    {
        $bearer = $this->bearerFor(RoleName::SuperAdmin);

        $this->patchJson(self::ENDPOINT.'/EGP', ['rounding_enabled' => false], $bearer)->assertStatus(200);

        self::assertSame(false, DB::scalar("select rounding_enabled from currencies where code = 'EGP'"));
        self::assertSame(true, DB::scalar("select rounding_unit = 1 from currencies where code = 'EGP'"));

        $this->patchJson(self::ENDPOINT.'/EGP', ['rounding_enabled' => true], $bearer)->assertStatus(200);

        self::assertSame(true, DB::scalar("select rounding_unit = 1 from currencies where code = 'EGP'"));
    }

    /** `DB-07`: a unit is a decimal string and every digit of it survives. */
    public function test_that_a_fractional_unit_keeps_its_digits(): void
    {
        $this->patchJson(self::ENDPOINT.'/USD', ['rounding_unit' => '0.005'], $this->bearerFor(RoleName::SuperAdmin))
            ->assertStatus(200);

        self::assertSame(true, DB::scalar("select rounding_unit = 0.005 from currencies where code = 'USD'"));
    }

    /** `RoundingRule` refuses a non-positive unit; so must the boundary. */
    public function test_that_a_zero_unit_is_refused(): void
    {
        $this->patchJson(self::ENDPOINT.'/EGP', ['rounding_unit' => '0'], $this->bearerFor(RoleName::SuperAdmin))
            ->assertStatus(422);

        self::assertSame(true, DB::scalar("select rounding_unit = 1 from currencies where code = 'EGP'"));
    }

    public function test_that_a_negative_unit_is_refused(): void
    {
        $this->patchJson(self::ENDPOINT.'/EGP', ['rounding_unit' => '-1'], $this->bearerFor(RoleName::SuperAdmin))
            ->assertStatus(422);
    }

    public function test_that_a_unit_that_is_not_a_number_is_refused(): void
    {
        $this->patchJson(self::ENDPOINT.'/EGP', ['rounding_unit' => 'one pound'], $this->bearerFor(RoleName::SuperAdmin))
            ->assertStatus(422);
    }

    public function test_that_an_empty_change_set_is_refused(): void
    {
        $this->patchJson(self::ENDPOINT.'/EGP', [], $this->bearerFor(RoleName::SuperAdmin))
            ->assertStatus(422);
    }

    public function test_that_a_currency_the_system_does_not_offer_is_not_found(): void
    {
        $bearer = $this->bearerFor(RoleName::SuperAdmin);

        // A missing route answers 404 too, so the known code is exercised first:
        // without this the test passes before the endpoint exists, which is how
        // it read on its first run.
        $this->patchJson(self::ENDPOINT.'/EGP', ['rounding_unit' => '1'], $bearer)->assertStatus(200);

        $this->patchJson(self::ENDPOINT.'/GBP', ['rounding_unit' => '1'], $bearer)->assertStatus(404);
    }

    /** An archived currency is not a currency the system offers. */
    public function test_that_an_archived_currency_is_not_found(): void
    {
        $bearer = $this->bearerFor(RoleName::SuperAdmin);

        $this->patchJson(self::ENDPOINT.'/EUR', ['rounding_unit' => '1'], $bearer)->assertStatus(200);

        DB::table('currencies')->where('code', 'EUR')->update(['deleted_at' => now()]);

        $this->patchJson(self::ENDPOINT.'/EUR', ['rounding_unit' => '1'], $bearer)->assertStatus(404);
    }

    /** `AUD-01`: a change to how money is rounded is not a silent change. */
    public function test_that_a_change_is_audited_with_the_old_and_the_new_rule(): void
    {
        $this->patchJson(self::ENDPOINT.'/EGP', ['rounding_unit' => '5'], $this->bearerFor(RoleName::SuperAdmin))
            ->assertStatus(200);

        $old = DB::scalar(
            "select old_values::text from audit_log where event = 'CURRENCY_ROUNDING_UPDATED' order by created_at desc limit 1",
        );
        $new = DB::scalar(
            "select new_values::text from audit_log where event = 'CURRENCY_ROUNDING_UPDATED' order by created_at desc limit 1",
        );

        self::assertIsString($old);
        self::assertIsString($new);
        self::assertStringContainsString('1', $old);
        self::assertStringContainsString('5', $new);
    }
}
