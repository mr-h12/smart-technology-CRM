<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Illuminate\Cache\RedisStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Module 0's first acceptance line: "app runs · frontend talks to backend ·
 * database connects".
 *
 * Every piece of this has been asserted somewhere. What has never been asserted
 * is that they are joined. The shell is tested for language and direction, the
 * ping route for its envelope, the database for its precision, Redis for its
 * queues — four green suites that would all stay green if the SPA were asking
 * a path the server does not serve, which is exactly the failure this closes.
 *
 * ── Why the seam is a file comparison ───────────────────────────────────────
 *
 * `/api/v1` is written in two places that never see each other: `api.ts`
 * prefixes it onto every call, and `bootstrap/app.php` registers it as
 * `apiPrefix`. PHP cannot execute the TypeScript and the bundle cannot read the
 * router, so nothing connects them — the same shape as the queue names in point
 * 8.1, and the same fix.
 *
 * ── What this is not ────────────────────────────────────────────────────────
 *
 * Not a health endpoint. `ST-08` asks for `/health` reporting each service
 * explicitly, `routes/api.php` records why that is not this route, and the
 * owner reaffirmed the deferral on 2026-08-23. This file *checks* the
 * dependencies from the test process; it does not publish that check to
 * anybody. The last test below fails if a `/health` route appears without the
 * decision being revisited.
 */
final class EndToEndConnectivityTest extends TestCase
{
    use RefreshDatabase;

    /** API-02, and the one string the client and the router must agree on. */
    private const API_PREFIX = 'api/v1';

    /**
     * Redis database 15 — the same isolation point 8.2 established for queues,
     * extended here to the cache. The running application uses database 1 for
     * its cache (`REDIS_CACHE_DB` defaults to 1), and a test writing there
     * would evict a developer's live cache entries.
     */
    private const TEST_REDIS_DATABASE = '15';

    // ────────────────────────────────────────────────────── link 1 — the shell

    public function test_the_server_returns_the_spa_shell_for_a_deep_link(): void
    {
        // D-67: history mode, no hash fragment. A deep link only works because
        // every non-API path falls through to the shell and vue-router resolves
        // it on the client.
        $response = $this->get('/deals/01890000-0000-7000-8000-000000000000');

        $response->assertOk();
        $response->assertSee('id="app"', false);
    }

    public function test_an_unknown_api_path_is_refused_rather_than_answered_with_the_shell(): void
    {
        // The catch-all's `api/` exclusion, asserted from the outside. Without
        // it a removed endpoint returns 200 with HTML in it, and a client cannot
        // tell it from a working one.
        $response = $this->getJson('/'.self::API_PREFIX.'/does-not-exist');

        $response->assertNotFound();
        self::assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
    }

    // ──────────────────────────────────────────────────── link 2 — the contract

    public function test_the_client_and_the_router_agree_on_the_api_prefix(): void
    {
        $client = self::read(base_path('resources/js/api.ts'));
        $routing = self::read(base_path('bootstrap/app.php'));

        self::assertStringContainsString('/'.self::API_PREFIX.'${path}', $client,
            'api.ts must call the versioned prefix the router registers (API-02).');

        self::assertMatchesRegularExpression(
            '/apiPrefix:\s*\''.preg_quote(self::API_PREFIX, '/').'\'/',
            $routing,
            'bootstrap/app.php must register the prefix api.ts calls.',
        );
    }

    public function test_the_language_the_shell_declares_is_the_language_the_api_answers_in(): void
    {
        // api.ts sends `Accept-Language: document.documentElement.lang`, and the
        // shell is what sets that attribute. This is the whole loop: the server
        // tells the document its language, and the document tells it back on the
        // next call.
        $shell = $this->withHeader('Accept-Language', 'ar')->get('/');
        $shell->assertOk();
        $shell->assertSee('lang="ar"', false);

        $this->withHeader('Accept-Language', 'ar')->getJson('/'.self::API_PREFIX.'/ping')->assertOk();
        self::assertSame('ar', App::getLocale());

        $this->withHeader('Accept-Language', 'en')->getJson('/'.self::API_PREFIX.'/ping')->assertOk();
        self::assertSame('en', App::getLocale());
    }

    // ────────────────────────────────────────────────────────── link 3 — the API

    public function test_the_api_answers_in_the_documented_envelope(): void
    {
        $response = $this->getJson('/'.self::API_PREFIX.'/ping');

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['service', 'time'], 'meta' => ['request_id']]);

        // OpenAPI §3.3: server-generated, on every response. api.ts prefers the
        // header and falls back to the envelope, so the two must not disagree.
        $header = $response->headers->get('X-Request-Id');
        self::assertIsString($header);
        self::assertNotSame('', $header);
        self::assertSame($header, $response->json('meta.request_id'));
    }

    // ─────────────────────────────────────────────────── link 4 — PostgreSQL

    public function test_the_database_answers_a_real_query_on_the_connection_the_application_uses(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());

        // `DB::scalar` rather than `select()[0]->column`: the latter is
        // `list<mixed>` to PHPStan at level 10, and the cast that silences it
        // would also silence a driver returning a string — which is exactly the
        // thing worth knowing about a connection.
        self::assertSame(1, DB::scalar('select 1 as reachable'), 'PostgreSQL did not answer.');
    }

    public function test_module_zero_tables_exist_on_that_same_connection(): void
    {
        // "The database connects" is not "a socket opened". A connection that
        // answers `select 1` against an empty schema is a connection to nothing
        // the application can use.
        foreach (['migrations', 'files', 'audit_log', 'document_sequences'] as $table) {
            self::assertTrue(Schema::hasTable($table), "Module 0's `{$table}` is missing.");
        }

        self::assertGreaterThan(0, DB::table('migrations')->count(), 'No migration has run on this connection.');
    }

    // ──────────────────────────────────────────────────────── link 5 — Redis

    public function test_redis_answers_and_the_suite_is_isolated_from_the_running_application(): void
    {
        self::assertSame(self::TEST_REDIS_DATABASE, self::configured('database.redis.default.database'));
        self::assertSame(self::TEST_REDIS_DATABASE, self::configured('database.redis.cache.database'),
            'The application caches on database 1. A test writing there evicts live entries.');

        $pong = Redis::connection()->ping();
        self::assertNotSame(false, $pong, 'Redis did not answer PING.');
    }

    public function test_the_cache_round_trips_through_real_redis(): void
    {
        // phpunit.xml puts CACHE_STORE on `array` so ordinary tests stay
        // hermetic. That is left alone; this names the redis store explicitly,
        // which is also what makes the round trip real rather than in-process.
        $store = Cache::store('redis');
        self::assertInstanceOf(RedisStore::class, $store->getStore());

        $key = 'connectivity-probe:'.bin2hex(random_bytes(8));

        self::assertNull($store->get($key), 'The probe key must not already exist.');

        $store->put($key, 'reachable', 60);
        self::assertSame('reachable', $store->get($key), 'Redis accepted a write and did not return it.');

        $store->forget($key);
        self::assertNull($store->get($key), 'Redis kept a key that was forgotten.');
    }

    // ────────────────────────────────────────────────────────── the whole chain

    public function test_the_stack_answers_from_the_shell_to_the_database_and_the_cache(): void
    {
        // One test that walks the chain in order, so a failure names the link
        // rather than a symptom. The tests above check each link in isolation;
        // this is the one that would have caught them being joined wrongly.
        $shell = $this->get('/');
        $shell->assertOk();
        self::assertStringContainsString('id="app"', (string) $shell->getContent(), 'Link 1: the shell is not served.');

        $api = $this->getJson('/'.self::API_PREFIX.'/ping');
        $api->assertOk();
        self::assertIsString($api->json('data.service'), 'Link 2: the API did not answer the shell.');

        self::assertSame(1, DB::scalar('select 1 as reachable'), 'Link 3: the database did not answer.');

        $key = 'connectivity-chain:'.bin2hex(random_bytes(8));
        Cache::store('redis')->put($key, 'reachable', 60);
        self::assertSame('reachable', Cache::store('redis')->get($key), 'Link 4: the cache did not answer.');
        Cache::store('redis')->forget($key);
    }

    // ──────────────────────────────────────────── the deferral, still in force

    public function test_no_health_endpoint_has_appeared(): void
    {
        // ST-08 and OBS-01 are owed a `/health` reporting each service. The
        // decision to defer it is recorded in routes/api.php and was reaffirmed
        // by the owner on 2026-08-23. This fails if one appears, so publishing
        // it stays a decision rather than a commit.
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();

            self::assertFalse(
                $uri === 'health' || str_ends_with($uri, '/health'),
                "A /health route appeared at '{$uri}'. ST-08 is deferred by a recorded decision; "
                .'revisit it rather than routing around it.',
            );
        }

        self::assertStringContainsString(
            'deliberately not /health',
            self::read(base_path('routes/api.php')),
            'The recorded reason for the deferral was removed from routes/api.php.',
        );
    }

    public function test_the_framework_boot_probe_is_not_mistaken_for_that_endpoint(): void
    {
        // bootstrap/app.php registers Laravel's own `/up`. It answers 200 once
        // the framework boots and reports nothing about PostgreSQL, Redis,
        // Meilisearch, the queues or storage, so it satisfies none of ST-08 and
        // is named here so nobody reads it as coverage. It is also unversioned
        // and outside the API surface on purpose.
        $this->get('/up')->assertOk();

        self::assertFalse(
            str_starts_with('up', self::API_PREFIX),
            'The boot probe must stay outside the versioned API.',
        );
    }

    // ───────────────────────────────────────────────────────────── mechanics

    private static function configured(string $key): string
    {
        $value = config($key);
        self::assertIsScalar($value, "config('{$key}') is not a scalar.");

        return (string) $value;
    }

    private static function read(string $path): string
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Could not read {$path}.");
        }

        return $contents;
    }
}
