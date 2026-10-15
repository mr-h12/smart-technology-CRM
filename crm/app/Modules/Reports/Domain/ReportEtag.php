<?php

declare(strict_types=1);

namespace App\Modules\Reports\Domain;

/**
 * `DB-12` / `API-12` for a report: `report:<id>:<version_token>`, on
 * `QuotationEtag`'s terms — sent in the body as `etag`, read back from `If-Match`.
 */
final class ReportEtag
{
    private const SHAPE = '/^report:([0-9a-f-]{36}):(\d+)$/';

    public static function of(Report $report): string
    {
        return 'report:'.$report->id.':'.$report->versionToken;
    }

    /** A missing, malformed or other-report `If-Match` is the same 400. */
    public static function tokenFrom(?string $ifMatch, string $reportId): int
    {
        if ($ifMatch === null || preg_match(self::SHAPE, trim($ifMatch), $m) !== 1 || $m[1] !== $reportId) {
            throw ReportWriteRefused::ifMatchRequired();
        }

        return (int) $m[2];
    }
}
