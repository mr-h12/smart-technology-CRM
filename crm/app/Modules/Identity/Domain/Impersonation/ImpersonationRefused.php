<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Impersonation;

use RuntimeException;

/**
 * A refused Login As.
 *
 * The message is for logs; what the caller sees is built from
 * {@see ImpersonationRefusal::messageKey()}, because no user-facing string
 * lives in PHP (§14.2, Coding Standards §11).
 */
final class ImpersonationRefused extends RuntimeException
{
    private function __construct(public readonly ImpersonationRefusal $reason)
    {
        parent::__construct('Impersonation refused: '.$reason->value);
    }

    public static function because(ImpersonationRefusal $reason): self
    {
        return new self($reason);
    }
}
