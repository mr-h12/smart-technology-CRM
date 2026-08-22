<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Http\Middleware\AddRequestId;
use App\Modules\Audit\Domain\AuditContext;
use App\Modules\Audit\Domain\AuditEntry;
use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditContextResolverInterface;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Audit\Infrastructure\RequestAuditContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Point 6.4 — the writer.
 *
 * Points 6.1 to 6.3 built a table, made it append-only and kept it supplied
 * with months. Nothing had ever written a row. `AUD-01` wants create, update,
 * delete, approve and transfer recorded; `AUD-02` fixes what a record contains;
 * `Coding Standards §10` adds the request and correlation IDs.
 *
 * ── Two invariants that are not obvious ────────────────────────────────────
 *
 * **No floats in a payload.** `DB-07` forbids floating point anywhere near
 * money, and an audit row is where a price change is preserved *permanently*.
 * `json_encode(0.1 + 0.2)` writes `0.30000000000000004` into a row nobody can
 * ever correct, so the entry refuses a float outright rather than storing an
 * approximation of what happened.
 *
 * **The correlation ID is validated here as well as at the boundary.** `D-69`
 * bounds it, `AddRequestId` enforces that on the way in, and this enforces it
 * again on the way to a permanent row — because a caller can build a context by
 * hand, and `AUD-03` means a forged log line cannot be edited out afterwards.
 */
final class AuditRecorderTest extends TestCase
{
    use RefreshDatabase;

    // ─────────────────────────────────────────────────────── the full record

    public function test_it_writes_every_column_aud_02_names(): void
    {
        $entity = (string) Uuid::uuid7();

        $this->withContext(new AuditContext(
            actorId: $actor = (string) Uuid::uuid7(),
            ip: '10.0.0.7',
            userAgent: 'Mozilla/5.0 (X11; Linux x86_64)',
            requestId: $request = (string) Uuid::uuid7(),
            correlationId: 'trace.abc-123',
        ));

        $this->recorder()->record(
            AuditEvent::marginChanged(),
            'quotation',
            $entity,
            ['margin' => '10.000'],
            ['margin' => '12.500'],
        );

        $row = self::onlyRow();

        self::assertSame($actor, $row->user_id);
        self::assertSame('MARGIN_CHANGED', $row->event);
        self::assertSame('quotation', $row->entity_type);
        self::assertSame($entity, $row->entity_id);
        self::assertSame('10.0.0.7', $row->ip_address);
        self::assertSame('Mozilla/5.0 (X11; Linux x86_64)', $row->user_agent);
        self::assertSame($request, $row->request_id);
        self::assertSame('trace.abc-123', $row->correlation_id);
    }

    public function test_the_values_round_trip_as_jsonb(): void
    {
        $this->withContext(AuditContext::system());

        $this->recorder()->record(
            AuditEvent::taxChanged(),
            'quotation',
            (string) Uuid::uuid7(),
            ['tax_percent' => '15.000', 'tax_exempt' => false],
            ['tax_percent' => '0.000', 'tax_exempt' => true],
        );

        $row = self::onlyRow();

        // assertEquals, not assertSame: `jsonb` is a parsed document, not the
        // text that was sent, and PostgreSQL stores its keys in its own order —
        // shortest first, then bytewise. Measured here: `tax_exempt` came back
        // ahead of `tax_percent`. Anything that depends on key order in an
        // audit payload is depending on something the column does not promise.
        self::assertEquals(
            ['tax_percent' => '15.000', 'tax_exempt' => false],
            json_decode((string) $row->old_values, true),
        );
        self::assertEquals(
            ['tax_percent' => '0.000', 'tax_exempt' => true],
            json_decode((string) $row->new_values, true),
        );
    }

    public function test_jsonb_is_stored_as_json_not_as_a_quoted_string(): void
    {
        $this->withContext(AuditContext::system());

        $this->recorder()->record(
            AuditEvent::taxChanged(),
            'quotation',
            (string) Uuid::uuid7(),
            ['tax_percent' => '15.000'],
            null,
        );

        // jsonb_typeof is the difference between a queryable document and a
        // string that merely looks like one. Double-encoding produces the
        // latter, and every later query for old_values->>'tax_percent' returns
        // nothing at all rather than failing loudly.
        /** @var object{kind: string, value: string}|null $probe */
        $probe = DB::selectOne(
            "select jsonb_typeof(old_values) as kind,
                    old_values->>'tax_percent' as value
             from audit_log limit 1",
        );

        self::assertNotNull($probe);
        self::assertSame('object', $probe->kind);
        self::assertSame('15.000', $probe->value);
    }

    public function test_a_create_has_no_old_values_and_a_delete_has_no_new_ones(): void
    {
        $this->withContext(AuditContext::system());

        $this->recorder()->record(AuditEvent::of('CUSTOMER_CREATED'), 'customer',
            (string) Uuid::uuid7(), null, ['name' => 'Acme']);

        $row = self::onlyRow();

        self::assertNull($row->old_values);
        self::assertNotNull($row->new_values);
    }

    // ─────────────────────────────────────────────────────────── DB-07

    #[DataProvider('floatPayloads')]
    public function test_a_float_in_a_payload_is_refused(mixed $value): void
    {
        $this->withContext(AuditContext::system());

        $this->expectException(InvalidArgumentException::class);

        $this->recorder()->record(
            AuditEvent::marginChanged(),
            'quotation',
            (string) Uuid::uuid7(),
            null,
            ['margin' => $value],
        );
    }

    /** @return array<string, array{mixed}> */
    public static function floatPayloads(): array
    {
        return [
            // The value that makes the case: json_encode writes
            // 0.30000000000000004 and AUD-03 means nobody can correct it.
            'a computed float' => [0.1 + 0.2],
            'a literal float' => [12.5],
            'a float nested in a list' => [[['unit_price' => 3.3]]],
        ];
    }

    public function test_a_decimal_string_is_accepted(): void
    {
        // The other half: refusing floats is only useful if the documented way
        // of carrying money — a decimal string (DB-07, D-68) — goes through.
        $this->withContext(AuditContext::system());

        $this->recorder()->record(AuditEvent::marginChanged(), 'quotation',
            (string) Uuid::uuid7(), null, ['margin' => '12.500']);

        self::assertSame(1, DB::table('audit_log')->count());
    }

    // ──────────────────────────────────────────────────── time and truncation

    public function test_the_timestamp_is_utc(): void
    {
        $this->withContext(AuditContext::system());

        $before = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->recorder()->record(AuditEvent::loginAs(), 'user', (string) Uuid::uuid7());
        $after = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        /** @var object{at: string}|null $row */
        $row = DB::selectOne(
            "select to_char(created_at at time zone 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS') as at
             from audit_log limit 1",
        );

        self::assertNotNull($row);
        self::assertGreaterThanOrEqual($before->format('Y-m-d\TH:i:s'), $row->at);
        self::assertLessThanOrEqual($after->format('Y-m-d\TH:i:s'), $row->at);
    }

    public function test_the_logged_timestamp_is_utc_whatever_the_caller_passed(): void
    {
        // Measured: the database test above CANNOT detect a non-UTC timestamp.
        // created_at is TIMESTAMPTZ, so '18:27+03:00' and '15:27+00:00' are the
        // same instant and read back identically — shifting the entry's zone
        // changed nothing and every test stayed green. DB-08 is satisfied by
        // the column type there, not by anything this code does.
        //
        // Where it is not satisfied for free is the log line (AUD-05), which is
        // a formatted string. A machine reading a stream where some records say
        // +00:00 and others +03:00 has to normalise every one of them.
        $entry = new AuditEntry(
            AuditEvent::loginAs(),
            'user',
            (string) Uuid::uuid7(),
            null,
            null,
            AuditContext::system(),
            new \DateTimeImmutable('2026-08-22 18:00:00', new \DateTimeZone('Asia/Riyadh')),
        );

        self::assertSame('2026-08-22T15:00:00+00:00', $entry->toLogContext()['recorded_at']);
    }

    public function test_an_oversized_user_agent_is_truncated_rather_than_failing_the_write(): void
    {
        // The column is VARCHAR(512) and a User-Agent is attacker-influenced.
        // Letting the insert fail would take the business transaction with it
        // (AUD-01 with DB-11), which is a denial of service through a header.
        $this->withContext(new AuditContext(
            actorId: null,
            ip: null,
            userAgent: str_repeat('U', 4096),
            requestId: null,
            correlationId: null,
        ));

        $this->recorder()->record(AuditEvent::loginAs(), 'user', (string) Uuid::uuid7());

        self::assertSame(512, strlen((string) self::onlyRow()->user_agent));
    }

    // ───────────────────────────────────────────── the request context, D-69

    public function test_the_middleware_supplies_the_request_and_correlation_ids(): void
    {
        // The real middleware on a real request, not a hand-made context: D-69
        // validation lives there, and a resolver reading a different attribute
        // name would pass any test that set the attribute itself.
        $request = Request::create('/api/v1/ping', 'GET', server: [
            'REMOTE_ADDR' => '192.0.2.44',
            'HTTP_USER_AGENT' => 'Mozilla/5.0',
            'HTTP_X_CORRELATION_ID' => 'upstream.trace-9',
        ]);

        (new AddRequestId)->handle($request, fn (): Response => new Response);
        $this->app->instance('request', $request);

        $context = $this->app->make(AuditContextResolverInterface::class)->current();

        self::assertSame('upstream.trace-9', $context->correlationId);
        self::assertSame('192.0.2.44', $context->ip);
        self::assertSame('Mozilla/5.0', $context->userAgent);
        self::assertNotNull($context->requestId);
    }

    public function test_a_correlation_id_the_caller_forged_is_replaced_not_propagated(): void
    {
        // D-69: an unusable value is ignored and a server-generated one used
        // instead, and the request still succeeds. A newline is the case that
        // matters — it forges a second line in a log that AUD-03 makes
        // permanent.
        $request = Request::create('/api/v1/ping', 'GET', server: [
            'HTTP_X_CORRELATION_ID' => "abc\nlevel=critical",
        ]);

        (new AddRequestId)->handle($request, fn (): Response => new Response);
        $this->app->instance('request', $request);

        $context = $this->app->make(AuditContextResolverInterface::class)->current();

        self::assertNotSame("abc\nlevel=critical", $context->correlationId);
        self::assertNotNull($context->correlationId);
        self::assertSame($context->requestId, $context->correlationId);
    }

    public function test_the_domain_refuses_a_correlation_id_outside_the_d69_shape(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AuditContext(null, null, null, null, "abc\nforged");
    }

    public function test_the_domain_and_the_middleware_agree_on_the_d69_shape(): void
    {
        // Two copies of one rule is how they drift. This is the check that
        // notices, since neither file can reference the other without pointing
        // a module's domain at HTTP middleware or the reverse.
        self::assertSame(
            AddRequestId::CORRELATION_PATTERN,
            AuditContext::CORRELATION_PATTERN,
        );

        // The same duplication, for the same reason, in the resolver: naming
        // AddRequestId's constants from inside a module would make every
        // reference an uncovered deptrac dependency.
        self::assertSame(AddRequestId::ATTRIBUTE, RequestAuditContext::REQUEST_ATTRIBUTE);
        self::assertSame(AddRequestId::CORRELATION, RequestAuditContext::CORRELATION_ATTRIBUTE);
    }

    public function test_outside_a_request_the_context_is_system_rather_than_empty(): void
    {
        // A scheduled job has no IP, no agent and no inbound request. J-15 is
        // already such a caller. The recorder must still write the row.
        $context = $this->app->make(AuditContextResolverInterface::class)->current();

        self::assertNull($context->ip);
        self::assertNull($context->userAgent);
    }

    // ────────────────────────────────────────────────────── AUD-05 and DB-11

    public function test_it_emits_a_structured_log_line_carrying_the_correlation_id(): void
    {
        Log::shouldReceive('channel')->with('audit')->andReturnSelf();
        Log::shouldReceive('info')->once()->withArgs(
            function (string $message, array $context): bool {
                return $message === 'audit'
                    && $context['event'] === 'LOGIN_AS'
                    && $context['correlation_id'] === 'trace.abc-123';
            },
        );

        $this->withContext(new AuditContext(null, null, null, null, 'trace.abc-123'));

        $this->recorder()->record(AuditEvent::loginAs(), 'user', (string) Uuid::uuid7());
    }

    public function test_a_rolled_back_transaction_leaves_no_audit_row(): void
    {
        // AUD-01 with DB-11: the audit write belongs to the business
        // transaction. If the operation did not happen, neither did its record.
        $this->withContext(AuditContext::system());

        try {
            DB::transaction(function (): void {
                $this->recorder()->record(AuditEvent::loginAs(), 'user', (string) Uuid::uuid7());

                throw new \RuntimeException('the business operation failed');
            });
        } catch (\RuntimeException) {
            // expected
        }

        self::assertSame(0, DB::table('audit_log')->count());
    }

    // ────────────────────────────────────────────── the §3.12 vocabulary

    /**
     * @param  callable(): AuditEvent  $factory
     */
    #[DataProvider('mandatoryEvents')]
    public function test_every_mandatory_critical_event_has_a_name(callable $factory, string $value): void
    {
        // §3.12 rule 4 lists nine events that must always be audited. Named
        // constructors rather than free strings, because a misspelled event is
        // a row that no audit query will ever find.
        //
        // A closure rather than a method name: AuditEvent::{$method}() is
        // `mixed` to static analysis, and Coding Standards §5 forbids buying
        // level 10 back with an ignore.
        self::assertSame($value, $factory()->value);
    }

    /** @return array<string, array{callable(): AuditEvent, string}> */
    public static function mandatoryEvents(): array
    {
        return [
            'tax edits' => [AuditEvent::taxChanged(...), 'TAX_CHANGED'],
            'margin edits' => [AuditEvent::marginChanged(...), 'MARGIN_CHANGED'],
            'customer reassignment' => [AuditEvent::customerReassigned(...), 'CUSTOMER_REASSIGNED'],
            'role change' => [AuditEvent::roleChanged(...), 'ROLE_CHANGED'],
            'Login As' => [AuditEvent::loginAs(...), 'LOGIN_AS'],
            'restore from archive' => [AuditEvent::archiveRestored(...), 'ARCHIVE_RESTORED'],
            'FX rate change' => [AuditEvent::fxRateChanged(...), 'FX_RATE_CHANGED'],
            'account deactivation' => [AuditEvent::accountDeactivated(...), 'ACCOUNT_DEACTIVATED'],
            'self-approval' => [AuditEvent::selfApproval(...), 'SELF_APPROVAL'],
        ];
    }

    #[DataProvider('malformedEvents')]
    public function test_a_malformed_event_name_is_refused(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        AuditEvent::of($name);
    }

    /** @return array<string, array{string}> */
    public static function malformedEvents(): array
    {
        return [
            'empty' => [''],
            'lower case' => ['login_as'],
            'a space' => ['LOGIN AS'],
            'a newline' => ["LOGIN_AS\nFORGED"],
            // The column is VARCHAR(64); refusing here beats a database error
            // inside someone else's transaction.
            'longer than the column' => [str_repeat('A', 65)],
        ];
    }

    // ──────────────────────────────────────────────────────────── helpers

    private function recorder(): AuditRecorderInterface
    {
        return $this->app->make(AuditRecorderInterface::class);
    }

    private function withContext(AuditContext $context): void
    {
        $this->app->instance(AuditContextResolverInterface::class, new class($context) implements AuditContextResolverInterface
        {
            public function __construct(private readonly AuditContext $context) {}

            public function current(): AuditContext
            {
                return $this->context;
            }
        });
    }

    /**
     * @return object{
     *     user_id: ?string, event: string, entity_type: string, entity_id: string,
     *     old_values: ?string, new_values: ?string, ip_address: ?string,
     *     user_agent: ?string, request_id: ?string, correlation_id: ?string
     * }
     */
    private static function onlyRow(): object
    {
        /**
         * @var object{
         *     user_id: ?string, event: string, entity_type: string, entity_id: string,
         *     old_values: ?string, new_values: ?string, ip_address: ?string,
         *     user_agent: ?string, request_id: ?string, correlation_id: ?string
         * }|null $row
         */
        $row = DB::table('audit_log')->first();

        self::assertNotNull($row, 'No audit row was written.');
        self::assertSame(1, DB::table('audit_log')->count());

        return $row;
    }
}
