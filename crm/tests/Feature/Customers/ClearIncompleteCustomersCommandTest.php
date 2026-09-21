<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

/**
 * `D-87` ruling 3 (F-11 · 1.4): the one-off correction. A row the importer
 * flagged that is complete today is cleared with an audit row; a row still
 * missing a core field is left alone; a second run changes nothing.
 *
 * The actor is the system (`AuditContext::system()`, the J-15 shape): nobody
 * pressed a button on these rows, so `updated_by` and the audit's `user_id`
 * are null rather than a person's id.
 */
final class ClearIncompleteCustomersCommandTest extends TestCase
{
    use RefreshDatabase;

    private const COMMAND = 'customers:clear-incomplete';

    public function test_that_a_complete_flagged_row_is_cleared_and_audited_by_the_system(): void
    {
        $id = $this->flagged(['name' => 'Gap Trading', 'sector' => 'Medical', 'region' => 'Cairo', 'contact_person' => 'Mona', 'phone' => '0100']);

        $this->run_()->expectsOutputToContain('1 of 1')->assertExitCode(0);

        $this->assertDatabaseHas('customers', ['id' => $id, 'is_incomplete' => false, 'updated_by' => null]);

        $row = DB::table('audit_log')->where('event', 'CUSTOMER_UPDATED')->where('entity_id', $id)->first();
        self::assertNotNull($row);
        self::assertNull($row->user_id);
        self::assertIsString($row->old_values);
        self::assertIsString($row->new_values);
        self::assertSame(['is_incomplete' => true], json_decode($row->old_values, true));
        self::assertSame(['is_incomplete' => false], json_decode($row->new_values, true));
    }

    public function test_that_an_incomplete_row_is_untouched(): void
    {
        $id = $this->flagged(['name' => 'Gap Trading', 'sector' => 'Medical', 'contact_person' => 'Mona', 'phone' => '0100']);

        // Listed as flagged, not cleared — the count must say so, not count the ids it visited.
        $this->run_()->expectsOutputToContain('0 of 1')->assertExitCode(0);

        $this->assertDatabaseHas('customers', ['id' => $id, 'is_incomplete' => true]);
        self::assertSame(0, DB::table('audit_log')->where('entity_id', $id)->count());
    }

    public function test_that_a_second_run_writes_no_audit_row(): void
    {
        $id = $this->flagged(['name' => 'Gap Trading', 'sector' => 'Medical', 'region' => 'Cairo', 'contact_person' => 'Mona', 'phone' => '0100']);

        $this->run_()->assertExitCode(0);
        $this->run_()->expectsOutputToContain('0 of 0')->assertExitCode(0);

        self::assertSame(1, DB::table('audit_log')->where('entity_id', $id)->count());
    }

    /** @param array<string, mixed> $columns */
    private function flagged(array $columns): string
    {
        $id = (string) Str::uuid7();

        DB::table('customers')->insert($columns + [
            'id' => $id,
            'is_incomplete' => true,
            'customer_status' => 'prospect',
            'is_archived' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** `artisan()` is typed `PendingCommand|int`; narrowed once, as `EnsureAuditPartitionsTest` does. */
    private function run_(): PendingCommand
    {
        $pending = $this->artisan(self::COMMAND);

        self::assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }
}
