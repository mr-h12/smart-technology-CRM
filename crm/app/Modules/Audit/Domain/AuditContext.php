<?php

declare(strict_types=1);

namespace App\Modules\Audit\Domain;

use InvalidArgumentException;

/**
 * Who and from where — the half of `AUD-02` that comes from the request rather
 * than from the operation.
 *
 * Every field is nullable, and that is not laziness. A scheduled job has no
 * actor, no IP, no user agent and no inbound request; `J-15` is already such a
 * caller. A `NOT NULL` here would make the audit write fail precisely when the
 * system acts on its own behalf, and `AUD-01` with `DB-11` puts that write
 * inside the business transaction.
 */
final readonly class AuditContext
{
    /**
     * `D-69`, enforced a second time.
     *
     * `AddRequestId` already validates the header at the boundary, and this is
     * deliberately not trusting that: a caller can build a context by hand, and
     * `AUD-03` makes a forged log line permanent. The rule is duplicated
     * because neither file can reference the other without pointing a module's
     * domain at HTTP middleware or the reverse — `AuditRecorderTest` asserts
     * the two constants are identical, which is what notices the drift.
     */
    public const CORRELATION_PATTERN = '/^[A-Za-z0-9._-]{1,128}$/D';

    public function __construct(
        public ?string $actorId,
        public ?string $ip,
        public ?string $userAgent,
        public ?string $requestId,
        public ?string $correlationId,
    ) {
        if ($correlationId !== null && preg_match(self::CORRELATION_PATTERN, $correlationId) !== 1) {
            throw new InvalidArgumentException(
                'A correlation id must match the D-69 shape: ^[A-Za-z0-9._-]{1,128}$.',
            );
        }
    }

    /** The system acting on its own behalf: a job, a command, a migration. */
    public static function system(): self
    {
        return new self(null, null, null, null, null);
    }
}
