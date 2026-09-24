<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

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
}
