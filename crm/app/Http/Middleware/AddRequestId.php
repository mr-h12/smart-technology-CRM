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
 * X-Correlation-Id is caller-supplied and optional. It is propagated when
 * present and generated when not — and never trusted as an authorization input,
 * which is why it is only ever echoed, never read for a decision.
 */
final class AddRequestId
{
    public const ATTRIBUTE = 'request_id';

    public const CORRELATION = 'correlation_id';

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
        $correlationId = $request->headers->get('X-Correlation-Id') ?: $requestId;

        $request->attributes->set(self::ATTRIBUTE, $requestId);
        $request->attributes->set(self::CORRELATION, $correlationId);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);
        $response->headers->set('X-Correlation-Id', $correlationId);

        return $response;
    }
}
