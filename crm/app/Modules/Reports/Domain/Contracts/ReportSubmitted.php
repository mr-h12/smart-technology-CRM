<?php

declare(strict_types=1);

namespace App\Modules\Reports\Domain\Contracts;

/**
 * `D-132`: a report version was submitted and the submit committed. Dispatched
 * only after the transaction, so a refused or rolled-back submit announces
 * nothing (spec §4.1). Ids only, as `QuotationSent` carries: a listener that
 * needs more reads it through `SubmittedReportsInterface`.
 */
final readonly class ReportSubmitted
{
    public function __construct(
        public string $reportId,
        public int $version,
    ) {}
}
