<?php

declare(strict_types=1);

namespace App\Modules\Admin\Application\Settings;

use App\Modules\Admin\Domain\Contracts\SettingsRepositoryInterface;
use App\Modules\Admin\Domain\Settings\SystemSetting;
use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * §13 screen 4's save button.
 *
 * **One transaction** (`DB-11`): the write and its audit entry are one fact. A
 * settings screen that saved four fields and recorded three would leave the
 * company's own configuration with no account of who changed it.
 *
 * **`SETTINGS_UPDATED` is not one of §3.12 rule 4's nine**, and it is recorded
 * anyway: `AUD-01` asks for a comprehensive audit, and `AuditEnforcementTest`
 * refuses a module writer that neither records nor carries a reason. The
 * company name printed on every quotation is not a field that should change
 * without a trace.
 *
 * The old and new values travel together in one entry per field, because "what
 * did it used to say" is the question an audit of a settings screen is asked.
 */
final readonly class UpdateSettings
{
    public function __construct(
        private ConnectionInterface $connection,
        private SettingsRepositoryInterface $settings,
        private AuditRecorderInterface $audit,
    ) {}

    /**
     * @param  array<string, string>  $values  keyed by `SystemSetting::value`, already validated
     * @return array<string, string|null>
     */
    public function handle(array $values): array
    {
        return $this->connection->transaction(function () use ($values): array {
            foreach ($values as $key => $value) {
                $setting = SystemSetting::from($key);

                $written = $this->settings->put($setting, $value);

                // The key travels in the values, not in `entity_id`:
                // `audit_log.entity_id` is a UUID column and a settings key is
                // a name. `entity_type` is the table, as everywhere else.
                $this->audit->record(
                    AuditEvent::of('SETTINGS_UPDATED'),
                    'settings',
                    $written['id'],
                    ['key' => $setting->value, 'value' => $written['previous']],
                    ['key' => $setting->value, 'value' => $value],
                );
            }

            return $this->settings->all();
        });
    }
}
