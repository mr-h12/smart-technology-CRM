<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Rbac;

/**
 * One row of §3's matrix.
 *
 * A role absent from `$grants` is not permitted — the document's `—` and `❌`
 * are the same thing here, because neither grants anything and inventing a
 * distinction between "not applicable" and "refused" would put a second
 * meaning into an authorisation table.
 */
final readonly class Permission
{
    /** @var array<string, Grant> keyed by Role::value */
    private array $grants;

    /**
     * @param  array<string, Grant>  $grants  keyed by Role::value
     * @param  string  $section  the §3 subsection this row was read from
     */
    public function __construct(
        private string $resource,
        private string $action,
        array $grants,
        private string $section,
    ) {
        $this->grants = $grants;
    }

    public function key(): string
    {
        return $this->resource.'.'.$this->action;
    }

    public function resource(): string
    {
        return $this->resource;
    }

    public function action(): string
    {
        return $this->action;
    }

    public function section(): string
    {
        return $this->section;
    }

    /** @return array<string, Grant> keyed by Role::value */
    public function grants(): array
    {
        return $this->grants;
    }

    public function grantFor(Role $role): ?Grant
    {
        return $this->grants[$role->value] ?? null;
    }
}
