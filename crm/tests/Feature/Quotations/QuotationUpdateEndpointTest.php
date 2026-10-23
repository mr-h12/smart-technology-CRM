<?php

declare(strict_types=1);

namespace Tests\Feature\Quotations;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Quotations\Domain\Writing\QuotationWriteRefused;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use stdClass;
use Tests\TestCase;

/**
 * `PATCH /api/v1/quotations/{id}` — Module 7 Point 3.6.
 *
 * `DB-12`/`API-12`: `If-Match` carries §9.2's etag, a stale one is `409
 * concurrency_conflict` (never 412), and the write advances `version_token`
 * so the token that just succeeded is stale on the next try. §3.5's `edit`
 * row is Draft-only; `edit margin` and `edit tax` are separate grants. Every
 * edit is re-priced by §5 and written to the audit log (§6.4). QS.2a: a legacy
 * Draft converts to the line pricing at its next save, to the same total (`D-165`).
 */
final class QuotationUpdateEndpointTest extends TestCase
{
    use QuotationLineFixtures;
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/quotations';

    private const PASSWORD = 'Passw0rd123';

    /** One ready term with no name of its own, and one the employee added (`D-103`). */
    private const TERMS = [
        ['key' => 'payment_terms', 'title' => null, 'body' => '50% advance'],
        ['key' => null, 'title' => 'Installation', 'body' => 'On site, two days'],
    ];

    /** @var array<string, User> */
    private array $users = [];

    private string $customerId;

    private string $lineId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->currency('EGP', '1', false, true);
        $this->customerId = $this->customer();
        $this->lineId = $this->supplierLine('10', '5');
    }

    // ────────────────────────────────────────────────────────── authorisation

    public function test_that_an_unauthenticated_caller_cannot_edit(): void
    {
        $this->patchJson(self::ENDPOINT.'/'.Uuid::uuid4()->toString(), $this->payload())->assertStatus(401);
    }

    public function test_that_the_manager_edits_any_draft(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));

        $this->edit($id, $etag, $this->payload(), RoleName::Manager)->assertStatus(200);
    }

    /** @return array<string, array{RoleName}> */
    public static function ownScoped(): array
    {
        return ['outdoor sales' => [RoleName::OutdoorSales], 'indoor sales' => [RoleName::IndoorSales]];
    }

    #[DataProvider('ownScoped')]
    public function test_that_an_own_scoped_role_edits_its_own_deals_draft(RoleName $role): void
    {
        [$id, $etag] = $this->quotation($this->deal($this->userWith($role)->id));

        $this->edit($id, $etag, $this->payload(), $role)->assertStatus(200);
    }

    /** §5.1: out of reach is a 404, indistinguishable from absent. */
    #[DataProvider('ownScoped')]
    public function test_that_an_own_scoped_role_cannot_edit_another_owners_draft(RoleName $role): void
    {
        [$id, $etag] = $this->quotation($this->deal($this->userWith(RoleName::Manager)->id));

        $this->edit($id, $etag, $this->payload(), $role)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found');
    }

    /** `D-138`: the deal's owner has no Team Leader, so the quotation is outside the leader's team — 404. */
    public function test_that_the_team_leader_sees_nothing_outside_the_team(): void
    {
        [$id, $etag] = $this->quotation($this->deal($this->userWith(RoleName::IndoorSales)->id));

        $this->edit($id, $etag, $this->payload(), RoleName::TeamLeader)->assertStatus(404);
    }

    /**
     * §3.5's `edit` cell is `—` for Procurement and the CEO, and the Outdoor Supervisor.
     *
     * @return array<string, array{RoleName}>
     */
    public static function nonEditors(): array
    {
        return [
            'procurement' => [RoleName::Procurement],
            'ceo' => [RoleName::Ceo],
            'outdoor supervisor' => [RoleName::OutdoorSupervisor],
        ];
    }

    #[DataProvider('nonEditors')]
    public function test_that_a_role_without_the_grant_cannot_edit(RoleName $role): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));

        $this->edit($id, $etag, $this->payload(), $role)->assertStatus(403);
    }

    /** `SEC-07`: the grant is a database row, and withdrawing it withdraws the route. */
    public function test_that_withdrawing_the_grant_refuses_a_role_that_had_it(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));
        DB::table('permissions')->where('resource', 'quotation')->where('action', 'edit')->delete();

        $this->edit($id, $etag, $this->payload(), RoleName::Manager)->assertStatus(403);
    }

    /** §3.5 `edit margin` is its own checkmark: without it, a changed line margin is refused … */
    public function test_that_a_line_margin_needs_the_edit_margin_grant(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));
        DB::table('permissions')->where('resource', 'quotation')->where('action', 'edit_margin')->delete();

        $this->edit($id, $etag, $this->payload([
            'lines' => [['supplier_quotation_item_id' => $this->lineId, 'quantity' => '2', 'margin_percent' => '30']],
        ]), RoleName::Manager)->assertStatus(403);
    }

    /** `D-106` (a): a negative extra is a margin too — the sign changes nothing about who may set it. */
    public function test_that_a_negative_line_margin_needs_the_edit_margin_grant(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));
        DB::table('permissions')->where('resource', 'quotation')->where('action', 'edit_margin')->delete();

        $this->edit($id, $etag, $this->payload([
            'lines' => [['supplier_quotation_item_id' => $this->lineId, 'quantity' => '2', 'margin_percent' => '-20']],
        ]), RoleName::Manager)->assertStatus(403);
    }

    /** … and an unchanged margin is not an edit of the margin. */
    public function test_that_an_unchanged_margin_needs_no_margin_grant(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));
        DB::table('permissions')->where('resource', 'quotation')->where('action', 'edit_margin')->delete();

        $this->edit($id, $etag, $this->payload(['terms' => [['key' => 'payment_terms', 'title' => null, 'body' => '30 days']]]), RoleName::Manager)->assertStatus(200);
    }

    /**
     * §3.5 `edit tax`, the same way. QS.2a: the tax is the line's (`D-157`), so moving a line's rate is the
     * edit it guards — 14 % to 5 % refused without it — and re-saving the same rates is not.
     */
    public function test_that_changing_a_lines_tax_needs_the_edit_tax_grant(): void
    {
        $line = $this->lineBody();
        [$id, $etag] = $this->quotation($this->deal(null), $this->e1Body($line));
        DB::table('permissions')->where('resource', 'quotation')->where('action', 'edit_tax')->delete();

        $this->edit($id, $etag, $this->e1Body(['tax_percent' => '5'] + $line), RoleName::Manager)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'permission_denied');
        $this->edit($id, $etag, $this->e1Body($line), RoleName::Manager)->assertStatus(200);
    }

    // ─────────────────────────────────────────────────────── optimistic lock

    /** `OpenAPI §5.1`: a missing or malformed `If-Match` is an invalid header → 400. */
    public function test_that_a_missing_if_match_is_a_400(): void
    {
        [$id] = $this->quotation($this->deal(null));

        $this->patchJson(self::ENDPOINT.'/'.$id, $this->payload(), $this->bearerFor(RoleName::Manager))
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'invalid_request')
            ->assertJsonPath('error.details.0.field', 'If-Match')
            ->assertJsonPath('error.details.0.code', QuotationWriteRefused::IF_MATCH_REQUIRED);
    }

    public function test_that_a_malformed_if_match_is_a_400(): void
    {
        [$id] = $this->quotation($this->deal(null));

        $this->edit($id, '"7"', $this->payload(), RoleName::Manager)->assertStatus(400);
    }

    /** `API-12`: a stale token is `409 concurrency_conflict` — never 412 — and names the current etag to refresh from. */
    public function test_that_a_stale_token_is_a_409_with_the_current_etag(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));

        $this->edit($id, 'quotation:'.$id.':0', $this->payload(), RoleName::Manager)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'concurrency_conflict')
            ->assertJsonPath('error.details.0.field', 'If-Match')
            ->assertJsonPath('error.details.0.code', QuotationWriteRefused::STALE_VERSION)
            ->assertJsonPath('error.details.0.current_etag', $etag);

        // Nothing was written.
        self::assertSame('20.000', DB::table('quotations')->where('id', $id)->value('default_margin'));
    }

    /** `DB-12`: the write advances the token, so the token that just succeeded is stale on the next try. */
    public function test_that_a_successful_edit_advances_the_token(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));

        $this->edit($id, $etag, $this->payload(), RoleName::Manager)
            ->assertStatus(200)
            ->assertJsonPath('data.etag', 'quotation:'.$id.':2')
            // §6.3's document version is a different counter and does not move on an edit.
            ->assertJsonPath('data.version', 1);

        $this->edit($id, $etag, $this->payload(), RoleName::Manager)->assertStatus(409);
    }

    // ────────────────────────────────────────────────────────── draft only

    /** §3.5 `edit` = "(Draft)"; a submitted quotation is Module 8's to edit. */
    public function test_that_a_non_draft_quotation_is_refused(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));
        DB::table('quotations')->where('id', $id)->update(['status' => 'pending']);

        $this->edit($id, $etag, $this->payload(), RoleName::Manager)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'business_rule_blocked')
            ->assertJsonPath('error.details.0.code', QuotationWriteRefused::NOT_DRAFT);
    }

    // ───────────────────────────────────────────────────── what the edit does

    /**
     * §5 re-priced on the backend from what was typed (`D-156`): a `tax_exempt` Draft (example 3) edited into
     * example 1 — 114 incl. 14 % → 100 net → 120.00 × 3, 10 % off before tax (`D-64`), the line's 14 % on
     * 324.00 = 45.36, the 50 delivery untaxed (`D-62`) → 419.36; no quotation margin (`D-156`).
     */
    public function test_that_an_edit_is_repriced_and_answered_in_full(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null), $this->e3Body());

        $this->edit($id, $etag, [
            ...$this->e1Body(),
            'terms' => [['key' => 'payment_terms', 'title' => null, 'body' => '30 days']],
        ], RoleName::Manager)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.pricing_mode', 'tax_included')
            ->assertJsonPath('data.default_margin', null)
            ->assertJsonPath('data.tax_percent', null)
            ->assertJsonPath('data.subtotal', '360.000000')
            ->assertJsonPath('data.discount_amount', '36.000000')
            ->assertJsonPath('data.tax_base', '324.000000')
            ->assertJsonPath('data.tax_amount', '45.360000')
            ->assertJsonPath('data.additional_total', '50.000000')
            ->assertJsonPath('data.final_total', '419.360000')
            ->assertJsonPath('data.terms.0.body', '30 days')
            ->assertJsonPath('data.items.0.quantity', '3.0000')
            ->assertJsonPath('data.items.0.unit_price', '120.000000')
            ->assertJsonPath('data.additional_items.0.description', 'Delivery')
            ->assertJsonCount(1, 'data.items')
            ->assertJsonCount(1, 'data.additional_items');

        // The replaced lines are soft-deleted, never physically gone (`DB-01`).
        self::assertSame(2, DB::table('quotation_items')->where('quotation_id', $id)->count());
        self::assertSame(1, DB::table('quotation_items')->where('quotation_id', $id)->whereNull('deleted_at')->count());
    }

    // ─────────────────────────────────────── QS.2a · a legacy Draft (D-165)

    /**
     * `D-165` (`D-152`'s U-8): a legacy taxed Draft opens converted — example 4's entry is 100 × 1.14 = 114
     * including 14 %, at its two margins summed, 10 + 5 = 15 % — and saved through the new body it becomes
     * `tax_included`, with no quotation margin and no quotation tax rate, at the same 403.97.
     */
    public function test_that_a_legacy_taxed_draft_converts_at_its_next_save_to_the_same_total(): void
    {
        $id = $this->legacyQuotation($this->deal(null), $this->customerId, $this->lineId);
        [$entry, $etag] = $this->entryOf($id);

        self::assertSame('tax_included', $entry['pricing_mode'] ?? null);
        $line = $this->entryLine($entry);
        self::assertSame('114.000000', $line['entered_unit_price'] ?? null);
        self::assertDecimal('14', $line['tax_percent'] ?? null);
        self::assertDecimal('15', $line['margin_percent'] ?? null);

        $this->edit($id, $etag, $this->bodyFrom($entry), RoleName::Manager)
            ->assertStatus(200)
            ->assertJsonPath('data.pricing_mode', 'tax_included')
            ->assertJsonPath('data.default_margin', null)
            ->assertJsonPath('data.tax_percent', null)
            ->assertJsonPath('data.tax_percents', [])
            ->assertJsonPath('data.items.0.unit_price', '115.000000')
            ->assertJsonPath('data.tax_amount', '43.470000')
            ->assertJsonPath('data.final_total', '403.970000');

        self::assertSame(
            ['pricing_mode' => 'tax_included', 'default_margin' => null, 'final_total' => '403.970000'],
            (array) DB::table('quotations')->where('id', $id)->first(['pricing_mode', 'default_margin', 'final_total']),
        );
    }

    /** `D-165`: an untaxed legacy Draft converts to `tax_exempt` — the 100 net typed as-is, no tax, 15 % → 360.50. */
    public function test_that_a_legacy_untaxed_draft_converts_to_tax_exempt(): void
    {
        $id = $this->legacyQuotation($this->deal(null), $this->customerId, $this->lineId, taxed: false);
        [$entry, $etag] = $this->entryOf($id);

        self::assertSame('tax_exempt', $entry['pricing_mode'] ?? null);
        $line = $this->entryLine($entry);
        self::assertSame('100.000000', $line['entered_unit_price'] ?? null);
        self::assertNull($line['tax_percent'] ?? null);
        self::assertDecimal('15', $line['margin_percent'] ?? null);

        $this->edit($id, $etag, $this->bodyFrom($entry), RoleName::Manager)
            ->assertStatus(200)
            ->assertJsonPath('data.pricing_mode', 'tax_exempt')
            ->assertJsonPath('data.default_margin', null)
            ->assertJsonPath('data.tax_amount', null)
            ->assertJsonPath('data.final_total', '360.500000');
    }

    /** `D-165`: an issued legacy quotation is never re-priced — reading it leaves every stored figure as it was. */
    public function test_that_reading_a_sent_legacy_quotation_leaves_its_money_alone(): void
    {
        $id = $this->legacyQuotation($this->deal(null), $this->customerId, $this->lineId, status: 'sent');
        $columns = ['pricing_mode', 'default_margin', 'tax_percent', 'tax_amount', 'final_total', 'version_token'];
        $before = (array) DB::table('quotations')->where('id', $id)->first($columns);

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.pricing_mode', 'legacy')
            ->assertJsonPath('data.final_total', '403.970000');

        self::assertSame(
            ['pricing_mode' => 'legacy', 'default_margin' => '10.000', 'tax_percent' => '14.000', 'tax_amount' => '43.470000', 'final_total' => '403.970000', 'version_token' => 1],
            $before,
        );
        self::assertSame($before, (array) DB::table('quotations')->where('id', $id)->first($columns));
        self::assertSame('115.000000', DB::table('quotation_items')->where('quotation_id', $id)->value('unit_price'));
    }

    /** §6.4: "every edit is written to the audit log" — with `AUD-02`'s old and new values. */
    public function test_that_an_edit_is_written_to_the_audit_log(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));

        $this->edit($id, $etag, $this->payload(['discount_percent' => '10']), RoleName::Manager)->assertStatus(200);

        $row = DB::table('audit_log')->where('entity_type', 'quotation')->where('entity_id', $id)->where('event', 'QUOTATION_UPDATED')->first();
        self::assertInstanceOf(stdClass::class, $row);
        self::assertIsString($row->old_values);
        self::assertIsString($row->new_values);
        $old = json_decode($row->old_values, true);
        $new = json_decode($row->new_values, true);
        self::assertIsArray($old);
        self::assertIsArray($new);
        self::assertSame('0.000', $old['discount_percent'] ?? null);
        self::assertSame('10', $new['discount_percent'] ?? null);
    }

    // ──────────────────────────────────────────────────────────── D-103 terms

    /** `D-103`: the terms are one list of `{key, title, body}`, answered in the order they were sent. */
    public function test_that_the_terms_are_saved_and_answered_in_order(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));

        $this->edit($id, $etag, $this->payload(['terms' => self::TERMS]), RoleName::Manager)->assertStatus(200);

        $data = $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))->assertStatus(200)->json('data');
        self::assertIsArray($data);
        self::assertEquals(self::TERMS, $data['terms'] ?? null);
        self::assertArrayNotHasKey('payment_terms', $data, 'D-103 retires the three fields from the API.');
        self::assertArrayNotHasKey('show_delivery_terms', $data, 'D-103 retires the flag from the API.');
    }

    /**
     * `terms` is a header field, so it follows `QuotationDraft::forCreate()`'s
     * rule for them: left out, it is left alone; sent as `[]`, it is emptied.
     */
    public function test_that_an_edit_without_terms_keeps_them_and_an_empty_list_clears_them(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));
        $etag = $this->edit($id, $etag, $this->payload(['terms' => self::TERMS]), RoleName::Manager)->assertStatus(200)->json('data.etag');
        self::assertIsString($etag);

        $etag = $this->edit($id, $etag, $this->payload(), RoleName::Manager)->assertStatus(200)->json('data.etag');
        self::assertIsString($etag);
        self::assertEquals(self::TERMS, $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))->json('data.terms'));

        $this->edit($id, $etag, $this->payload(['terms' => []]), RoleName::Manager)->assertStatus(200);
        self::assertSame([], $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))->json('data.terms'));
    }

    /** `D-103`: 15 is the maximum, refused by the API and not only by the button. */
    public function test_that_fifteen_terms_are_accepted_and_sixteen_refused(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));
        $term = ['key' => null, 'title' => 'Note', 'body' => 'Text'];

        $this->edit($id, $etag, $this->payload(['terms' => array_fill(0, 16, $term)]), RoleName::Manager)
            ->assertStatus(422)
            ->assertJsonFragment(['field' => 'terms']);

        $this->edit($id, $etag, $this->payload(['terms' => array_fill(0, 15, $term)]), RoleName::Manager)->assertStatus(200);
    }

    /** `D-103` (4): only the three ready terms have a label to fall back on; an added one needs its name. */
    public function test_that_an_added_term_with_a_body_needs_a_name(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));

        $this->edit($id, $etag, $this->payload(['terms' => [['key' => null, 'title' => null, 'body' => 'Text']]]), RoleName::Manager)
            ->assertStatus(422)
            ->assertJsonFragment(['field' => 'terms.0.title']);
    }

    /**
     * F-37 · 1.4b: the 422 under a row names the field the employee sees, in
     * the request's language — never the request path (`terms.0.title`).
     */
    public function test_that_a_row_refusal_names_the_field_in_the_request_language(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));
        $body = $this->payload([
            'terms' => [['key' => null, 'title' => null, 'body' => 'Text']],
            'additional_items' => [['description' => '', 'amount' => '']],
        ]);
        $expected = [
            'ar' => [
                'terms.0.title' => 'حقل اسم الشرط مطلوب.',
                'additional_items.0.description' => 'حقل وصف البند الإضافي مطلوب.',
                'additional_items.0.amount' => 'حقل مبلغ البند الإضافي مطلوب.',
            ],
            'en' => [
                'terms.0.title' => 'The term name field is required.',
                'additional_items.0.description' => 'The additional item description field is required.',
                'additional_items.0.amount' => 'The additional item amount field is required.',
            ],
        ];

        foreach ($expected as $locale => $messages) {
            $details = $this->withHeader('Accept-Language', $locale)
                ->edit($id, $etag, $body, RoleName::Manager)
                ->assertStatus(422)
                ->json('error.details');
            self::assertIsArray($details);

            foreach ($messages as $field => $message) {
                self::assertContains(
                    ['field' => $field, 'code' => 'invalid', 'message' => $message],
                    $details,
                    $locale.': '.json_encode($details, JSON_UNESCAPED_UNICODE),
                );
            }
        }
    }

    public function test_that_a_key_other_than_the_three_is_refused(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));

        $this->edit($id, $etag, $this->payload(['terms' => [['key' => 'installation', 'title' => 'Installation', 'body' => 'Text']]]), RoleName::Manager)
            ->assertStatus(422)
            ->assertJsonFragment(['field' => 'terms.0.key']);
    }

    /** §6.4 + `AUD-02`: a change of terms is an edit like any other, with its old and new values. */
    public function test_that_a_change_of_terms_is_audited_with_its_old_and_new_values(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));

        $this->edit($id, $etag, $this->payload(['terms' => self::TERMS]), RoleName::Manager)->assertStatus(200);

        $row = DB::table('audit_log')->where('entity_type', 'quotation')->where('entity_id', $id)->where('event', 'QUOTATION_UPDATED')->first();
        self::assertInstanceOf(stdClass::class, $row);
        self::assertIsString($row->old_values);
        self::assertIsString($row->new_values);
        $old = json_decode($row->old_values, true);
        $new = json_decode($row->new_values, true);
        self::assertIsArray($old);
        self::assertIsArray($new);
        self::assertSame([], $old['terms'] ?? null);
        self::assertEquals(self::TERMS, $new['terms'] ?? null);
    }

    /** The deal and the customer are the quotation's identity, not fields of an edit. */
    public function test_that_the_deal_and_customer_cannot_be_moved(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));

        $this->edit($id, $etag, $this->payload(['deal_id' => Uuid::uuid4()->toString()]), RoleName::Manager)
            ->assertStatus(422)
            ->assertJsonFragment(['field' => 'deal_id']);
        $this->edit($id, $etag, $this->payload(['customer_id' => Uuid::uuid4()->toString()]), RoleName::Manager)
            ->assertStatus(422)
            ->assertJsonFragment(['field' => 'customer_id']);
    }

    public function test_that_an_unknown_id_is_a_404(): void
    {
        $id = Uuid::uuid4()->toString();

        $this->edit($id, 'quotation:'.$id.':1', $this->payload(), RoleName::Manager)->assertStatus(404);
    }

    // ────────────────────────────────────────────────────────────── fixtures

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function edit(string $id, string $ifMatch, array $body, RoleName $role): TestResponse
    {
        return $this->patchJson(self::ENDPOINT.'/'.$id, $body, [...$this->bearerFor($role), 'If-Match' => $ifMatch]);
    }

    /**
     * The full editable body — an edit replaces every editable field, so the
     * default is the quotation as `quotation()` created it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'currency' => 'EGP',
            'default_margin' => '20',
            'discount_percent' => '0',
            'lines' => [['supplier_quotation_item_id' => $this->lineId, 'quantity' => '2']],
            'additional_items' => [['description' => 'Delivery', 'amount' => '5']],
        ], $overrides);
    }

    /**
     * Creates a quotation through Point 3.4's endpoint and reads its etag
     * through Point 3.5's: `$body` — QS.2a's line-owned body (`e1Body()`,
     * `e3Body()`) — or one line of 2 × 10 at 20 % margin, plus 5 delivery.
     *
     * @param  array<string, mixed>|null  $body
     * @return array{string, string} id and etag
     */
    private function quotation(string $dealId, ?array $body = null): array
    {
        $id = $this->postJson(self::ENDPOINT, [
            'deal_id' => $dealId,
            'customer_id' => $this->customerId,
            ...($body ?? $this->payload()),
        ], ['Idempotency-Key' => Uuid::uuid4()->toString()] + $this->bearerFor(RoleName::Manager))->assertStatus(201)->json('data.id');

        self::assertIsString($id);

        $etag = $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))->assertStatus(200)->json('data.etag');
        self::assertIsString($etag);

        return [$id, $etag];
    }

    /**
     * The `entry` the builder loads — for a legacy quotation, converted (`D-165`) — and the etag to save it under.
     *
     * @return array{array<mixed>, string}
     */
    private function entryOf(string $id): array
    {
        $data = $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))->assertStatus(200)->json('data');
        self::assertIsArray($data);
        $entry = $data['entry'] ?? null;
        $etag = $data['etag'] ?? null;
        self::assertIsArray($entry);
        self::assertIsString($etag);

        return [$entry, $etag];
    }

    /**
     * @param  array<mixed>  $entry
     * @return array<mixed> the entry's one line
     */
    private function entryLine(array $entry): array
    {
        $lines = $entry['lines'] ?? null;
        self::assertIsArray($lines);
        self::assertCount(1, $lines);
        $line = $lines[0] ?? null;
        self::assertIsArray($line);

        return $line;
    }

    /**
     * The body the builder saves from `$entry`: example 4's header (EGP, 10 % off, delivery 50) and the
     * entry's line as loaded, a null tax left out — `tax_exempt` refuses one.
     *
     * @param  array<mixed>  $entry
     * @return array<string, mixed>
     */
    private function bodyFrom(array $entry): array
    {
        $line = array_filter(
            array_intersect_key($this->entryLine($entry), array_flip(['supplier_id', 'catalog_item_id', 'unit_code', 'entered_unit_price', 'tax_percent', 'margin_percent', 'quantity'])),
            static fn (mixed $value): bool => $value !== null,
        );

        return [
            'currency' => 'EGP',
            'pricing_mode' => $entry['pricing_mode'] ?? null,
            'discount_percent' => '10',
            'lines' => [$line],
            'additional_items' => [['description' => 'Delivery', 'amount' => '50']],
        ];
    }

    /**
     * Equal to `$expected` as a number, whatever scale the server wrote it at.
     *
     * @param  numeric-string  $expected
     */
    private static function assertDecimal(string $expected, mixed $actual): void
    {
        self::assertIsString($actual);
        self::assertIsNumeric($actual);
        self::assertSame(0, bccomp($expected, $actual, 6), $actual.' is not '.$expected);
    }

    private function currency(string $code, string $unit, bool $enabled, bool $base): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('currencies')->insert([
            'id' => $id,
            'code' => $code,
            'rounding_unit' => $unit,
            'rounding_enabled' => $enabled,
            'is_base' => $base,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function currencyId(string $code): string
    {
        $id = DB::table('currencies')->where('code', $code)->value('id');
        self::assertIsString($id);

        return $id;
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

    private function deal(?string $ownerId): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('deals')->insert([
            'id' => $id,
            'code' => 'DL-'.now()->format('Y').'-'.substr($id, 0, 4),
            'customer_id' => $this->customerId,
            'owner_id' => $ownerId,
            'last_activity_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** Seeds §4.1's chain in EGP and returns the `supplier_quotation_items` id a line points at. */
    private function supplierLine(string $unitPrice, string $quantity): string
    {
        $supplierId = Uuid::uuid4()->toString();
        $catalogItemId = Uuid::uuid4()->toString();
        $offerId = Uuid::uuid4()->toString();
        $lineId = Uuid::uuid4()->toString();

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
            'currency_id' => $this->currencyId('EGP'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('supplier_quotation_items')->insert([
            'id' => $lineId,
            'supplier_quotation_id' => $offerId,
            'catalog_item_id' => $catalogItemId,
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
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
        $user = $this->userWith($role);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertStatus(201)->json('data.token');

        self::assertIsString($token);

        return ['Authorization' => 'Bearer '.$token];
    }
}
