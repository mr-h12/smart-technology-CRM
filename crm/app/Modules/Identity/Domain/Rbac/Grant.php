<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Rbac;

/**
 * One cell of §3's matrix: a role may do this, at this scope.
 *
 * Every grant carries a scope, because `SEC-07` addresses a permission as
 * `resource.action.scope` and there is no fourth part meaning "yes". The
 * document, though, writes two different kinds of cell — an explicit `All`,
 * `Team`, `Own`, `Out` or `Asgn`, and a bare `✅` — and this keeps the
 * difference visible instead of flattening it.
 *
 * That matters because the bare ✅ had to be *interpreted*: it means "at this
 * role's scope for this section", which is the reading §3.4 makes explicit by
 * writing "✅ Own" and "✅ Asgn" in the one row where the answer differs from
 * the section's view row. Recording which cells were checkmarks is what lets
 * `RbacMatrixDataTest` check that interpretation on every one of them, rather
 * than stating it once in a comment and hoping.
 */
final readonly class Grant
{
    private function __construct(
        private Scope $scope,
        private bool $wasCheckmark,
    ) {}

    /** The document wrote a scope: All, Team, Own, Out or Asgn. */
    public static function scoped(Scope $scope): self
    {
        return new self($scope, false);
    }

    /** The document wrote a bare ✅; $resolved is the scope it resolves to. */
    public static function checkmark(Scope $resolved): self
    {
        return new self($resolved, true);
    }

    public function scope(): Scope
    {
        return $this->scope;
    }

    public function wasCheckmark(): bool
    {
        return $this->wasCheckmark;
    }
}
