<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Http\Middleware\AddRequestId;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * OpenAPI §3.3 and D-69.
 *
 * X-Request-Id is always server-generated. X-Correlation-Id is caller-supplied,
 * optional, and accepted only when it matches D-69's pattern; anything else is
 * ignored and replaced, and the request still succeeds.
 *
 * Every assertion here runs through the HTTP kernel against a real route and
 * reads the response headers a client would receive — not the middleware in
 * isolation. A validation rule that is only true when called directly is not a
 * rule the system has (SEC-12, Coding Standards §5).
 */
final class RequestIdTest extends TestCase
{
    private const ROUTE = '/api/v1/ping';

    // ---------------------------------------------------------------- happy path

    /** @return array<string, array{0: string}> */
    public static function acceptedValues(): array
    {
        return [
            // The two shapes the contract itself names: §3.3 describes
            // Idempotency-Key as "a UUID or similarly high-entropy key", and
            // the §4.1 envelope example carries a prefixed ULID.
            'uuid v4' => ['9f1c2f4e-6b3a-4d5e-8a70-1f2e3d4c5b6a'],
            'prefixed ulid' => ['req_01J5Y8P7TJ1J8DNM7ED6K2D1XQ'],
            // W3C Trace Context and the hyphenated ids most tracing clients emit.
            'traceparent style' => ['00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'],
            'trace style' => ['trace-abc-123'],
            'dotted' => ['crm.api.7f3c9d'],
            'underscored' => ['job_2026_08_21_000123'],
            // D-69 decision 3. "0" is a legitimate id and the elvis operator
            // this replaced discarded it silently — a correlation id that does
            // not correlate, visible only in production logs.
            'the single character zero' => ['0'],
            // The boundary itself, read from the constant so the pattern and
            // the documented maximum cannot drift apart unnoticed.
            'exactly the maximum length' => [self::repeat(AddRequestId::CORRELATION_MAX_LENGTH)],
            'a single character' => ['A'],
        ];
    }

    #[DataProvider('acceptedValues')]
    public function test_a_valid_correlation_id_is_propagated_exactly(string $supplied): void
    {
        $response = $this->withHeader('X-Correlation-Id', $supplied)->getJson(self::ROUTE);

        $response->assertOk();
        $response->assertHeader('X-Correlation-Id', $supplied);
    }

    // ------------------------------------------------------------- failure / edge

    /** @return array<string, array{0: string}> */
    public static function rejectedValues(): array
    {
        return [
            'empty' => [''],
            'only whitespace' => ['   '],
            // Log and audit injection: AUD-05 writes this value into structured
            // logs and Coding Standards §10 into audit rows, both of which are
            // permanent. A quote, a brace or a separator forges a record there.
            'markup' => ['value-with-<markup>-inside'],
            'a log field separator' => ['abc;def'],
            'json structure' => ['{"level":"error"}'],
            'a quote' => ['abc"def'],
            'a space' => ['two words'],
            // PCRE's `$` matches before a trailing newline unless the D
            // modifier is set. Without D this exact value passes the pattern
            // and puts a line break into the log file, so this case is what
            // proves the modifier is present. Never sent over the wire — this
            // request is built in-process and never reaches nginx.
            'a trailing newline' => ["abc\n"],
            'an embedded newline' => ["abc\ndef"],
            'a carriage return' => ["abc\rdef"],
            'a null byte' => ["abc\0def"],
            'a path traversal attempt' => ['../../etc/passwd'],
            'non-ascii' => ['معرّف-عربي'],
            // The length boundary, from both sides.
            'one character over the maximum' => [self::repeat(AddRequestId::CORRELATION_MAX_LENGTH + 1)],
            // The length a caller can actually reach today: nginx's default
            // header buffer passes 8000 characters, so 4000 is comfortably
            // inside what reaches the application unvalidated.
            'four thousand characters' => [self::repeat(4000)],
        ];
    }

    #[DataProvider('rejectedValues')]
    public function test_an_unusable_correlation_id_is_replaced_rather_than_rejected(string $supplied): void
    {
        $response = $this->withHeader('X-Correlation-Id', $supplied)->getJson(self::ROUTE);

        // D-69 decision 2: the header is optional, so a bad value must not be
        // able to fail the request. 200, never 400.
        $response->assertOk();

        $returned = $response->headers->get('X-Correlation-Id');

        self::assertIsString($returned);
        self::assertNotSame($supplied, $returned, 'the rejected value was echoed back to the client');
        self::assertMatchesRegularExpression(AddRequestId::CORRELATION_PATTERN, $returned);
    }

    #[DataProvider('rejectedValues')]
    public function test_a_rejected_correlation_id_never_appears_in_the_response(string $supplied): void
    {
        $response = $this->withHeader('X-Correlation-Id', $supplied)->getJson(self::ROUTE);

        // The negative test Documentation_Map §"Security incident or
        // vulnerability fix" asks for: the untrusted value is not reflected
        // anywhere a client or a log reader can see it.
        $needle = trim($supplied);

        if ($needle === '') {
            self::assertSame(200, $response->getStatusCode());

            return;
        }

        self::assertStringNotContainsString($needle, $response->getContent() ?: '');

        foreach ($response->headers->all() as $values) {
            foreach ($values as $value) {
                self::assertStringNotContainsString($needle, (string) $value);
            }
        }
    }

    // ------------------------------------------------------------------ generation

    public function test_a_missing_header_produces_a_server_generated_correlation_id(): void
    {
        $response = $this->getJson(self::ROUTE);

        $response->assertOk();

        $correlation = $response->headers->get('X-Correlation-Id');
        $requestId = $response->headers->get('X-Request-Id');

        self::assertIsString($correlation);
        self::assertIsString($requestId);
        self::assertMatchesRegularExpression(AddRequestId::CORRELATION_PATTERN, $correlation);

        // §3.3 "otherwise create one" — with nothing to trace against, the
        // generated correlation id is the request id, so one request is still
        // one identifier rather than two unrelated ones.
        self::assertSame($requestId, $correlation);
    }

    public function test_the_request_id_is_always_server_generated_and_never_taken_from_the_caller(): void
    {
        $supplied = 'trace-abc-123';

        $first = $this->withHeader('X-Correlation-Id', $supplied)->getJson(self::ROUTE);
        $second = $this->withHeader('X-Correlation-Id', $supplied)->getJson(self::ROUTE);

        $firstId = $first->headers->get('X-Request-Id');
        $secondId = $second->headers->get('X-Request-Id');

        self::assertIsString($firstId);
        self::assertIsString($secondId);
        self::assertNotSame($supplied, $firstId);
        self::assertNotSame($firstId, $secondId, 'X-Request-Id must be unique per request');

        // §4.1: the envelope carries the same request id the header does.
        $first->assertJsonPath('meta.request_id', $firstId);
    }

    public function test_both_identifiers_are_present_on_every_response(): void
    {
        $response = $this->getJson(self::ROUTE);

        $response->assertOk();
        $response->assertHeader('X-Request-Id');
        $response->assertHeader('X-Correlation-Id');
    }

    /**
     * The middleware is registered globally in bootstrap/app.php, so the web
     * surface carries the identifiers too — the SPA shell is served from there.
     */
    public function test_the_identifiers_are_present_on_the_web_surface_as_well(): void
    {
        $response = $this->withHeader('X-Correlation-Id', 'not a valid id')->get('/');

        $response->assertOk();

        $returned = $response->headers->get('X-Correlation-Id');

        self::assertIsString($returned);
        self::assertNotSame('not a valid id', $returned);
    }

    private static function repeat(int $length): string
    {
        return str_repeat('A', $length);
    }
}
