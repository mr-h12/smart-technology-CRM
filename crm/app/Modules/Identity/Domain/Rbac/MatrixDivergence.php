<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Rbac;

/**
 * How far the live grant set has drifted from §3.
 *
 * Two lists of `"<role slug> | <resource>.<action>.<scope>"`, in the same
 * vocabulary §3 and the seeder already use, so a line of output can be pasted
 * straight into a search of `PermissionMatrix`.
 *
 * **Drift is not by itself a defect.** `SEC-07` puts the matrix in the database
 * and §3.12 rule 5 makes changing it a configuration change with an audit
 * trail, so a deliberate grant is a supported operation. What this class
 * carries is the difference; whether a given line is intended is a judgement
 * the reader makes, not one the code makes for them.
 */
final readonly class MatrixDivergence
{
    /**
     * @param  list<string>  $extra  held live, not declared by §3
     * @param  list<string>  $missing  declared by §3, not held live
     */
    public function __construct(
        public array $extra,
        public array $missing,
    ) {}

    public function isAligned(): bool
    {
        return $this->extra === [] && $this->missing === [];
    }
}
