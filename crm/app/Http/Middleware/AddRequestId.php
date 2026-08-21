<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * OpenAPI §3.3: X-Request-Id is server-generated and returned on every response,
 * for logs, errors, audit correlation and support reports.
 *
 * X-Correlation-Id is caller-supplied and optional. §3.3 says to "validate and
 * propagate it; otherwise create one", and D-69 fixes what validating means:
 * the value is accepted only when it matches CORRELATION_PATTERN, and anything
 * else — missing, empty, malformed, overlong — is ignored and replaced by a
 * server-generated id. The request still succeeds, because the header is
 * optional and an optional trace header must never be able to fail a request.
 *
 * It is never trusted as an authorization input, which is why it is only ever
 * echoed, never read for a decision.
 */
final class AddRequestId
{
    public const ATTRIBUTE = 'request_id';

    public const CORRELATION = 'correlation_id';

    /**
     * D-69. The character class is the allowlist that keeps a caller-supplied
     * value safe to write into a log line or an audit row (AUD-05, Coding
     * Standards §10) — no whitespace, no CR/LF, no quoting or markup
     * characters, so nothing here can forge a log record or escape a context.
     *
     * The D modifier is load-bearing rather than decorative: without it PCRE's
     * `$` also matches immediately before a trailing newline, so "abc\n" would
     * pass this very pattern and put a line break into the logs. D makes `$`
     * mean end of subject, which is what the rule says.
     */
    public const CORRELATION_PATTERN = '/^[A-Za-z0-9._-]{1,128}$/D';

    /** D-69. The upper bound expressed in the pattern above, for callers and tests. */
    public const CORRELATION_MAX_LENGTH = 128;

    /**
     * Closure carries no return type of its own, so static analysis sees the
     * result of $next as mixed and every use of it as an error. Annotating the
     * callable shape is what makes this middleware analysable at level 10 —
     * Coding Standards §5 forbids untyped escape hatches, and lowering the
     * level to accommodate this file would have been exactly that.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::uuid();
        $correlationId = $this->correlationFrom($request) ?? $requestId;

        $request->attributes->set(self::ATTRIBUTE, $requestId);
        $request->attributes->set(self::CORRELATION, $correlationId);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);
        $response->headers->set('X-Correlation-Id', $correlationId);

        return $response;
    }

    /**
     * The caller's correlation id when it is one this system will repeat, and
     * null when there is nothing usable to propagate.
     *
     * Coding Standards §5 requires every untrusted value to be validated at the
     * backend boundary, and names the header explicitly. Absence and rejection
     * deliberately collapse into the same answer: §3.3 prescribes one behaviour
     * for both — create one.
     *
     * Note what is *not* here: no `?:`, and no falsy test. "0" is a legitimate
     * trace id and must survive; the elvis operator discarded it silently,
     * which is the kind of defect that only ever appears in production logs as
     * a correlation id that does not correlate.
     */
    private function correlationFrom(Request $request): ?string
    {
        $supplied = $request->headers->get('X-Correlation-Id');

        if ($supplied === null || $supplied === '') {
            return null;
        }

        return preg_match(self::CORRELATION_PATTERN, $supplied) === 1
            ? $supplied
            : null;
    }
}
