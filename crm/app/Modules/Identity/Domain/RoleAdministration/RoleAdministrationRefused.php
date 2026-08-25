<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\RoleAdministration;

use RuntimeException;

/**
 * A refused permission-matrix command.
 *
 * Framework-free for the reason `deptrac.layers.yaml` gives Domain an empty
 * ruleset: the status and the `OpenAPI §5.1` code live on the reason, and
 * turning them into an HTTP response is `ApiExceptionRenderer`'s job one layer
 * out.
 */
final class RoleAdministrationRefused extends RuntimeException
{
    private function __construct(public readonly RoleAdministrationRefusal $reason)
    {
        parent::__construct('Role administration refused: '.$reason->value);
    }

    public static function because(RoleAdministrationRefusal $reason): self
    {
        return new self($reason);
    }
}
