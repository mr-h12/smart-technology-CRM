<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\RoleAdministration;

use App\Modules\Identity\Domain\Rbac\Scope;

/**
 * One `permissions` row as the administration screen reads it.
 *
 * A value object and not the Eloquent `Permission`, for the reason `D-77`
 * exists: Application and Domain may not touch Illuminate, and the screen needs
 * five strings rather than a model.
 */
final readonly class PermissionView
{
    public function __construct(
        public string $id,
        public string $resource,
        public string $action,
        public string $scope,
    ) {}

    /** `§3.2`'s own notation — "Permission = Resource + Action + Scope". */
    public function triple(): string
    {
        return $this->resource.'.'.$this->action.'.'.$this->scope;
    }

    /** The `resource.action` pair, which is what §3.12 rule 3 forbids by name. */
    public function key(): string
    {
        return $this->resource.'.'.$this->action;
    }

    /**
     * The typed scope, or null for a row whose `scope` column holds something
     * §3.2 does not define.
     *
     * `tryFrom` and not `from`: the column carries a CHECK constraint listing
     * the five, but a listing endpoint must not become a 500 because one row
     * got past it. {@see \App\Modules\Identity\Infrastructure\EloquentPermissionRepository}
     * makes the same choice on the authorisation path.
     */
    public function scopeValue(): ?Scope
    {
        return Scope::tryFrom($this->scope);
    }
}
