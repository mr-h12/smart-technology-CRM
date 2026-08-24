<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Rbac;

use RuntimeException;

/**
 * `SEC-09` and §3.12 rule 1 — the refusal the API returns when a caller lacks
 * the permission.
 *
 * It names what was refused so the log line and the `details[]` entry can say
 * so; it is rendered into `OpenAPI §5`'s envelope in `bootstrap/app.php`, which
 * is outside `app/Modules`. The module states *what* was refused and never
 * builds an HTTP response to say it.
 *
 * One exception for every denial — a missing grant, a scope that does not
 * reach, a role with nothing at all. `OpenAPI §5.1` gives 403 exactly one code,
 * and the caller learns which action they lack, never who else may perform it.
 */
final class AuthorizationRefused extends RuntimeException
{
    /** `OpenAPI §5.1`'s only 403 code. */
    public const ERROR_CODE = 'permission_denied';

    /** §5.1: "specific stable codes inside `details`". */
    public const DETAIL_CODE = 'unauthorized_action';

    private function __construct(
        public readonly string $resource,
        public readonly string $action,
        public readonly ?Scope $requiredScope,
    ) {
        parent::__construct(sprintf(
            'Permission denied: %s.%s%s',
            $resource,
            $action,
            $requiredScope instanceof Scope ? '.'.$requiredScope->value : '',
        ));
    }

    public static function of(string $resource, string $action, ?Scope $requiredScope = null): self
    {
        return new self($resource, $action, $requiredScope);
    }

    /** `resource.action` as §3.2 writes it, without the scope. */
    public function ability(): string
    {
        return $this->resource.'.'.$this->action;
    }
}
