<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Modules\Identity\Application\Authentication\AuthenticateUser;
use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Module 2, Point 3.4 — `GET` and `PATCH /api/v1/system-limits`.
 *
 * **§13 screen 6, and its own row of §3.11.** *"system limits (SLAs,
 * thresholds)"* is `✅ Super Admin · — · —`, a different row from *system
 * settings* even though both are the Super Admin's today. Point 1.1 made them
 * two tables for exactly that reason: *"a matrix row may be regranted without a
 * deployment, so the split is what keeps the authorisation check at the table
 * instead of inside a WHERE"*. A test asserts the Manager is refused, which is
 * the row rather than a formality.
 *
 * **`D-75`'s row is one of the six, and that is the point of including it.**
 * `identity.lockout_minutes` is the only limit the documentation gives a value
 * to, it is already seeded, and `SystemSettingsSeeder` says outright that *"the
 * first thing that will happen to this row is somebody changing it"*. An
 * endpoint that listed §13 screen 6's five and omitted this one would leave the
 * only live limit in the system uneditable.
 *
 * **The other five are declared and deliberately unvalued.** §13 names them;
 * `D-17` says the stale-deal threshold is *"configurable in settings"* and
 * stops, §11.5 says the daily deadline comes *"from settings"* and stops. So
 * the reading returns `null` for each — *"not configured yet"* is a real state
 * (Point 1.1 made the column nullable for it), and inventing a number here
 * would be a business rule arriving as configuration and read as fact.
 *
 * **`unit` is why this table is not `settings`.** §13 screen 6 mixes days,
 * hours and megabytes on one screen, and a threshold in days cannot be rendered
 * beside an SLA in hours without saying which is which.
 */
final class SystemLimitEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Passw0rd123';

    private const ENDPOINT = '/api/v1/system-limits';

    private const LOCKOUT = AuthenticateUser::LOCKOUT_MINUTES_KEY;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingsSeeder::class);
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

    // ── §3.11's own row ─────────────────────────────────────────────────────

    public function test_that_an_unauthenticated_caller_is_refused(): void
    {
        $this->getJson(self::ENDPOINT)->assertStatus(401);
    }

    public function test_that_a_manager_may_not_read_the_limits(): void
    {
        $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::Manager))->assertStatus(403);
    }

    public function test_that_a_sales_employee_may_not_change_a_limit(): void
    {
        $this->patchJson(
            self::ENDPOINT,
            ['limits' => [self::LOCKOUT => '45']],
            $this->bearerFor(RoleName::IndoorSales),
        )->assertStatus(403);
    }

    // ── reading ─────────────────────────────────────────────────────────────

    public function test_that_the_reading_answers_in_the_documented_envelope(): void
    {
        $response = $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::SuperAdmin))
            ->assertStatus(200);

        $response->assertJsonStructure(['data' => ['limits'], 'meta' => ['request_id']]);

        self::assertSame($response->headers->get('X-Request-Id'), $response->json('meta.request_id'));
    }

    /**
     * Every declared limit is present, valued or not — §13 draws a form, and a
     * field the response omits is a field the screen cannot render.
     */
    public function test_that_every_declared_limit_is_present_with_its_unit(): void
    {
        $limits = $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::SuperAdmin))
            ->assertStatus(200)
            ->json('data.limits');

        self::assertIsArray($limits);
        self::assertCount(6, $limits, '§13 screen 6 names five, and D-75 adds the sixth.');

        foreach ($limits as $key => $limit) {
            self::assertIsString($key);
            self::assertIsArray($limit);
            self::assertArrayHasKey('value', $limit);
            self::assertArrayHasKey('unit', $limit);
            self::assertArrayHasKey('value_type', $limit);
        }
    }

    /** `D-75`'s seeded interim, read through the endpoint that will change it. */
    public function test_that_the_seeded_lockout_is_reported_with_its_unit(): void
    {
        $limits = $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::SuperAdmin))
            ->assertStatus(200)
            ->json('data.limits');

        self::assertIsArray($limits);

        $lockout = $limits[self::LOCKOUT];
        self::assertIsArray($lockout);
        self::assertSame('30', $lockout['value'], 'DB-07: a limit is a string, not a number.');
        self::assertSame('minutes', $lockout['unit']);
        self::assertSame('integer', $lockout['value_type']);
    }

    /**
     * The five §13 names and nobody has valued. Null and not `0`: Point 1.1
     * made the column nullable so "not configured yet" is distinguishable from
     * a configured zero.
     */
    public function test_that_an_unvalued_limit_reports_null_rather_than_a_default(): void
    {
        $limits = $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::SuperAdmin))
            ->assertStatus(200)
            ->json('data.limits');

        self::assertIsArray($limits);

        $unvalued = 0;
        foreach ($limits as $key => $limit) {
            self::assertIsArray($limit);

            if ($key !== self::LOCKOUT) {
                self::assertNull($limit['value'], "{$key} has no documented value and must not invent one.");
                $unvalued++;
            }
        }

        self::assertSame(5, $unvalued);
    }

    // ── writing ─────────────────────────────────────────────────────────────

    public function test_that_the_super_admin_may_change_the_lockout(): void
    {
        $this->patchJson(
            self::ENDPOINT,
            ['limits' => [self::LOCKOUT => '45']],
            $this->bearerFor(RoleName::SuperAdmin),
        )->assertStatus(200);

        self::assertSame('45', DB::scalar('select value from system_limits where key = ?', [self::LOCKOUT]));
    }

    /**
     * **`D-75` end to end.** The lockout is not a number in a config file any
     * more: changing it through this endpoint changes what `AuthenticateUser`
     * reads, which is the whole reason Point 2.3 put a `SettingReader` between
     * them. Without this test the endpoint could write a row nothing consults.
     */
    public function test_that_changing_the_lockout_changes_what_the_login_flow_reads(): void
    {
        $reader = $this->app->make(\App\Support\Settings\SettingReader::class);

        self::assertSame(30, $reader->integer(self::LOCKOUT));

        $this->patchJson(
            self::ENDPOINT,
            ['limits' => [self::LOCKOUT => '45']],
            $this->bearerFor(RoleName::SuperAdmin),
        )->assertStatus(200);

        self::assertSame(45, $this->app->make(\App\Support\Settings\SettingReader::class)->integer(self::LOCKOUT));
    }

    /** A limit that had no row gets one — the five start unvalued. */
    public function test_that_an_unvalued_limit_can_be_given_a_value(): void
    {
        $bearer = $this->bearerFor(RoleName::SuperAdmin);

        $key = 'limits.stale_deal_days';

        $this->patchJson(self::ENDPOINT, ['limits' => [$key => '14']], $bearer)->assertStatus(200);

        self::assertSame('14', DB::scalar('select value from system_limits where key = ?', [$key]));
        self::assertSame('days', DB::scalar('select unit from system_limits where key = ?', [$key]));
    }

    public function test_that_the_response_reports_the_new_value(): void
    {
        $limits = $this->patchJson(
            self::ENDPOINT,
            ['limits' => [self::LOCKOUT => '45']],
            $this->bearerFor(RoleName::SuperAdmin),
        )->assertStatus(200)->json('data.limits');

        self::assertIsArray($limits);

        $lockout = $limits[self::LOCKOUT];
        self::assertIsArray($lockout);
        self::assertSame('45', $lockout['value']);
    }

    /** `AUD-01`: a threshold that decides when a deal goes stale is not silent. */
    public function test_that_a_change_is_audited_with_the_old_and_the_new(): void
    {
        $this->patchJson(
            self::ENDPOINT,
            ['limits' => [self::LOCKOUT => '45']],
            $this->bearerFor(RoleName::SuperAdmin),
        )->assertStatus(200);

        $old = DB::scalar(
            "select old_values::text from audit_log where event = 'SYSTEM_LIMITS_UPDATED' order by created_at desc limit 1",
        );
        $new = DB::scalar(
            "select new_values::text from audit_log where event = 'SYSTEM_LIMITS_UPDATED' order by created_at desc limit 1",
        );

        self::assertIsString($old);
        self::assertIsString($new);
        self::assertStringContainsString('30', $old);
        self::assertStringContainsString('45', $new);
    }

    // ── refusals ────────────────────────────────────────────────────────────

    /** The key/value table accepts anything; this endpoint does not. */
    public function test_that_an_unknown_key_is_refused(): void
    {
        $this->patchJson(
            self::ENDPOINT,
            ['limits' => ['haxx.enabled' => '1']],
            $this->bearerFor(RoleName::SuperAdmin),
        )->assertStatus(422);

        self::assertSame(0, DB::scalar("select count(*)::int from system_limits where key = 'haxx.enabled'"));
    }

    /** A limit declared `integer` is not a sentence. */
    public function test_that_a_non_numeric_value_for_an_integer_limit_is_refused(): void
    {
        $this->patchJson(
            self::ENDPOINT,
            ['limits' => [self::LOCKOUT => 'half an hour']],
            $this->bearerFor(RoleName::SuperAdmin),
        )->assertStatus(422);

        self::assertSame('30', DB::scalar('select value from system_limits where key = ?', [self::LOCKOUT]));
    }

    /**
     * **A zero-minute lockout never locks.** Point 2.3 chose `is_numeric` over
     * a cast for exactly this reason; the boundary refuses it outright rather
     * than storing a number that silently disables `SEC-03`.
     */
    public function test_that_a_zero_or_negative_integer_limit_is_refused(): void
    {
        $bearer = $this->bearerFor(RoleName::SuperAdmin);

        $this->patchJson(self::ENDPOINT, ['limits' => [self::LOCKOUT => '0']], $bearer)->assertStatus(422);
        $this->patchJson(self::ENDPOINT, ['limits' => [self::LOCKOUT => '-5']], $bearer)->assertStatus(422);
        $this->patchJson(self::ENDPOINT, ['limits' => [self::LOCKOUT => '1.5']], $bearer)->assertStatus(422);

        self::assertSame('30', DB::scalar('select value from system_limits where key = ?', [self::LOCKOUT]));
    }

    public function test_that_an_empty_change_set_is_refused(): void
    {
        $bearer = $this->bearerFor(RoleName::SuperAdmin);

        $this->patchJson(self::ENDPOINT, [], $bearer)->assertStatus(422);
        $this->patchJson(self::ENDPOINT, ['limits' => []], $bearer)->assertStatus(422);
    }

    /**
     * `OpenAPI §5.1`'s `details[].message` is read by a person, so it may not
     * carry the internal key path.
     *
     * **The same defect Point 5.1 measured on `PATCH /settings`, live on the
     * endpoint beside it and found the same way — by building the screen that
     * shows the message.** Measured with a throwaway request before this test
     * was written: the two refusals read *"The limits.limits.stale deal days
     * field format is invalid."* and *"The limits.identity.lockout minutes
     * field format is invalid."*, in Arabic as well as English.
     *
     * `limits.stale_deal_days` is the worse of the two because its own key
     * begins with `limits.`, so the submitted path carries the word twice. The
     * machine code beside the sentence is unchanged and still English — a
     * client matches on that, not on this.
     */
    public function test_that_a_refusal_names_the_limit_in_words_a_person_reads(): void
    {
        $response = $this->patchJson(
            self::ENDPOINT,
            ['limits' => ['limits.stale_deal_days' => 'quite a while']],
            $this->bearerFor(RoleName::SuperAdmin),
        )->assertStatus(422);

        $message = $response->json('error.details.0.message');

        self::assertIsString($message);
        self::assertStringNotContainsString('limits.', $message);
        self::assertStringNotContainsString('stale_deal_days', $message);
        self::assertStringContainsString('stale-deal threshold', $message);

        // The **field** path is untouched: it is the submitted path a client
        // matches a message to its control by, and Point 5.2's screen reads it.
        self::assertSame('limits.limits.stale_deal_days', $response->json('error.details.0.field'));
    }

    /** `D-75`'s row, whose key does not repeat the prefix but still leaked it. */
    public function test_that_the_lockout_refusal_names_the_limit_in_words_too(): void
    {
        $response = $this->patchJson(
            self::ENDPOINT,
            ['limits' => [self::LOCKOUT => 'half an hour']],
            $this->bearerFor(RoleName::SuperAdmin),
        )->assertStatus(422);

        $message = $response->json('error.details.0.message');

        self::assertIsString($message);
        self::assertStringNotContainsString('identity.', $message);
        self::assertStringContainsString('lockout', $message);
        self::assertSame('limits.identity.lockout_minutes', $response->json('error.details.0.field'));
    }

    /** §13 screen 6 is a form, not a resource collection — no create, no delete. */
    public function test_that_the_resource_carries_no_create_or_delete_verb(): void
    {
        $bearer = $this->bearerFor(RoleName::SuperAdmin);

        $this->postJson(self::ENDPOINT, ['limits' => []], $bearer)->assertStatus(405);
        $this->deleteJson(self::ENDPOINT, [], $bearer)->assertStatus(405);
    }
}
