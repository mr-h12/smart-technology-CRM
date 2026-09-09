<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Modules\Audit\Domain\AuditContext;
use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditContextResolverInterface;
use App\Modules\Audit\Domain\Contracts\AuditEntryReaderInterface;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Audit\Domain\Reading\AuditRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Module 5 Point 5.1 — the read half of `audit_log`.
 *
 * `AUD-03` refuses `UPDATE`, `DELETE` and `TRUNCATE` on this table and always
 * has; it has never refused a `SELECT`. Module 5 Point 1.1 declined to build a
 * `deal_status_history` table on the grounds that this table already stores
 * §4.4's "old status · new status · who · when" verbatim — a reading that owes
 * exactly this port, because §3.4 seeds `deal.view_timeline` to seven roles and
 * nothing has ever been able to check it.
 *
 * ── What is proved here, and what is not ───────────────────────────────────
 *
 * The port takes an entity type and an id, and performs **no** authorization —
 * deliberately, because only the owning module knows what reaching one of its
 * rows means (`SEC-08`). So there is no scope case below and there should not
 * be one; Point 5.2's endpoint test is where a caller who may not see the deal
 * is refused. What is proved here is that the rows come back, in the documented
 * order, with the payloads intact and the pagination arithmetic correct.
 */
final class AuditEntryReaderTest extends TestCase
{
    use RefreshDatabase;

    // ─────────────────────────────────────────────────── the entity's own rows

    public function test_it_returns_only_the_rows_of_the_entity_asked_for(): void
    {
        $this->withContext(AuditContext::system());

        $wanted = (string) Uuid::uuid7();
        $other = (string) Uuid::uuid7();

        $this->record('DEAL_STATUS_CHANGED', 'deal', $wanted, ['status' => 'lead'], ['status' => 'contacted']);
        $this->record('DEAL_STATUS_CHANGED', 'deal', $other, ['status' => 'lead'], ['status' => 'contacted']);
        // Same id, different entity type — the pair a `where` on id alone
        // would wrongly merge, and a real possibility given every module here
        // keys on its own UUIDs.
        $this->record('CUSTOMER_CREATED', 'customer', $wanted, null, ['name' => 'Acme']);

        $page = $this->reader()->forEntity('deal', $wanted, 1, 25);

        self::assertCount(1, $page->items);
        self::assertSame(1, $page->total);
        self::assertSame($wanted, $page->items[0]->entityId);
        self::assertSame('deal', $page->items[0]->entityType);
    }

    public function test_an_entity_with_no_rows_is_an_empty_page_not_a_failure(): void
    {
        $page = $this->reader()->forEntity('deal', (string) Uuid::uuid7(), 1, 25);

        self::assertSame([], $page->items);
        self::assertSame(0, $page->total);
        // An empty result is one (empty) page, not zero — CustomerPage's rule,
        // transcribed into AuditRecordPage.
        self::assertSame(1, $page->totalPages());
        self::assertFalse($page->hasNextPage());
        self::assertFalse($page->hasPreviousPage());
    }

    // ──────────────────────────────────────────────────────────────── the order

    public function test_the_newest_record_comes_first(): void
    {
        $this->withContext(AuditContext::system());
        $deal = (string) Uuid::uuid7();

        $this->record('DEAL_CREATED', 'deal', $deal, null, ['status' => 'lead']);
        $this->record('DEAL_STATUS_CHANGED', 'deal', $deal, ['status' => 'lead'], ['status' => 'contacted']);
        $this->record('DEAL_STATUS_CHANGED', 'deal', $deal, ['status' => 'contacted'], ['status' => 'won']);

        $page = $this->reader()->forEntity('deal', $deal, 1, 25);

        // Newest first is the port's promise, not the caller's option: the
        // fact anybody wants is what just happened.
        self::assertSame(
            ['won', 'contacted', 'lead'],
            array_map(static fn (AuditRecord $r): ?string => $r->newValue('status'), $page->items),
        );
    }

    public function test_created_at_orders_the_page_even_when_the_id_disagrees(): void
    {
        // ⚠️ Written because a probe found the claim untested. Reversing
        // `orderByDesc('created_at')` alone reddened NOTHING: `id` is a UUIDv7
        // (`D-61`) and therefore already time-ordered, so in a test whose rows
        // are written microseconds apart the tie-break silently carried the
        // whole promise. Here the two keys are made to disagree at insert time
        // — the older row is given the *newer* id — so only `created_at` can
        // produce the documented order.
        //
        // Back-dating with an UPDATE was tried first and is impossible by
        // design: `AUD-03`'s trigger answered SQLSTATE `AUD03`, "audit_log is
        // append-only: UPDATE is refused". The rows are therefore built with
        // both columns chosen up front.
        $deal = (string) Uuid::uuid7();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $firstId = (string) Uuid::uuid7();
        // Generated second, so it sorts *after* $firstId as a UUIDv7 — and is
        // handed the *older* timestamp.
        $secondId = (string) Uuid::uuid7();

        self::assertGreaterThan($firstId, $secondId, 'uuid7 did not increase; the probe below proves nothing.');

        $this->insertRow($secondId, $deal, 'older', $now->modify('-2 days'));
        $this->insertRow($firstId, $deal, 'newer', $now->modify('-1 hour'));

        $page = $this->reader()->forEntity('deal', $deal, 1, 25);

        // Ordered by id the page would open with 'older'; ordered by
        // created_at — the documented key — it opens with 'newer'.
        self::assertSame(
            ['newer', 'older'],
            array_map(static fn (AuditRecord $r): ?string => $r->newValue('status'), $page->items),
        );
    }

    // ───────────────────────────────────────────────────────── §4.4's four fields

    public function test_a_status_change_carries_old_status_new_status_who_and_when(): void
    {
        $actor = (string) Uuid::uuid7();
        $this->withContext(new AuditContext(
            actorId: $actor,
            ip: '10.0.0.7',
            userAgent: 'Mozilla/5.0',
            requestId: (string) Uuid::uuid7(),
            correlationId: null,
        ));

        $deal = (string) Uuid::uuid7();
        $before = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $this->record('DEAL_STATUS_CHANGED', 'deal', $deal, ['status' => 'lead'], ['status' => 'contacted']);

        $record = $this->reader()->forEntity('deal', $deal, 1, 25)->items[0];

        // The criterion, field by field: old status, new status, who, when.
        self::assertSame('lead', $record->oldValue('status'));
        self::assertSame('contacted', $record->newValue('status'));
        self::assertSame($actor, $record->actorId);
        self::assertSame('UTC', $record->recordedAt->getTimezone()->getName());
        self::assertGreaterThanOrEqual(
            $before->modify('-2 seconds')->getTimestamp(),
            $record->recordedAt->getTimestamp(),
        );
        self::assertSame('DEAL_STATUS_CHANGED', $record->event);
    }

    public function test_the_impersonator_survives_the_round_trip(): void
    {
        // SEC-10: a record naming only the actor would read identically
        // whether or not the action was taken through somebody else's account.
        $this->withContext(new AuditContext(
            actorId: $admin = (string) Uuid::uuid7(),
            ip: null,
            userAgent: null,
            requestId: null,
            correlationId: null,
            impersonatedUserId: $victim = (string) Uuid::uuid7(),
        ));

        $deal = (string) Uuid::uuid7();
        $this->record('DEAL_APPROVED', 'deal', $deal, null, ['approval_status' => 'approved']);

        $record = $this->reader()->forEntity('deal', $deal, 1, 25)->items[0];

        self::assertSame($admin, $record->actorId);
        self::assertSame($victim, $record->impersonatedUserId);
    }

    public function test_a_null_payload_reads_back_as_null_not_as_an_empty_array(): void
    {
        $this->withContext(AuditContext::system());
        $deal = (string) Uuid::uuid7();

        $this->record('DEAL_CREATED', 'deal', $deal, null, ['status' => 'lead']);

        $record = $this->reader()->forEntity('deal', $deal, 1, 25)->items[0];

        // A creation has no "before". An empty array would claim it had one
        // that happened to be empty, which is a different fact.
        self::assertNull($record->oldValues);
        self::assertSame(['status' => 'lead'], $record->newValues);
        self::assertNull($record->oldValue('status'));
    }

    public function test_a_non_string_payload_value_answers_null_rather_than_a_type_error(): void
    {
        $this->withContext(AuditContext::system());
        $deal = (string) Uuid::uuid7();

        $this->record('DEAL_UPDATED', 'deal', $deal, null, ['status' => ['nested'], 'title' => 'Ok']);

        $record = $this->reader()->forEntity('deal', $deal, 1, 25)->items[0];

        self::assertNull($record->newValue('status'));
        self::assertSame('Ok', $record->newValue('title'));
        // The raw payload is still there — the accessor narrows, it does not discard.
        self::assertNotNull($record->newValues);
        self::assertArrayHasKey('status', $record->newValues);
        self::assertSame(['nested'], $record->newValues['status']);
    }

    // ─────────────────────────────────────────────────────────────── pagination

    public function test_the_page_carries_openapi_4_2_s_six_numbers(): void
    {
        $this->withContext(AuditContext::system());
        $deal = (string) Uuid::uuid7();

        for ($i = 0; $i < 5; $i++) {
            $this->record('DEAL_UPDATED', 'deal', $deal, null, ['title' => "t{$i}"]);
        }

        $page = $this->reader()->forEntity('deal', $deal, 2, 2);

        self::assertCount(2, $page->items);
        // `total` is the size of the answer, not of the page.
        self::assertSame(5, $page->total);
        self::assertSame(2, $page->page);
        self::assertSame(2, $page->perPage);
        self::assertSame(3, $page->totalPages());
        self::assertTrue($page->hasNextPage());
        self::assertTrue($page->hasPreviousPage());
    }

    public function test_a_page_past_the_end_is_empty_but_still_reports_the_total(): void
    {
        $this->withContext(AuditContext::system());
        $deal = (string) Uuid::uuid7();
        $this->record('DEAL_CREATED', 'deal', $deal, null, ['status' => 'lead']);

        $page = $this->reader()->forEntity('deal', $deal, 9, 25);

        self::assertSame([], $page->items);
        self::assertSame(1, $page->total);
        self::assertTrue($page->hasPreviousPage());
        self::assertFalse($page->hasNextPage());
    }

    public function test_pages_do_not_overlap_or_drop_a_row(): void
    {
        $this->withContext(AuditContext::system());
        $deal = (string) Uuid::uuid7();

        for ($i = 0; $i < 7; $i++) {
            $this->record('DEAL_UPDATED', 'deal', $deal, null, ['title' => "t{$i}"]);
        }

        $ids = [];

        foreach ([1, 2, 3] as $page) {
            foreach ($this->reader()->forEntity('deal', $deal, $page, 3)->items as $record) {
                $ids[] = $record->id;
            }
        }

        // Rows written inside one transaction can share `created_at` to the
        // microsecond; without the UUIDv7 tie-break the pages reshuffle and a
        // row appears twice while another is never seen.
        self::assertCount(7, $ids);
        self::assertCount(7, array_unique($ids));
    }

    // ────────────────────────────────────────────────────────────────── helpers

    private function reader(): AuditEntryReaderInterface
    {
        return $this->app->make(AuditEntryReaderInterface::class);
    }

    /**
     * @param  array<array-key, mixed>|null  $old
     * @param  array<array-key, mixed>|null  $new
     */
    private function record(string $event, string $type, string $id, ?array $old, ?array $new): void
    {
        $this->app->make(AuditRecorderInterface::class)
            ->record(AuditEvent::of($event), $type, $id, $old, $new);
    }

    /** One row with both sort keys chosen by the caller — see the ordering test. */
    private function insertRow(string $id, string $dealId, string $status, \DateTimeImmutable $at): void
    {
        DB::table('audit_log')->insert([
            'id' => $id,
            'user_id' => null,
            'impersonated_user_id' => null,
            'event' => 'DEAL_STATUS_CHANGED',
            'entity_type' => 'deal',
            'entity_id' => $dealId,
            'old_values' => null,
            'new_values' => json_encode(['status' => $status], JSON_THROW_ON_ERROR),
            'ip_address' => null,
            'user_agent' => null,
            'request_id' => null,
            'correlation_id' => null,
            'created_at' => $at->format(DATE_RFC3339_EXTENDED),
        ]);
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
}
