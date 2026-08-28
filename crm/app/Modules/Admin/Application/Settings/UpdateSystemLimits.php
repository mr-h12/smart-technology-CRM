<?php

declare(strict_types=1);

namespace App\Modules\Admin\Application\Settings;

use App\Modules\Admin\Domain\Contracts\SettingsCacheInterface;
use App\Modules\Admin\Domain\Contracts\SystemLimitRepositoryInterface;
use App\Modules\Admin\Domain\Settings\SystemLimit;
use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * §13 screen 6's save button.
 *
 * **One transaction** (`DB-11`) and an audit entry per field, for the reasons
 * {@see UpdateSettings} gives. `SYSTEM_LIMITS_UPDATED` is not one of §3.12 rule
 * 4's nine and is recorded anyway (`AUD-01`): these numbers decide when a deal
 * is flagged stale, when a daily report counts as missed, and — through
 * `D-75` — how long a locked-out account stays locked. *"When did this change"*
 * is the first question asked about any of them.
 */
final readonly class UpdateSystemLimits
{
    public function __construct(
        private ConnectionInterface $connection,
        private SystemLimitRepositoryInterface $limits,
        private AuditRecorderInterface $audit,
        private SettingsCacheInterface $cache,
    ) {}

    /**
     * @param  array<string, string>  $values  keyed by `SystemLimit::value`, already validated
     * @return array<string, array{value: string|null, unit: string|null, value_type: string}>
     */
    public function handle(array $values): array
    {
        $this->connection->transaction(function () use ($values): void {
            foreach ($values as $key => $value) {
                $limit = SystemLimit::from($key);

                $written = $this->limits->put($limit, $value);

                // The key travels in the values and not in `entity_id`:
                // `audit_log.entity_id` is a UUID column and a limit's key is a
                // name. Measured in Point 3.1 — SQLSTATE[22P02].
                $this->audit->record(
                    AuditEvent::of('SYSTEM_LIMITS_UPDATED'),
                    'system_limits',
                    $written['id'],
                    ['key' => $limit->value, 'value' => $written['previous']],
                    ['key' => $limit->value, 'value' => $value],
                );
            }
        });

        // ⚠️ **After the transaction, never inside it** (Point 4.2) — a flush
        // inside leaves a window in which a concurrent reader repopulates the
        // cache from rows that have not committed, and a rollback would then
        // leave values that never existed. The read-back is outside for the
        // same reason: what the response reports is what committed.
        //
        // This one carries `D-75`: what `AuthenticateUser` reads is the entry
        // being forgotten here, so the owner's change takes effect on the next
        // login rather than on the next deployment.
        $this->cache->forgetLimits();

        return $this->limits->all();
    }
}
