<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Administration;

use RuntimeException;

/**
 * A refused user-administration command.
 *
 * An exception rather than a return value because every caller of every use
 * case here would otherwise have to remember to check — and the one that
 * forgets writes the row anyway. {@see \App\Support\Http\ApiExceptionRenderer}
 * turns it into the `OpenAPI §5` envelope.
 *
 * The message is for logs. What the caller sees is built from
 * {@see AdministrationRefusal::messageKey()}, because no user-facing string
 * lives in PHP (`§14.2`, Coding Standards §11).
 */
final class UserAdministrationRefused extends RuntimeException
{
    private function __construct(public readonly AdministrationRefusal $reason)
    {
        parent::__construct('User administration refused: '.$reason->value);
    }

    public static function because(AdministrationRefusal $reason): self
    {
        return new self($reason);
    }
}
