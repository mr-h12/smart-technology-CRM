<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\RoleAdministration;

use App\Modules\Identity\Domain\Rbac\PermissionMatrix;
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
     * Whether `§3.12` rule 3 allows this permission to be granted to anybody.
     *
     * Rule 3 — "No hard deletes for customers, deals, reports or suppliers" —
     * is a property of the `resource.action` pair, not of the triple:
     * `customer.delete.own` is no more grantable than `customer.delete.all`.
     * {@see PermissionMatrix::forbiddenKeys()} derives the set from the matrix
     * itself (a row every role holds `—` on), so the list is never re-typed.
     *
     * Asked here rather than in each caller so the use case that refuses a
     * grant and the payload that greys out its checkbox are the *same*
     * question. They were two copies of one `in_array` for exactly one point,
     * which is how a screen ends up offering what the API refuses.
     */
    public function isGrantable(): bool
    {
        return ! in_array($this->key(), PermissionMatrix::forbiddenKeys(), true);
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
