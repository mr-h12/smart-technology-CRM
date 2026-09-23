<?php

declare(strict_types=1);

namespace Tests\Feature\Quotations;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Quotations\Domain\Writing\QuotationWriteRefused;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use stdClass;
use Tests\TestCase;

/**
 * Module 10 · 1.4 — `PATCH /quotations/{id}/respond` for `partial` and
 * `counter` (`OpenAPI §7.2`, §6.3, `D-08`): `sent → partial|counter` and the
 * new `draft` version written in the same transaction and named in the
 * answer (`new_version`). `counter` needs a reason (`rejection_reason_required`);
 * a field that belongs to another response is refused (owner, 2026-09-23, A);
 * `accepted` came with 1.6 (B). The deal does not move (Q2).
 *
 * Module 10 · 1.5 — `rejected`, from `sent` and `expired` (Q8): reason
 * required, no copy; the deal goes `lost` with the reason only when none of
 * its quotations is live afterwards (Q12), counted under `FOR UPDATE`, and is
 * left alone when it has no `lost` edge (rule b). The answer says which with
 * `deal_lost` (owner, 2026-09-23).
 */
final class QuotationRespondEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/quotations';

    private const PASSWORD = 'Passw0rd123';

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, string> one login per role per test — the login limit counts every call */
    private array $tokens = [];

    private string $customerId;

    private string $lineId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->currency('EGP');
        $this->customerId = $this->customer();
        $this->lineId = $this->supplierLine();
    }

    // ────────────────────────────────────────────────────────── the transition

    public function test_partial_moves_sent_to_partial_and_writes_the_draft_copy(): void
    {
        $deal = $this->deal(null);
        [$id, $etag] = $this->sent($deal);

        $response = $this->respond($id, $etag, RoleName::Manager, ['response' => 'partial'])
            ->assertStatus(200)
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.status', 'partial')
            ->assertJsonPath('data.new_version.version', 2);

        self::assertNotSame($etag, $response->json('data.etag'));

        $copyId = $response->json('data.new_version.id');
        self::assertIsString($copyId);
        $copy = DB::table('quotations')->where('id', $copyId)->first();
        self::assertInstanceOf(stdClass::class, $copy);
        self::assertSame('draft', $copy->status);
        self::assertSame($id, $copy->parent_id);
        self::assertSame($copy->code, $response->json('data.new_version.code'));
        self::assertSame(1, DB::table('quotation_items')->where('quotation_id', $copyId)->count());

        self::assertSame('partial', $this->statusOf('quotations', $id));
        self::assertNull(DB::table('quotations')->where('id', $id)->value('rejection_reason'));
        self::assertSame('quotation_sent', $this->statusOf('deals', $deal));
        self::assertSame(0, DB::table('audit_log')->where('event', 'DEAL_STATUS_CHANGED')->count());
    }

    public function test_counter_stores_its_reason_on_the_parent_only(): void
    {
        $deal = $this->deal(null);
        [$id, $etag] = $this->sent($deal);

        $copyId = $this->respond($id, $etag, RoleName::Manager, ['response' => 'counter', 'reason' => 'Asks 10% off'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'counter')
            ->json('data.new_version.id');
        self::assertIsString($copyId);

        self::assertSame('Asks 10% off', DB::table('quotations')->where('id', $id)->value('rejection_reason'));
        self::assertNull(DB::table('quotations')->where('id', $copyId)->value('rejection_reason'));
        self::assertSame('draft', $this->statusOf('quotations', $copyId));
        self::assertSame('quotation_sent', $this->statusOf('deals', $deal));
    }

    /** @return array<string, array{array<string, string>}> */
    public static function withoutAReason(): array
    {
        return [
            'counter, missing' => [['response' => 'counter']],
            'counter, blank' => [['response' => 'counter', 'reason' => '   ']],
            'rejected, missing' => [['response' => 'rejected']],
            'rejected, blank' => [['response' => 'rejected', 'reason' => '   ']],
        ];
    }

    /**
     * §6.3 "rejection and counter reasons are mandatory before the status change is accepted" — nothing is written.
     *
     * @param  array<string, string>  $body
     */
    #[DataProvider('withoutAReason')]
    public function test_a_response_without_its_reason_is_refused_and_writes_nothing(array $body): void
    {
        [$id, $etag] = $this->sent($this->deal(null));

        $this->respond($id, $etag, RoleName::Manager, $body)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.details.0.field', 'reason')
            ->assertJsonPath('error.details.0.code', 'rejection_reason_required')
            ->assertJsonPath('error.details.0.message', __('quotations.errors.rejection_reason_required'));

        $this->assertNothingWritten($id);
    }

    /** Q7: the copy stops carrying the earlier return's marks — they belong to the row that was returned. */
    public function test_the_copy_does_not_carry_the_earlier_return(): void
    {
        [$id, $etag] = $this->sent($this->deal(null));
        DB::table('quotations')->where('id', $id)->update(['returned_at' => now(), 'return_note' => 'Fix the margin']);

        $copyId = $this->respond($id, $etag, RoleName::Manager, ['response' => 'partial'])
            ->assertStatus(200)
            ->json('data.new_version.id');
        self::assertIsString($copyId);

        $copy = DB::table('quotations')->where('id', $copyId)->first();
        self::assertInstanceOf(stdClass::class, $copy);
        self::assertNull($copy->returned_at);
        self::assertNull($copy->return_note);
    }

    /** One transaction: a copy the database refuses (`DB-03`, one v2 per parent) leaves the parent `sent`. */
    public function test_a_refused_copy_rolls_the_status_back(): void
    {
        [$id, $etag] = $this->sent($this->deal(null));

        // An existing v2: open it by hand from `partial`, then put the parent back.
        DB::table('quotations')->where('id', $id)->update(['status' => 'partial']);
        $this->postJson(self::ENDPOINT.'/'.$id.'/new-version', [], ['Idempotency-Key' => Uuid::uuid4()->toString()] + $this->bearerFor(RoleName::Manager))
            ->assertStatus(201);
        DB::table('quotations')->where('id', $id)->update(['status' => 'sent']);

        $this->respond($id, $etag, RoleName::Manager, ['response' => 'partial'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'state_transition_invalid')
            ->assertJsonPath('error.details.0.code', QuotationWriteRefused::VERSION_EXISTS);

        self::assertSame('sent', $this->statusOf('quotations', $id));
        self::assertSame(0, DB::table('audit_log')->where('event', 'QUOTATION_PARTIAL')->count());
    }

    public function test_responding_to_a_quotation_that_is_not_sent_is_a_transition_error(): void
    {
        [$id, $etag] = $this->sent($this->deal(null));
        DB::table('quotations')->where('id', $id)->update(['status' => 'approved']);

        $this->respond($id, $etag, RoleName::Manager, ['response' => 'partial'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'state_transition_invalid');

        self::assertSame(1, DB::table('quotations')->count());
    }

    public function test_the_two_responses_are_written_to_the_audit_log(): void
    {
        [$partialId, $partialEtag] = $this->sent($this->deal(null));
        [$counterId, $counterEtag] = $this->sent($this->deal(null));

        $copyId = $this->respond($partialId, $partialEtag, RoleName::Manager, ['response' => 'partial'])->assertStatus(200)->json('data.new_version.id');
        $this->respond($counterId, $counterEtag, RoleName::Manager, ['response' => 'counter', 'reason' => 'Too high'])->assertStatus(200);

        [$old, $new] = $this->audited('QUOTATION_PARTIAL', $partialId);
        self::assertSame('sent', $old['status'] ?? null);
        self::assertSame('partial', $new['status'] ?? null);

        [$old, $new] = $this->audited('QUOTATION_COUNTERED', $counterId);
        self::assertSame('sent', $old['status'] ?? null);
        self::assertSame('counter', $new['status'] ?? null);
        self::assertSame('Too high', $new['rejection_reason'] ?? null);

        self::assertIsString($copyId);
        [, $new] = $this->audited('QUOTATION_VERSION_CREATED', $copyId);
        self::assertSame($partialId, $new['parent_id'] ?? null);
        self::assertSame(2, DB::table('audit_log')->where('event', 'QUOTATION_VERSION_CREATED')->count());
    }

    // ─────────────────────────────────────────────────── rejected (1.5)

    /** Q2 + Q12: the sole live quotation rejected — the deal goes `lost`, the rejection reason its lost reason. */
    public function test_rejecting_the_last_live_quotation_makes_the_deal_lost(): void
    {
        $deal = $this->deal(null);
        [$id, $etag] = $this->sent($deal);

        $this->respond($id, $etag, RoleName::Manager, ['response' => 'rejected', 'reason' => 'Too expensive'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.deal_lost', true)
            ->assertJsonMissingPath('data.new_version');

        self::assertSame('Too expensive', DB::table('quotations')->where('id', $id)->value('rejection_reason'));
        self::assertSame('lost', $this->statusOf('deals', $deal));
        self::assertSame('Too expensive', DB::table('deals')->where('id', $deal)->value('lost_reason'));
        self::assertSame(0, DB::table('quotations')->where('parent_id', $id)->count());
        self::assertSame(1, DB::table('audit_log')->where('event', 'DEAL_STATUS_CHANGED')->where('entity_id', $deal)->count());

        [$old, $new] = $this->audited('QUOTATION_REJECTED', $id);
        self::assertSame('sent', $old['status'] ?? null);
        self::assertSame('rejected', $new['status'] ?? null);
        self::assertSame('Too expensive', $new['rejection_reason'] ?? null);
    }

    /** The point's proof (Q12): two live quotations on one deal — the first rejection leaves it, the second makes it `lost`. */
    public function test_the_deal_is_lost_only_when_its_last_live_quotation_is_rejected(): void
    {
        $deal = $this->deal(null);
        [$first, $firstEtag] = $this->sent($deal);
        [$second, $secondEtag] = $this->sent($deal);

        $this->respond($first, $firstEtag, RoleName::Manager, ['response' => 'rejected', 'reason' => 'One'])
            ->assertStatus(200)
            ->assertJsonPath('data.deal_lost', false);
        self::assertSame('quotation_sent', $this->statusOf('deals', $deal));

        $this->respond($second, $secondEtag, RoleName::Manager, ['response' => 'rejected', 'reason' => 'Two'])
            ->assertStatus(200)
            ->assertJsonPath('data.deal_lost', true);
        self::assertSame('lost', $this->statusOf('deals', $deal));
        self::assertSame('Two', DB::table('deals')->where('id', $deal)->value('lost_reason'));
    }

    /** *Live* is the `active` bucket — a draft counts; a deleted draft and a finished quotation do not. */
    public function test_what_counts_as_live(): void
    {
        $deal = $this->deal(null);
        [$id, $etag] = $this->sent($deal);
        [$draft] = $this->sent($deal);
        DB::table('quotations')->where('id', $draft)->update(['status' => 'draft']);

        $this->respond($id, $etag, RoleName::Manager, ['response' => 'rejected', 'reason' => 'x'])
            ->assertStatus(200)
            ->assertJsonPath('data.deal_lost', false);

        $deal = $this->deal(null);
        [$id, $etag] = $this->sent($deal);
        [$deleted] = $this->sent($deal);
        DB::table('quotations')->where('id', $deleted)->update(['status' => 'draft', 'deleted_at' => now()]);
        [$finished] = $this->sent($deal);
        DB::table('quotations')->where('id', $finished)->update(['status' => 'counter', 'rejection_reason' => 'x']);

        $this->respond($id, $etag, RoleName::Manager, ['response' => 'rejected', 'reason' => 'x'])
            ->assertStatus(200)
            ->assertJsonPath('data.deal_lost', true);
    }

    /** Q8's new edge — §10.5 "records Rejected" after the offer expired. */
    public function test_an_expired_quotation_can_be_rejected(): void
    {
        $deal = $this->deal(null);
        [$id, $etag] = $this->sent($deal);
        DB::table('quotations')->where('id', $id)->update(['status' => 'expired']);

        $this->respond($id, $etag, RoleName::Manager, ['response' => 'rejected', 'reason' => 'no response'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.deal_lost', true);

        self::assertSame('lost', $this->statusOf('deals', $deal));
    }

    /** @return array<string, array{string}> */
    public static function withoutALostEdge(): array
    {
        return ['lost' => ['lost'], 'won' => ['won']];
    }

    /** `D-90` rule b: the quotation is rejected, the deal is untouched, and the answer says so. */
    #[DataProvider('withoutALostEdge')]
    public function test_a_deal_with_no_lost_edge_is_left_alone(string $status): void
    {
        $deal = $this->deal(null, $status);
        [$id, $etag] = $this->sent($deal);

        $this->respond($id, $etag, RoleName::Manager, ['response' => 'rejected', 'reason' => 'x'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.deal_lost', false);

        self::assertSame($status, $this->statusOf('deals', $deal));
        self::assertSame(0, DB::table('audit_log')->where('event', 'DEAL_STATUS_CHANGED')->count());
    }

    /** Only a rejection speaks of the deal: `partial` and `counter` carry no `deal_lost`. */
    public function test_a_partial_answer_says_nothing_about_the_deal(): void
    {
        [$id, $etag] = $this->sent($this->deal(null));

        $this->respond($id, $etag, RoleName::Manager, ['response' => 'partial'])
            ->assertStatus(200)
            ->assertJsonMissingPath('data.deal_lost');
    }

    /**
     * Q12's race guard: the deal's quotations are locked `FOR UPDATE` **before**
     * the status write, so two last rejections serialise instead of each seeing
     * the other still live (or deadlocking, if the lock came after the write).
     */
    public function test_the_deals_quotations_are_locked_before_the_rejection_is_written(): void
    {
        [$id, $etag] = $this->sent($this->deal(null));
        $bearer = $this->bearerFor(RoleName::Manager);

        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = strtolower($query->sql);
        });

        $this->patchJson(self::ENDPOINT.'/'.$id.'/respond', ['response' => 'rejected', 'reason' => 'x'], [...$bearer, 'If-Match' => $etag])
            ->assertStatus(200);

        $lock = $this->firstIndex($statements, fn (string $sql): bool => str_contains($sql, 'from "quotations"') && str_contains($sql, 'for update'));
        $write = $this->firstIndex($statements, fn (string $sql): bool => str_starts_with($sql, 'update "quotations"'));
        self::assertNotNull($lock, 'no FOR UPDATE on quotations');
        self::assertNotNull($write, 'no quotation update');
        self::assertLessThan($write, $lock);
    }

    /** §5.1 + 1.2's audit: another owner's quotation is out of reach, and its deal does not go `lost`. */
    #[DataProvider('ownScoped')]
    public function test_an_own_scoped_role_cannot_reject_another_owners_quotation(RoleName $role): void
    {
        $deal = $this->deal($this->userWith(RoleName::Manager)->id);
        [$id, $etag] = $this->sent($deal);

        $this->respond($id, $etag, $role, ['response' => 'rejected', 'reason' => 'x'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found');

        $this->assertNothingWritten($id);
        self::assertSame('quotation_sent', $this->statusOf('deals', $deal));
    }

    // ─────────────────────────────────────────────────── accepted (1.6)

    private const PO_BODY = ['response' => 'accepted', 'customer_po_reference' => '4500123987', 'po_date' => '2026-09-23'];

    /** §4.6 / `D-53` / Q5: the purchase order in the acceptance's transaction; `D-81`: every line consumed. */
    public function test_accepted_writes_the_purchase_order_and_consumes_each_line(): void
    {
        $deal = $this->deal(null);
        [$id, $etag] = $this->sent($deal, ['2', '3']);

        $response = $this->respond($id, $etag, RoleName::Manager, self::PO_BODY)
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.purchase_order.customer_po_reference', '4500123987')
            ->assertJsonPath('data.purchase_order.po_date', '2026-09-23')
            ->assertJsonMissingPath('data.new_version')
            ->assertJsonMissingPath('data.deal_lost');

        $poNumber = $response->json('data.purchase_order.po_number');
        self::assertIsString($poNumber);
        self::assertMatchesRegularExpression('/^PO-\d{4}-\d{4}$/', $poNumber);

        $order = DB::table('purchase_orders')->where('quotation_id', $id)->first();
        self::assertInstanceOf(stdClass::class, $order);
        self::assertSame($response->json('data.purchase_order.id'), $order->id);
        self::assertSame($poNumber, $order->po_number);

        self::assertSame('5.0000', $this->consumed());
        self::assertSame('accepted', $this->statusOf('quotations', $id));
        self::assertSame('quotation_sent', $this->statusOf('deals', $deal));
        self::assertSame(0, DB::table('audit_log')->where('event', 'DEAL_STATUS_CHANGED')->count());
    }

    /** `D-81`: "the old/new `consumed_quantity` rides the transition's audit entry", keyed by the line consumed with. */
    public function test_acceptance_is_audited_with_each_lines_balance_and_the_order(): void
    {
        [$id, $etag] = $this->sent($this->deal(null), ['2', '3']);
        $lines = DB::table('quotation_items')->where('quotation_id', $id)->orderBy('line_no')->pluck('id')->all();
        self::assertCount(2, $lines);
        [$first, $second] = $lines;
        self::assertIsString($first);
        self::assertIsString($second);

        $orderId = $this->respond($id, $etag, RoleName::Manager, self::PO_BODY)->assertStatus(200)->json('data.purchase_order.id');
        self::assertIsString($orderId);

        [$old, $new] = $this->audited('QUOTATION_ACCEPTED', $id);
        self::assertSame('sent', $old['status'] ?? null);
        self::assertSame('accepted', $new['status'] ?? null);
        // `jsonb` keeps its own key order, so each line is read by its key.
        $before = $old['consumed_quantity'] ?? null;
        $after = $new['consumed_quantity'] ?? null;
        self::assertIsArray($before);
        self::assertIsArray($after);
        self::assertCount(2, $before);
        self::assertSame(['0.0000', '2.0000'], [$before[$first] ?? null, $before[$second] ?? null]);
        self::assertSame(['2.0000', '5.0000'], [$after[$first] ?? null, $after[$second] ?? null]);

        [, $order] = $this->audited('PURCHASE_ORDER_CREATED', $orderId, 'purchase_order');
        self::assertSame($id, $order['quotation_id'] ?? null);
        self::assertSame('4500123987', $order['customer_po_reference'] ?? null);
        self::assertSame('2026-09-23', $order['po_date'] ?? null);
    }

    /** `D-81`: "No ceiling … exceeding is allowed with a warning" — acceptance is not blocked. */
    public function test_acceptance_may_go_past_the_available_balance(): void
    {
        [$id, $etag] = $this->sent($this->deal(null));
        DB::table('supplier_quotation_items')->where('id', $this->lineId)->update(['consumed_quantity' => '4']);

        $this->respond($id, $etag, RoleName::Manager, self::PO_BODY)->assertStatus(200);

        self::assertSame('6.0000', $this->consumed());
    }

    public function test_accepting_a_quotation_that_is_not_sent_is_a_transition_error(): void
    {
        [$id, $etag] = $this->sent($this->deal(null));
        DB::table('quotations')->where('id', $id)->update(['status' => 'expired']);

        $this->respond($id, $etag, RoleName::Manager, self::PO_BODY)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'state_transition_invalid');

        self::assertSame(0, DB::table('purchase_orders')->count());
        self::assertSame('0.0000', $this->consumed());
    }

    /** A refused acceptance writes no order and moves no supplier balance. */
    #[DataProvider('ownScoped')]
    public function test_an_own_scoped_role_cannot_accept_another_owners_quotation(RoleName $role): void
    {
        [$id, $etag] = $this->sent($this->deal($this->userWith(RoleName::Manager)->id));

        $this->respond($id, $etag, $role, self::PO_BODY)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found');

        $this->assertNothingWritten($id);
        self::assertSame(0, DB::table('purchase_orders')->count());
        self::assertSame('0.0000', $this->consumed());
    }

    // ──────────────────────────────────────────── the body's boundary (A, B)

    /**
     * Owner, 2026-09-23: a field that belongs to another response is refused, not dropped (A);
     * `accepted` needs both purchase-order fields (1.6, owner B: a reference with a character, a date).
     *
     * @return array<string, array{array<string, string>, string}>
     */
    public static function refusedBodies(): array
    {
        return [
            'reason on partial' => [['response' => 'partial', 'reason' => 'x'], 'reason'],
            'po reference on partial' => [['response' => 'partial', 'customer_po_reference' => 'PO-7'], 'customer_po_reference'],
            'po date on counter' => [['response' => 'counter', 'reason' => 'x', 'po_date' => '2026-09-23'], 'po_date'],
            'po reference on rejected' => [['response' => 'rejected', 'reason' => 'x', 'customer_po_reference' => 'PO-7'], 'customer_po_reference'],
            'accepted without a reference' => [['response' => 'accepted', 'po_date' => '2026-09-23'], 'customer_po_reference'],
            'accepted with a blank reference' => [['response' => 'accepted', 'customer_po_reference' => '   ', 'po_date' => '2026-09-23'], 'customer_po_reference'],
            'accepted without a date' => [['response' => 'accepted', 'customer_po_reference' => '4500123987'], 'po_date'],
            'accepted with a malformed date' => [['response' => 'accepted', 'customer_po_reference' => '4500123987', 'po_date' => '23/09/2026'], 'po_date'],
            'reason on accepted' => [['response' => 'accepted', 'reason' => 'x', 'customer_po_reference' => '4500123987', 'po_date' => '2026-09-23'], 'reason'],
            'no response' => [[], 'response'],
        ];
    }

    /** @param array<string, string> $body */
    #[DataProvider('refusedBodies')]
    public function test_a_body_the_response_does_not_take_is_refused(array $body, string $field): void
    {
        [$id, $etag] = $this->sent($this->deal(null));

        $this->respond($id, $etag, RoleName::Manager, $body)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.details.0.field', $field);

        $this->assertNothingWritten($id);
    }

    // ────────────────────────────────────────────────────── optimistic locking

    public function test_a_missing_if_match_is_a_400(): void
    {
        [$id] = $this->sent($this->deal(null));

        $this->patchJson(self::ENDPOINT.'/'.$id.'/respond', ['response' => 'partial'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(400)
            ->assertJsonPath('error.details.0.code', QuotationWriteRefused::IF_MATCH_REQUIRED);
    }

    public function test_a_stale_token_is_a_409_and_writes_nothing(): void
    {
        [$id] = $this->sent($this->deal(null));

        $this->respond($id, 'quotation:'.$id.':0', RoleName::Manager, ['response' => 'partial'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'concurrency_conflict');

        $this->assertNothingWritten($id);
    }

    // ─────────────────────────────────────────────────────────── authorisation

    public function test_an_unauthenticated_caller_cannot_respond(): void
    {
        $this->patchJson(self::ENDPOINT.'/'.Uuid::uuid4()->toString().'/respond', ['response' => 'partial'])->assertStatus(401);
    }

    /** @return array<string, array{RoleName}> */
    public static function ownScoped(): array
    {
        return ['outdoor sales' => [RoleName::OutdoorSales], 'indoor sales' => [RoleName::IndoorSales]];
    }

    #[DataProvider('ownScoped')]
    public function test_an_own_scoped_role_responds_on_its_own_deals_quotation(RoleName $role): void
    {
        [$id, $etag] = $this->sent($this->deal($this->userWith($role)->id));

        $this->respond($id, $etag, $role, ['response' => 'partial'])->assertStatus(200)->assertJsonPath('data.status', 'partial');
    }

    /** §5.1: out of reach is a 404 — and nothing of the other owner's moves (`deal_id` only from the authorised quotation). */
    #[DataProvider('ownScoped')]
    public function test_an_own_scoped_role_cannot_respond_on_another_owners_quotation(RoleName $role): void
    {
        $deal = $this->deal($this->userWith(RoleName::Manager)->id);
        [$id, $etag] = $this->sent($deal);

        $this->respond($id, $etag, $role, ['response' => 'counter', 'reason' => 'x'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found');

        $this->assertNothingWritten($id);
        self::assertSame('quotation_sent', $this->statusOf('deals', $deal));
    }

    /** `team` is granted and unbacked — fails closed (`D-a`). */
    public function test_the_team_leader_sees_nothing_until_teams_exist(): void
    {
        [$id, $etag] = $this->sent($this->deal($this->userWith(RoleName::TeamLeader)->id));

        $this->respond($id, $etag, RoleName::TeamLeader, ['response' => 'partial'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found');

        $this->assertNothingWritten($id);
    }

    /**
     * §3.5's `record customer response` has no cell for Procurement, the CEO or the Outdoor Supervisor.
     *
     * @return array<string, array{RoleName}>
     */
    public static function nonResponders(): array
    {
        return [
            'procurement' => [RoleName::Procurement],
            'ceo' => [RoleName::Ceo],
            'outdoor supervisor' => [RoleName::OutdoorSupervisor],
        ];
    }

    #[DataProvider('nonResponders')]
    public function test_a_role_without_the_grant_cannot_respond(RoleName $role): void
    {
        [$id, $etag] = $this->sent($this->deal(null));

        $this->respond($id, $etag, $role, ['response' => 'partial'])->assertStatus(403);

        $this->assertNothingWritten($id);
    }

    public function test_an_unknown_id_is_a_404(): void
    {
        $id = Uuid::uuid4()->toString();

        $this->respond($id, 'quotation:'.$id.':1', RoleName::Manager, ['response' => 'partial'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found');
    }

    // ──────────────────────────────────────────────────────────────── fixtures

    /**
     * @param  array<string, string>  $body
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function respond(string $id, string $ifMatch, RoleName $role, array $body): TestResponse
    {
        return $this->patchJson(self::ENDPOINT.'/'.$id.'/respond', $body, [...$this->bearerFor($role), 'If-Match' => $ifMatch]);
    }

    /** The parent is still `sent` with no reason, no copy exists, and no response was audited. */
    private function assertNothingWritten(string $id): void
    {
        self::assertSame('sent', $this->statusOf('quotations', $id));
        self::assertNull(DB::table('quotations')->where('id', $id)->value('rejection_reason'));
        self::assertSame(0, DB::table('quotations')->where('parent_id', $id)->count());
        self::assertSame(0, DB::table('audit_log')->whereIn('event', ['QUOTATION_PARTIAL', 'QUOTATION_COUNTERED', 'QUOTATION_REJECTED', 'QUOTATION_ACCEPTED', 'QUOTATION_VERSION_CREATED', 'PURCHASE_ORDER_CREATED', 'DEAL_STATUS_CHANGED'])->count());
    }

    /** @return array{array<mixed>, array<mixed>} old and new values */
    private function audited(string $event, string $entityId, string $entityType = 'quotation'): array
    {
        $row = DB::table('audit_log')->where('entity_type', $entityType)->where('entity_id', $entityId)->where('event', $event)->first();
        self::assertInstanceOf(stdClass::class, $row);
        self::assertIsString($row->new_values);
        $old = is_string($row->old_values) ? json_decode($row->old_values, true) : [];
        $new = json_decode($row->new_values, true);
        self::assertIsArray($old);
        self::assertIsArray($new);

        return [$old, $new];
    }

    /**
     * @param  list<string>  $statements
     * @param  callable(string): bool  $matches
     */
    private function firstIndex(array $statements, callable $matches): ?int
    {
        foreach ($statements as $index => $sql) {
            if ($matches($sql)) {
                return $index;
            }
        }

        return null;
    }

    private function consumed(): string
    {
        $consumed = DB::table('supplier_quotation_items')->where('id', $this->lineId)->value('consumed_quantity');
        self::assertIsString($consumed);

        return $consumed;
    }

    private function statusOf(string $table, string $id): string
    {
        $status = DB::table($table)->where('id', $id)->value('status');
        self::assertIsString($status);

        return $status;
    }

    /**
     * A draft through Point 3.4's endpoint, then set `sent` in place — the
     * etag is `version_token`, which a status column write leaves alone.
     *
     * @param  list<string>  $quantities  one line each, all on the one supplier line
     * @return array{string, string} id and etag
     */
    private function sent(string $dealId, array $quantities = ['2']): array
    {
        $lines = [];
        foreach ($quantities as $quantity) {
            $lines[] = ['supplier_quotation_item_id' => $this->lineId, 'quantity' => $quantity];
        }

        $id = $this->postJson(self::ENDPOINT, [
            'deal_id' => $dealId,
            'customer_id' => $this->customerId,
            'currency' => 'EGP',
            'default_margin' => '20',
            'discount_percent' => '0',
            'lines' => $lines,
        ], ['Idempotency-Key' => Uuid::uuid4()->toString()] + $this->bearerFor(RoleName::Manager))->assertStatus(201)->json('data.id');
        self::assertIsString($id);

        DB::table('quotations')->where('id', $id)->update(['status' => 'sent', 'sent_at' => now()]);

        $etag = $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))->assertStatus(200)->json('data.etag');
        self::assertIsString($etag);

        return [$id, $etag];
    }

    private function currency(string $code): void
    {
        DB::table('currencies')->insert([
            'id' => Uuid::uuid4()->toString(),
            'code' => $code,
            'rounding_unit' => '1',
            'rounding_enabled' => false,
            'is_base' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function customer(): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('customers')->insert([
            'id' => $id,
            'name' => 'Nile Trading',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** By default a deal at `quotation_sent` — where a `sent` quotation leaves it (1.3). */
    private function deal(?string $ownerId, string $status = 'quotation_sent'): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('deals')->insert([
            'id' => $id,
            'code' => 'DL-'.now()->format('Y').'-'.substr($id, 0, 4),
            'customer_id' => $this->customerId,
            'owner_id' => $ownerId,
            'status' => $status,
            'lost_reason' => $status === 'lost' ? 'Lost earlier' : null,
            'last_activity_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** Seeds §4.1's chain in EGP and returns the `supplier_quotation_items` id a line points at. */
    private function supplierLine(): string
    {
        $supplierId = Uuid::uuid4()->toString();
        $catalogItemId = Uuid::uuid4()->toString();
        $offerId = Uuid::uuid4()->toString();
        $lineId = Uuid::uuid4()->toString();
        $egp = DB::table('currencies')->where('code', 'EGP')->value('id');

        DB::table('suppliers')->insert([
            'id' => $supplierId, 'name' => 'Alpha Supplies',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('catalog_items')->insert([
            'id' => $catalogItemId, 'kind' => 'product', 'name' => 'Widget',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('supplier_quotations')->insert([
            'id' => $offerId,
            'code' => 'SQ-'.now()->format('Y').'-'.substr($lineId, 0, 4),
            'supplier_id' => $supplierId,
            'total_price' => '1',
            'currency_id' => $egp,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('supplier_quotation_items')->insert([
            'id' => $lineId,
            'supplier_quotation_id' => $offerId,
            'catalog_item_id' => $catalogItemId,
            'unit_price' => '10',
            'quantity' => '5',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $lineId;
    }

    private function userWith(RoleName $role): User
    {
        if (isset($this->users[$role->value])) {
            return $this->users[$role->value];
        }

        $row = Role::query()->where('slug', $role->value)->firstOrFail();

        $user = new User;
        $user->fill([
            'name' => 'Test '.$role->label(),
            'email' => str_replace('_', '.', $role->value).'@example.test',
            'password' => self::PASSWORD,
            'role_id' => $row->id,
            'is_active' => true,
            'is_hidden' => $role->isHidden(),
        ]);
        $user->save();

        return $this->users[$role->value] = $user;
    }

    /** @return array<string, string> */
    private function bearerFor(RoleName $role): array
    {
        if (isset($this->tokens[$role->value])) {
            return ['Authorization' => 'Bearer '.$this->tokens[$role->value]];
        }

        $user = $this->userWith($role);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertStatus(201)->json('data.token');

        self::assertIsString($token);
        $this->tokens[$role->value] = $token;

        return ['Authorization' => 'Bearer '.$token];
    }
}
