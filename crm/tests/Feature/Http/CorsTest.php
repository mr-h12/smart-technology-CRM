<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Tests\TestCase;

/**
 * F-26 · 1.1: the SPA calls `/api/v1` from its own origin (D-67), so no other
 * origin is given CORS access. Laravel's built-in default answered `*`.
 */
class CorsTest extends TestCase
{
    public function test_that_a_foreign_origin_gets_no_allow_origin_header(): void
    {
        $this->call('OPTIONS', '/api/v1/deals', server: [
            'HTTP_ORIGIN' => 'https://evil.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ])->assertHeaderMissing('Access-Control-Allow-Origin');

        $this->getJson('/api/v1/deals', ['Origin' => 'https://evil.example'])
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }
}
