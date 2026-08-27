<?php

declare(strict_types=1);

namespace App\Modules\Admin\Application\Money;

use App\Modules\Admin\Domain\Contracts\CurrencyRepositoryInterface;
use App\Modules\Admin\Domain\Money\Currency;
use App\Modules\Admin\Domain\Money\CurrencyCode;
use App\Modules\Admin\Domain\Money\RoundingRule;
use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * §5.3's editable half: the rounding unit, and `D-65`'s switch beside it.
 *
 * **A partial change keeps what it did not name.** `PATCH` with only
 * `rounding_enabled` must leave the unit alone — `D-65` flips a switch beside
 * the unit rather than erasing it, and `RoundingRule` models the two together
 * precisely so that turning rounding back on does not have to invent a unit.
 * The current rule is therefore read first and the request applied on top of it.
 *
 * **One transaction** (`DB-11`) around the write and its audit entry. **The
 * event is recorded although §3.12 rule 4 does not list it:** `AUD-01` asks for
 * a comprehensive audit, and this is the number every total in that currency is
 * rounded by. `§5.3` adds the reason it matters — the change applies to new
 * quotations only, so "when did this change" is the question reconciling an old
 * one will ask.
 */
final readonly class UpdateCurrencyRounding
{
    public function __construct(
        private ConnectionInterface $connection,
        private CurrencyRepositoryInterface $currencies,
        private AuditRecorderInterface $audit,
    ) {}

    public function handle(CurrencyCode $code, ?string $unit, ?bool $enabled): Currency
    {
        return $this->connection->transaction(function () use ($code, $unit, $enabled): Currency {
            $current = $this->currencies->find($code);

            if (! $current instanceof Currency) {
                throw new CurrencyNotOffered($code);
            }

            $nextUnit = $unit ?? $current->rounding()->unit();
            $nextEnabled = $enabled ?? $current->rounding()->isEnabled();

            // Decimal::positive() runs inside both constructors, so a unit that
            // slipped past the boundary is refused here too — the database's
            // CHECK is the third of the three.
            $rounding = $nextEnabled ? RoundingRule::to($nextUnit) : RoundingRule::disabled($nextUnit);

            $written = $this->currencies->replaceRounding($code, $rounding);

            $this->audit->record(
                AuditEvent::of('CURRENCY_ROUNDING_UPDATED'),
                'currencies',
                $written['id'],
                ['unit' => $written['previous']->unit(), 'enabled' => $written['previous']->isEnabled()],
                ['unit' => $rounding->unit(), 'enabled' => $rounding->isEnabled()],
            );

            $updated = $this->currencies->find($code);

            if (! $updated instanceof Currency) {
                throw new CurrencyNotOffered($code);
            }

            return $updated;
        });
    }
}
