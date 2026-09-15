<?php

declare(strict_types=1);

namespace Tests\Feature\Quotations;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * `D-11` — "No automatic escalation: the quotation waits; a red badge is
 * shown" — asserted where an escalation would have to live (Module 8 · 3.1).
 * A quotation past the SLA stays `pending` and stays on `/approvals`
 * (`ApprovalsView` draws `sla_exceeded` in words); nothing in the scheduler
 * moves, reassigns or notifies about it. §18.2 lists no such mail either.
 */
final class NoApprovalEscalationTest extends TestCase
{
    public function test_that_no_scheduled_entry_names_approvals(): void
    {
        $events = $this->app->make(Schedule::class)->events();
        $names = array_map(static fn ($event): string => $event->command.' '.($event->description ?? ''), $events);

        self::assertNotEmpty($names, 'The schedule is empty — this test would assert nothing.');

        foreach ($names as $name) {
            self::assertDoesNotMatchRegularExpression('/approv|escalat/i', $name);
        }
    }
}
