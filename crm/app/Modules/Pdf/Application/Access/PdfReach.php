<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Application\Access;

/**
 * How far one caller's §3.5 grant reaches one quotation. Three answers,
 * because the endpoints tell the first two apart: a grant whose scope has no
 * mechanism is a named `403` (Q2, `D-a`); a quotation out of reach, or absent,
 * is a `404` that does not confirm it exists (`OpenAPI §5.1`).
 */
enum PdfReach
{
    /** No grant, or only `Asgn` / `Team`: the scope selects no quotation at all. */
    case Unbacked;

    /** Absent, soft-deleted, or another owner's under `own`. */
    case Outside;

    case Reached;
}
