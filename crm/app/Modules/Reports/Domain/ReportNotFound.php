<?php

declare(strict_types=1);

namespace App\Modules\Reports\Domain;

use RuntimeException;

/** A report that does not exist, or is not the caller's — the same 404 (`SEC-07`). */
final class ReportNotFound extends RuntimeException
{
    public static function of(string $reportId): self
    {
        return new self('Report '.$reportId.' is not visible to this caller.');
    }
}
