<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain\Money;

use DateTimeImmutable;

/**
 * One `fx_rates` row: the rate, the moment it starts applying, and when it was
 * entered.
 *
 * {@see ExchangeRate} is the price itself and has no identity — it is a
 * multiplier. This is that price **as a recorded fact**, which is what `§13`
 * screen 5's *rate history* is a list of and what `§3.12` rule 4's mandatory
 * audit entry points at.
 *
 * `effectiveFrom` and `recordedAt` are two different questions and both are
 * asked in practice: `D-09` fixes the rate onto a quotation by *when it applied*,
 * while an auditor reconciling a total asks *when somebody typed it*. A rate
 * entered today for last week's business has different answers.
 */
final readonly class RecordedRate
{
    public function __construct(
        public string $id,
        public ExchangeRate $rate,
        public DateTimeImmutable $effectiveFrom,
        public DateTimeImmutable $recordedAt,
    ) {}
}
