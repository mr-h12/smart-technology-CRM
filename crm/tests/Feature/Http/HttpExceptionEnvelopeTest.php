<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Storage\Domain\AttachmentParent;
use App\Modules\Storage\Domain\Contracts\FileRepositoryInterface;
use App\Modules\Storage\Domain\ScanStatus;
use App\Modules\Storage\Domain\StoredFile;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * F-17 · 1.1 — `OpenAPI §5`: "All non-2xx responses use this shape. Never
 * return framework-default HTML or unstructured error objects."
 *
 * An `abort()`, a route that does not exist and a wrong method all raise a
 * Symfony `HttpException`, which the renderer did not map: the caller got
 * Laravel's default body, with a stack trace while debug is on (E5-7, E5-9).
 * Debug is on in testing, so a leaked trace shows here as it did in the QA.
 */
final class HttpExceptionEnvelopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_missing_file_answers_404_in_the_envelope_without_a_trace(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get('/api/v1/files/'.Str::uuid7()->toString().'/download');

        $response->assertNotFound()
            ->assertJsonPath('error.code', 'resource_not_found')
            ->assertJsonPath('error.message', __('http-statuses.404'))
            ->assertJsonMissingPath('trace')
            ->assertJsonMissingPath('exception');
        self::assertIsString($response->json('meta.request_id'));
    }

    public function test_the_message_follows_the_request_language(): void
    {
        $this->actingAs(User::factory()->create())
            ->withHeader('Accept-Language', 'ar')
            ->get('/api/v1/files/'.Str::uuid7()->toString().'/download')
            ->assertNotFound()
            ->assertJsonPath('error.message', 'الصفحة غير موجودة');
    }

    public function test_an_unknown_api_route_answers_404_in_the_envelope(): void
    {
        $this->get('/api/v1/no-such-route')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'resource_not_found')
            ->assertJsonMissingPath('trace');
    }

    /**
     * 405 is not in `OpenAPI §5.1`'s table; the owner's ruling (2026-09-24)
     * keeps the status and gives an unlisted 4xx `invalid_request`. RFC 9110
     * requires `Allow` on a 405, so the exception's headers survive.
     */
    public function test_a_wrong_method_answers_405_in_the_envelope_with_its_allow_header(): void
    {
        $this->put('/api/v1/files/'.Str::uuid7()->toString().'/download')
            ->assertStatus(405)
            ->assertHeader('Allow')
            ->assertJsonPath('error.code', 'invalid_request')
            ->assertJsonPath('error.message', __('http-statuses.405'))
            ->assertJsonMissingPath('trace');
    }

    /**
     * F-17 · 1.3 — `OpenAPI §5.1`'s 500 row: "Unexpected failure. Log
     * internally; never expose stack trace, SQL, secrets, or sensitive data."
     * The owner's ruling (2026-09-24): the envelope holds with debug on too,
     * and debug is on in testing, so a leaked trace shows here.
     */
    public function test_an_unexpected_failure_answers_500_internal_error_without_trace_sql_or_message(): void
    {
        Exceptions::fake();
        $this->findThrows(new RuntimeException('SQLSTATE[42P01]: Undefined table: relation "secret_table"'));

        $response = $this->actingAs(User::factory()->create())
            ->get('/api/v1/files/'.Str::uuid7()->toString().'/download');

        $response->assertStatus(500)
            ->assertExactJsonStructure(['error' => ['code', 'message'], 'meta' => ['request_id']])
            ->assertJsonPath('error.code', 'internal_error')
            ->assertJsonPath('error.message', __('http-statuses.500'));
        self::assertStringNotContainsString('SQLSTATE', (string) $response->getContent());
        self::assertStringNotContainsString('secret_table', (string) $response->getContent());
        Exceptions::assertReported(RuntimeException::class);
    }

    /**
     * F-17 · 1.3, the owner's ruling (2026-09-24): an `HttpResponseException`
     * carries a response built on purpose, so it is not a failure, and the
     * catch-all hands that response back rather than turning it into a 500.
     * Thrown from a controller it never reaches the handler — `Route::run()`
     * catches it — so this one comes from middleware, the way the framework
     * raises it: a rate limiter with its own `->response()`.
     */
    public function test_a_deliberate_response_exception_keeps_its_own_status_and_body(): void
    {
        RateLimiter::for('login', static fn (): Limit => Limit::perMinute(1)
            ->response(static fn (): JsonResponse => new JsonResponse(['deliberate' => true], 418)));

        $this->postJson('/api/v1/auth/login');

        $this->postJson('/api/v1/auth/login')
            ->assertStatus(418)
            ->assertExactJson(['deliberate' => true]);
    }

    /** The download's first call, `find()`, throws — a real endpoint failing unexpectedly. */
    private function findThrows(Throwable $failure): void
    {
        $this->app->instance(FileRepositoryInterface::class, new readonly class($failure) implements FileRepositoryInterface
        {
            public function __construct(private Throwable $failure) {}

            public function find(string $id): ?StoredFile
            {
                throw $this->failure;
            }

            public function parentsOf(string $fileId): array
            {
                throw $this->failure;
            }

            public function filesOf(AttachmentParent $parent, string $parentId): array
            {
                throw $this->failure;
            }

            public function recordScan(string $fileId, ScanStatus $status): void
            {
                throw $this->failure;
            }
        });
    }
}
