<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Authentication;

use RuntimeException;

/**
 * A refused device revocation — `SEC-05`.
 *
 * Framework-free for the reason `deptrac.layers.yaml` gives Domain an empty
 * ruleset: the status and the `OpenAPI §5.1` code live on the reason, and
 * turning them into an HTTP response is `ApiExceptionRenderer`'s job one layer
 * out.
 */
final class SessionRevocationRefused extends RuntimeException
{
    private function __construct(public readonly SessionRefusal $reason)
    {
        parent::__construct('Session revocation refused: '.$reason->value);
    }

    public static function because(SessionRefusal $reason): self
    {
        return new self($reason);
    }
}
