<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure;

use App\Modules\Audit\Domain\AuditContext;
use App\Modules\Audit\Domain\Contracts\AuditContextResolverInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;

/**
 * The ambient half of `AUD-02`, read from the request `AddRequestId` has
 * already been through.
 *
 * ── Why the attribute names are repeated here ──────────────────────────────
 *
 * `AddRequestId` lives in `app/Http`, which is outside `deptrac`'s
 * `app/Modules` path, so naming its constants from inside a module would make
 * every reference an uncovered dependency and turn a clean gate into a report
 * nobody reads. The names are therefore duplicated, and `AuditRecorderTest`
 * asserts they are identical to the middleware's — which is what notices the
 * drift the duplication invites.
 *
 * ── Why the request id decides, not the request ────────────────────────────
 *
 * Laravel binds a `request` even in a console process, so "is there a request"
 * is always yes and always useless. The honest question is whether this call
 * came through the HTTP middleware at all, and the presence of the attribute
 * that middleware sets is the only thing that answers it.
 */
final readonly class RequestAuditContext implements AuditContextResolverInterface
{
    /** Mirrors `AddRequestId::ATTRIBUTE`. */
    public const REQUEST_ATTRIBUTE = 'request_id';

    /** Mirrors `AddRequestId::CORRELATION`. */
    public const CORRELATION_ATTRIBUTE = 'correlation_id';

    public function __construct(private Container $container) {}

    public function current(): AuditContext
    {
        // No instanceof guard: Laravel binds a `request` in every context,
        // console included, so the check was always true and static analysis
        // said so. The attribute below is the real gate.
        $request = $this->container->make(Request::class);

        $requestId = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        if (! is_string($requestId)) {
            // A console command, a queued job, a test that never went through
            // the middleware. J-15 is already such a caller.
            return AuditContext::system();
        }

        $correlationId = $request->attributes->get(self::CORRELATION_ATTRIBUTE);

        return new AuditContext(
            actorId: self::actorId($request),
            ip: $request->getClientIp(),
            userAgent: $request->userAgent(),
            requestId: $requestId,
            correlationId: is_string($correlationId) ? $correlationId : null,
        );
    }

    /**
     * Null until Module 1 exists.
     *
     * `user()` is answered by whatever guard is configured, and there is no
     * users table yet — Module 1 replaces Laravel's rather than extending it.
     * Reading it now returns null, which is the correct answer rather than a
     * placeholder, and the column is nullable for exactly this reason.
     */
    private static function actorId(Request $request): ?string
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        $id = $user->getAuthIdentifier();

        return is_string($id) || is_int($id) ? (string) $id : null;
    }
}
