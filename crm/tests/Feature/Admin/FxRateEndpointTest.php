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
 * Module 2, Point 3.3 — `GET /api/v1/fx-rates` and `POST /api/v1/fx-rates`.
 *
 * **The negative authorisation test inverts here, and that is the point.**
 * §3.11's *FX rates* row is `✅ Super Admin · ✅ Manager · — Others`, one line
 * below the *system settings* row Point 3.2 guarded. So the Manager, who is
 * refused by `PATCH /currencies/{code}`, must be **allowed** by both endpoints
 * here — and a sales employee still refused. Two tests assert each direction,
 * because a route that carried `admin.system_settings` by copy-paste would pass
 * every other test in this file.
 *
 * **A new rate is a new row.** §5.6: *"Changing an FX rate never affects an
 * existing quotation"*, and `AP-06` files rates under append-only critical data.
 * Point 1.2 put that in the database as the `fx_rates_no_rewrite` trigger, so
 * there is deliberately **no** `PATCH` here: recording a second price for a pair
 * leaves the first standing, and a test reads both rows back.
 *
 * **§3.12 rule 4 makes the audit entry mandatory**, unlike `SETTINGS_UPDATED`
 * and `CURRENCY_ROUNDING_UPDATED`, which Points 3.1 and 3.2 wrote on `AUD-01`'s
 * general grounds. *"Mandatory audit entries for: … FX rate change …"* — and
 * `AuditEvent::fxRateChanged()` already exists for it.
 *
 * **`DB-07` at `D-68`'s FX scale.** A rate is `NUMERIC(18,8)` and arrives and
 * leaves as a decimal string; `48.12345678` survives the round trip or the
 * multiplier reaching every converted line is not the one that was entered.
 *
 * **What cannot be tested here:** §5.6's *"changing an FX rate never affects an
 * existing quotation"* as an outcome — there are no quotations until Module 7.
 * What this file can prove is the half that makes it possible: the old row is
 * still there.
 */
final class FxRateEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Passw0rd123';

    private const ENDPOINT = '/api/v1/fx-rates';

    protected function setUp(): void
    {
        parent::setUp();

        // SEC-07 puts the grants in the database, and a rate needs two live
        // currencies to be a rate between.
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

    /** @return array<string, mixed> */
    private static function rate(string $rate = '48.5', ?string $effectiveFrom = null): array
    {
        $body = ['from_currency' => 'USD', 'to_currency' => 'EGP', 'rate' => $rate];

        return $effectiveFrom === null ? $body : $body + ['effective_from' => $effectiveFrom];
    }

    // ── §3.11, inverted ─────────────────────────────────────────────────────

    public function test_that_an_unauthenticated_caller_is_refused(): void
    {
        $this->getJson(self::ENDPOINT)->assertStatus(401);
        $this->postJson(self::ENDPOINT, self::rate())->assertStatus(401);
    }

    /**
     * §3.11's *FX rates* row, and the reason this file exists as a separate
     * point: the Manager is refused by Point 3.2's endpoint and allowed here.
     */
    public function test_that_a_manager_may_read_the_rate_history(): void
    {
        $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::Manager))->assertStatus(200);
    }

    public function test_that_a_manager_may_record_a_rate(): void
    {
        $this->postJson(self::ENDPOINT, self::rate(), $this->bearerFor(RoleName::Manager))
            ->assertStatus(201);
    }

    /**
     * The other half of the same row. Without this the endpoint could be
     * carrying no permission at all and every test above would still pass.
     */
    public function test_that_a_sales_employee_may_not_read_the_rate_history(): void
    {
        $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::IndoorSales))->assertStatus(403);
    }

    public function test_that_a_sales_employee_may_not_record_a_rate(): void
    {
        $this->postJson(self::ENDPOINT, self::rate(), $this->bearerFor(RoleName::IndoorSales))
            ->assertStatus(403);
    }

    // ── recording ───────────────────────────────────────────────────────────

    public function test_that_a_recorded_rate_answers_in_the_documented_envelope(): void
    {
        $response = $this->postJson(self::ENDPOINT, self::rate(), $this->bearerFor(RoleName::SuperAdmin))
            ->assertStatus(201);

        $response->assertJsonStructure([
            'data' => ['fx_rate' => ['id', 'from_currency', 'to_currency', 'rate', 'effective_from', 'created_at']],
            'meta' => ['request_id'],
        ]);

        self::assertSame($response->headers->get('X-Request-Id'), $response->json('meta.request_id'));
        self::assertSame('USD', $response->json('data.fx_rate.from_currency'));
        self::assertSame('EGP', $response->json('data.fx_rate.to_currency'));
    }

    /** `DB-07` at `D-68`'s FX scale — every digit of an eight-place rate survives. */
    public function test_that_an_eight_place_rate_keeps_its_digits(): void
    {
        $rate = $this->postJson(self::ENDPOINT, self::rate('48.12345678'), $this->bearerFor(RoleName::SuperAdmin))
            ->assertStatus(201)
            ->json('data.fx_rate.rate');

        self::assertIsString($rate, 'DB-07: a rate leaves as a decimal string, never a float.');
        self::assertTrue(is_numeric($rate));
        self::assertSame(0, bccomp('48.12345678', $rate, 8));

        self::assertSame(true, DB::scalar('select rate = 48.12345678 from fx_rates limit 1'));
    }

    /**
     * The module's acceptance criterion: *"FX rate edit → old rate stays in
     * history"*. Point 1.2's trigger refuses an `UPDATE`; this is the endpoint
     * honouring the same rule by never attempting one.
     */
    public function test_that_a_second_rate_for_a_pair_leaves_the_first_standing(): void
    {
        $bearer = $this->bearerFor(RoleName::SuperAdmin);

        $this->postJson(self::ENDPOINT, self::rate('48.5', '2026-08-01T00:00:00+00:00'), $bearer)
            ->assertStatus(201);
        $this->postJson(self::ENDPOINT, self::rate('49.75', '2026-08-20T00:00:00+00:00'), $bearer)
            ->assertStatus(201);

        self::assertSame(2, DB::scalar('select count(*)::int from fx_rates where deleted_at is null'));
        self::assertSame(true, DB::scalar('select exists (select 1 from fx_rates where rate = 48.5)'));
        self::assertSame(true, DB::scalar('select exists (select 1 from fx_rates where rate = 49.75)'));
    }

    /** §3.12 rule 4 — this one is mandatory, not `AUD-01` discretion. */
    public function test_that_recording_a_rate_writes_the_mandatory_audit_entry(): void
    {
        $this->postJson(self::ENDPOINT, self::rate('48.5'), $this->bearerFor(RoleName::SuperAdmin))
            ->assertStatus(201);

        $values = DB::scalar(
            "select new_values::text from audit_log where event = 'FX_RATE_CHANGED' order by created_at desc limit 1",
        );

        self::assertIsString($values, '§3.12 rule 4: an FX rate change is always audited.');
        self::assertStringContainsString('USD', $values);
        self::assertStringContainsString('EGP', $values);
        self::assertStringContainsString('48.5', $values);

        // The row the entry points at is the rate that was just written.
        $entity = DB::scalar(
            "select entity_id::text from audit_log where event = 'FX_RATE_CHANGED' order by created_at desc limit 1",
        );

        self::assertIsString($entity);
        self::assertSame(true, DB::scalar('select exists (select 1 from fx_rates where id = ?)', [$entity]));
    }

    public function test_that_an_omitted_effective_from_defaults_to_now(): void
    {
        $this->postJson(self::ENDPOINT, self::rate(), $this->bearerFor(RoleName::SuperAdmin))
            ->assertStatus(201);

        self::assertSame(
            true,
            DB::scalar("select effective_from between now() - interval '1 minute' and now() + interval '1 minute' from fx_rates limit 1"),
        );
    }

    // ── refusals ────────────────────────────────────────────────────────────

    /** The `fx_rates_pair_moment_unique_alive` index, said at the boundary first. */
    public function test_that_the_same_pair_at_the_same_moment_is_refused(): void
    {
        $bearer = $this->bearerFor(RoleName::SuperAdmin);
        $moment = '2026-08-01T00:00:00+00:00';

        $this->postJson(self::ENDPOINT, self::rate('48.5', $moment), $bearer)->assertStatus(201);
        $this->postJson(self::ENDPOINT, self::rate('49.5', $moment), $bearer)->assertStatus(422);

        self::assertSame(1, DB::scalar('select count(*)::int from fx_rates'));
    }

    /** `fx_rates_distinct_currencies`: a currency against itself is not a price. */
    public function test_that_a_currency_against_itself_is_refused(): void
    {
        $this->postJson(
            self::ENDPOINT,
            ['from_currency' => 'EGP', 'to_currency' => 'EGP', 'rate' => '1'],
            $this->bearerFor(RoleName::SuperAdmin),
        )->assertStatus(422);

        self::assertSame(0, DB::scalar('select count(*)::int from fx_rates'));
    }

    public function test_that_a_currency_the_system_does_not_offer_is_refused(): void
    {
        $this->postJson(
            self::ENDPOINT,
            ['from_currency' => 'GBP', 'to_currency' => 'EGP', 'rate' => '1.2'],
            $this->bearerFor(RoleName::SuperAdmin),
        )->assertStatus(422);
    }

    /** An archived currency is not a currency the system offers (`D-34`). */
    public function test_that_an_archived_currency_is_refused(): void
    {
        $bearer = $this->bearerFor(RoleName::SuperAdmin);

        $this->postJson(self::ENDPOINT, ['from_currency' => 'EUR', 'to_currency' => 'EGP', 'rate' => '53'], $bearer)
            ->assertStatus(201);

        DB::table('currencies')->where('code', 'EUR')->update(['deleted_at' => now()]);

        $this->postJson(self::ENDPOINT, ['from_currency' => 'EUR', 'to_currency' => 'EGP', 'rate' => '54'], $bearer)
            ->assertStatus(422);
    }

    public function test_that_a_zero_rate_is_refused(): void
    {
        $this->postJson(self::ENDPOINT, self::rate('0'), $this->bearerFor(RoleName::SuperAdmin))
            ->assertStatus(422);
    }

    public function test_that_a_negative_rate_is_refused(): void
    {
        $this->postJson(self::ENDPOINT, self::rate('-1'), $this->bearerFor(RoleName::SuperAdmin))
            ->assertStatus(422);
    }

    /** `Decimal::of` refuses exponent notation; so must the boundary. */
    public function test_that_a_rate_in_exponent_notation_is_refused(): void
    {
        $this->postJson(self::ENDPOINT, self::rate('4.85e1'), $this->bearerFor(RoleName::SuperAdmin))
            ->assertStatus(422);
    }

    public function test_that_a_missing_rate_is_refused(): void
    {
        $this->postJson(
            self::ENDPOINT,
            ['from_currency' => 'USD', 'to_currency' => 'EGP'],
            $this->bearerFor(RoleName::SuperAdmin),
        )->assertStatus(422);
    }

    /**
     * **The bare date is the load-bearing half of this test.** `last tuesday`
     * is refused by Laravel's `date` rule as well, so on its own it could not
     * tell `date` from `date_format` — measured: weakening the rule to `date`
     * changed nothing. `2026-08-01` is where they differ, and `DB-08` is why it
     * matters: a day with no time and no offset becomes midnight in whatever
     * zone the process happens to be in, silently.
     */
    public function test_that_an_effective_from_that_is_not_an_iso_moment_is_refused(): void
    {
        $bearer = $this->bearerFor(RoleName::SuperAdmin);

        $this->postJson(self::ENDPOINT, self::rate('48.5', 'last tuesday'), $bearer)->assertStatus(422);
        $this->postJson(self::ENDPOINT, self::rate('48.5', '2026-08-01'), $bearer)->assertStatus(422);
        $this->postJson(self::ENDPOINT, self::rate('48.5', '01/08/2026 10:00'), $bearer)->assertStatus(422);

        self::assertSame(0, DB::scalar('select count(*)::int from fx_rates'));
    }

    // ── the history ─────────────────────────────────────────────────────────

    /** `OpenAPI §4.2` — all six pagination keys, on every list endpoint. */
    public function test_that_the_history_answers_in_the_collection_envelope(): void
    {
        $bearer = $this->bearerFor(RoleName::SuperAdmin);

        $this->postJson(self::ENDPOINT, self::rate('48.5'), $bearer)->assertStatus(201);

        $this->getJson(self::ENDPOINT, $bearer)
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [['id', 'from_currency', 'to_currency', 'rate', 'effective_from', 'created_at']],
                'meta' => [
                    'pagination' => [
                        'page', 'per_page', 'total', 'total_pages', 'has_next_page', 'has_previous_page',
                    ],
                    'request_id',
                ],
            ])
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('meta.pagination.per_page', 25)
            ->assertJsonPath('meta.pagination.has_next_page', false);
    }

    /** §6.2: "default order is resource-specific and documented" — newest first. */
    public function test_that_the_history_reads_newest_first(): void
    {
        $bearer = $this->bearerFor(RoleName::SuperAdmin);

        $this->postJson(self::ENDPOINT, self::rate('48.5', '2026-08-01T00:00:00+00:00'), $bearer)->assertStatus(201);
        $this->postJson(self::ENDPOINT, self::rate('49.75', '2026-08-20T00:00:00+00:00'), $bearer)->assertStatus(201);

        $rates = $this->getJson(self::ENDPOINT, $bearer)->assertStatus(200)->json('data');

        self::assertIsArray($rates);
        self::assertCount(2, $rates);

        $ordered = [];
        foreach ($rates as $row) {
            self::assertIsArray($row);
            self::assertIsString($row['rate'], 'DB-07: a rate leaves as a decimal string.');
            self::assertTrue(is_numeric($row['rate']));
            $ordered[] = $row['rate'];
        }

        self::assertSame(0, bccomp('49.75', $ordered[0], 8), 'The most recent rate is first.');
        self::assertSame(0, bccomp('48.5', $ordered[1], 8));
    }

    /** An empty history is page 1 of 1, not page 1 of 0. */
    public function test_that_an_empty_history_is_one_empty_page(): void
    {
        $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::SuperAdmin))
            ->assertStatus(200)
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.pagination.total', 0)
            ->assertJsonPath('meta.pagination.total_pages', 1)
            ->assertJsonPath('meta.pagination.has_next_page', false)
            ->assertJsonPath('meta.pagination.has_previous_page', false);
    }

    public function test_that_the_history_paginates(): void
    {
        $bearer = $this->bearerFor(RoleName::SuperAdmin);

        foreach (['2026-08-01', '2026-08-02', '2026-08-03'] as $day) {
            $this->postJson(self::ENDPOINT, self::rate('48.5', $day.'T00:00:00+00:00'), $bearer)->assertStatus(201);
        }

        $first = $this->getJson(self::ENDPOINT.'?per_page=2', $bearer)->assertStatus(200);
        $first->assertJsonPath('meta.pagination.total', 3)
            ->assertJsonPath('meta.pagination.total_pages', 2)
            ->assertJsonPath('meta.pagination.has_next_page', true)
            ->assertJsonPath('meta.pagination.has_previous_page', false);

        $page = $first->json('data');
        self::assertIsArray($page);
        self::assertCount(2, $page);

        $second = $this->getJson(self::ENDPOINT.'?per_page=2&page=2', $bearer)->assertStatus(200);
        $second->assertJsonPath('meta.pagination.has_next_page', false)
            ->assertJsonPath('meta.pagination.has_previous_page', true);

        $rest = $second->json('data');
        self::assertIsArray($rest);
        self::assertCount(1, $rest);
    }

    /**
     * §6.1: "Default `per_page`: 25; maximum 100. Invalid or excessive values
     * return `400 invalid_request`" — a 400, deliberately not the 422 a Form
     * Request would have produced.
     */
    public function test_that_a_per_page_above_the_maximum_is_a_bad_request(): void
    {
        $this->getJson(self::ENDPOINT.'?per_page=101', $this->bearerFor(RoleName::SuperAdmin))
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'invalid_request')
            ->assertJsonPath('error.details.0.field', 'per_page');
    }

    public function test_that_a_page_that_is_not_a_positive_integer_is_a_bad_request(): void
    {
        $bearer = $this->bearerFor(RoleName::SuperAdmin);

        $this->getJson(self::ENDPOINT.'?page=0', $bearer)->assertStatus(400);
        $this->getJson(self::ENDPOINT.'?page=abc', $bearer)->assertStatus(400);
        $this->getJson(self::ENDPOINT.'?per_page=1.5', $bearer)->assertStatus(400);
    }

    /** §6.2: "never ignore them silently". */
    public function test_that_an_undeclared_filter_is_a_bad_request(): void
    {
        $this->getJson(self::ENDPOINT.'?filter[rate]=48.5', $this->bearerFor(RoleName::SuperAdmin))
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'invalid_request');
    }

    public function test_that_an_undeclared_sort_is_a_bad_request(): void
    {
        $this->getJson(self::ENDPOINT.'?sort=rate', $this->bearerFor(RoleName::SuperAdmin))
            ->assertStatus(400);
    }

    /**
     * There is no `PATCH` on this resource, by design — `AP-06` and Point 1.2's
     * trigger make a new price a new row.
     *
     * The assertion is on the **collection** URI and not on `/{id}`: a URI no
     * route matches answers 404 whether or not the endpoint was ever written,
     * which is the vacuous shape Point 1.1 already paid for. `/fx-rates` does
     * match — `GET` and `POST` are registered on it — so a 405 is the router
     * stating that those are the only two verbs it carries.
     */
    public function test_that_the_resource_carries_no_edit_verb(): void
    {
        $bearer = $this->bearerFor(RoleName::SuperAdmin);

        $this->postJson(self::ENDPOINT, self::rate('48.5'), $bearer)->assertStatus(201);

        $this->patchJson(self::ENDPOINT, ['rate' => '49.5'], $bearer)->assertStatus(405);
        $this->deleteJson(self::ENDPOINT, [], $bearer)->assertStatus(405);
    }
}
