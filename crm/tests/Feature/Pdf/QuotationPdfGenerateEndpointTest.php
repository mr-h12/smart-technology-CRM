<?php

declare(strict_types=1);

namespace Tests\Feature\Pdf;

use App\Modules\Admin\Domain\Contracts\SettingsRepositoryInterface;
use App\Modules\Admin\Domain\Settings\SystemSetting;
use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Pdf\Application\CustomerQuotationViewMapper;
use App\Modules\Pdf\Presentation\RenderQuotationPdfJob;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Module 9, Point 3.5 — `POST /api/v1/quotations/{quotation}/pdf` under §3.5's
 * *generate PDF* row: the view is mapped here, when the button is pressed
 * (Q10), a `queued` generation is written in the language asked for (Q15),
 * the job goes onto the `pdf` queue, and the answer is `OpenAPI §4.3`'s `202`.
 */
final class QuotationPdfGenerateEndpointTest extends TestCase
{
    use InsertsQuotationRows;
    use RefreshDatabase;
    use SignsInByRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingsSeeder::class);
        $this->app->make(SettingsRepositoryInterface::class)->put(SystemSetting::CompanyName, 'Smart Technology for Integrated Systems');

        Queue::fake();
    }

    public function test_that_a_manager_asks_for_a_pdf_and_is_answered_with_its_job(): void
    {
        $quotationId = $this->insertQuotation($this->userWith(RoleName::IndoorSales)->id);
        $manager = $this->userWith(RoleName::Manager);

        $response = $this->postJson($this->endpoint($quotationId), ['locale' => 'en'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'queued');

        $jobId = $response->json('data.job_id');
        self::assertIsString($jobId);

        $generation = DB::table('pdf_generations')->where('id', $jobId)->first();
        self::assertNotNull($generation);
        self::assertSame($quotationId, $generation->quotation_id);
        self::assertSame('en', $generation->locale);
        self::assertSame('queued', $generation->status);
        self::assertSame($manager->id, $generation->created_by);

        // Q10: the job carries the view as it was when the button was pressed.
        $code = DB::table('quotations')->where('id', $quotationId)->value('code');
        Queue::assertPushedOn('pdf', RenderQuotationPdfJob::class, fn (RenderQuotationPdfJob $job): bool => $job->generationId === $jobId && $job->view->code === $code);

        self::assertSame(1, DB::table('audit_log')
            ->where('event', 'QUOTATION_PDF_REQUESTED')
            ->where('entity_type', 'quotation')
            ->where('entity_id', $quotationId)
            ->where('user_id', $manager->id)
            ->count());
    }

    /**
     * `D-108`: a deal's `details` is "internal only — never on a customer PDF". The view the job
     * carries is the one mapped from the database; its title proves the deal was read at all.
     */
    public function test_that_a_deals_internal_details_never_reach_the_customer_pdf(): void
    {
        $quotationId = $this->insertQuotation($this->userWith(RoleName::IndoorSales)->id);
        DB::table('deals')
            ->where('id', DB::table('quotations')->where('id', $quotationId)->value('deal_id'))
            ->update(['title' => 'Office fit-out', 'details' => 'INTERNAL-DETAILS-7731']);

        $this->postJson($this->endpoint($quotationId), ['locale' => 'en'], $this->bearerFor(RoleName::Manager))->assertStatus(202);

        Queue::assertPushedOn('pdf', RenderQuotationPdfJob::class, function (RenderQuotationPdfJob $job): bool {
            $view = serialize($job->view);

            return str_contains($view, 'Office fit-out') && ! str_contains($view, 'INTERNAL-DETAILS-7731');
        });
    }

    public function test_that_the_language_is_the_requests_own_unless_one_is_named(): void
    {
        $quotationId = $this->insertQuotation();

        $jobId = $this->postJson($this->endpoint($quotationId), [], $this->bearerFor(RoleName::Manager) + ['Accept-Language' => 'ar'])
            ->assertStatus(202)
            ->json('data.job_id');

        self::assertSame('ar', DB::table('pdf_generations')->where('id', $jobId)->value('locale'));
    }

    public function test_that_a_language_the_template_has_no_words_for_is_refused(): void
    {
        $this->postJson($this->endpoint($this->insertQuotation()), ['locale' => 'fr'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');

        self::assertSame(0, DB::table('pdf_generations')->count());
        Queue::assertNothingPushed();
    }

    /** @return array<string, array{RoleName, RoleName}> */
    public static function salesRoles(): array
    {
        return [
            'Outdoor Sales' => [RoleName::OutdoorSales, RoleName::IndoorSales],
            'Indoor Sales' => [RoleName::IndoorSales, RoleName::OutdoorSales],
        ];
    }

    #[DataProvider('salesRoles')]
    public function test_that_a_sales_role_asks_for_its_own_deals_pdf_and_not_another_employees(RoleName $role, RoleName $other): void
    {
        $own = $this->insertQuotation($this->userWith($role)->id);
        $others = $this->insertQuotation($this->userWith($other)->id);

        $this->postJson($this->endpoint($own), [], $this->bearerFor($role))->assertStatus(202);
        $this->postJson($this->endpoint($others), [], $this->bearerFor($role))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'resource_not_found');

        self::assertSame(0, DB::table('pdf_generations')->where('quotation_id', $others)->count());
    }

    /** `D-138` — Team on generate: a member's deal's PDF is the leader's to ask for; an outsider's is 404. */
    public function test_that_a_team_leader_asks_for_a_team_members_pdf_and_not_an_outsiders(): void
    {
        $member = $this->userWith(RoleName::IndoorSales);
        $member->forceFill(['team_leader_id' => $this->userWith(RoleName::TeamLeader)->id])->save();
        $members = $this->insertQuotation($member->id);
        $outsiders = $this->insertQuotation($this->userWith(RoleName::OutdoorSales)->id);

        $this->postJson($this->endpoint($members), [], $this->bearerFor(RoleName::TeamLeader))->assertStatus(202);
        $this->postJson($this->endpoint($outsiders), [], $this->bearerFor(RoleName::TeamLeader))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'resource_not_found');

        self::assertSame(0, DB::table('pdf_generations')->where('quotation_id', $outsiders)->count());
    }

    public function test_that_an_unowned_deals_pdf_is_nobodys_own(): void
    {
        $quotationId = $this->insertQuotation(null);

        $this->postJson($this->endpoint($quotationId), [], $this->bearerFor(RoleName::IndoorSales))->assertNotFound();
    }

    /** @return array<string, array{RoleName}> */
    public static function refusedByName(): array
    {
        return [
            'Procurement (Asgn, Q2)' => [RoleName::Procurement],
            'CEO (no grant to generate)' => [RoleName::Ceo],
            'Outdoor Supervisor (a dash on the row)' => [RoleName::OutdoorSupervisor],
        ];
    }

    #[DataProvider('refusedByName')]
    public function test_that_the_render_is_refused_by_name_to(RoleName $role): void
    {
        $quotationId = $this->insertQuotation($this->userWith(RoleName::IndoorSales)->id);

        $this->postJson($this->endpoint($quotationId), [], $this->bearerFor($role))
            ->assertForbidden()
            ->assertJsonPath('error.details.0.code', 'unauthorized_action');

        self::assertSame(0, DB::table('pdf_generations')->count());
        Queue::assertNothingPushed();
    }

    public function test_that_an_indescribable_quotation_is_a_422_and_nothing_is_queued(): void
    {
        $this->app->make(SettingsRepositoryInterface::class)->put(SystemSetting::CompanyName, '');

        $this->postJson($this->endpoint($this->insertQuotation()), [], $this->bearerFor(RoleName::Manager))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'business_rule_blocked')
            ->assertJsonPath('error.details.0.code', 'pdf_view_incomplete');

        self::assertSame(0, DB::table('pdf_generations')->count());
        self::assertSame(0, DB::table('audit_log')->where('event', 'QUOTATION_PDF_REQUESTED')->count());
        Queue::assertNothingPushed();
    }

    /** QS.1 · `D-155`: the line names its own product, so a supplier's edit that soft-deleted its offer line refuses no PDF. */
    public function test_that_a_quotation_on_a_deleted_offer_generates_its_pdf(): void
    {
        $quotationId = $this->pricedQuotation('draft', null, 'EGP', '1000', lineCosts: ['1000']);
        DB::table('supplier_quotation_items')->update(['deleted_at' => now()]);

        $jobId = $this->postJson($this->endpoint($quotationId), ['locale' => 'en'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')
            ->json('data.job_id');

        self::assertIsString($jobId);
        Queue::assertPushedOn('pdf', RenderQuotationPdfJob::class, fn (RenderQuotationPdfJob $job): bool => $job->generationId === $jobId);
    }

    /**
     * QS.1 · `D-155`, `D-107`: the mapper describes a line by its own catalog item, and gives it that item's unit,
     * with the line's offer line gone. The bound mapper, over the database, so the test names no port.
     */
    public function test_that_a_line_on_a_deleted_offer_maps_its_description_and_its_unit(): void
    {
        $quotationId = $this->pricedQuotation('draft', null, 'EGP', '1000', lineCosts: ['1000']);
        DB::table('enum_lists')->insert([
            'id' => Uuid::uuid7()->toString(), 'list' => 'units', 'code' => 'qs1-piece', 'label_en' => 'Piece', 'label_ar' => 'قطعة',
            'position' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('catalog_items')->update(['unit' => 'qs1-piece']);
        DB::table('supplier_quotation_items')->update(['deleted_at' => now()]);

        $line = $this->app->make(CustomerQuotationViewMapper::class)->forQuotation($quotationId)->lines[0];

        self::assertSame(['Widget', 'Piece', 'قطعة'], [$line->description, $line->unitEn, $line->unitAr]);
    }

    public function test_that_an_unknown_quotation_is_a_404(): void
    {
        $this->postJson($this->endpoint(Uuid::uuid7()->toString()), [], $this->bearerFor(RoleName::Manager))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'resource_not_found');
    }

    public function test_that_a_caller_with_no_session_is_a_401(): void
    {
        $this->postJson($this->endpoint($this->insertQuotation()))->assertUnauthorized();
    }

    private function endpoint(string $quotationId): string
    {
        return '/api/v1/quotations/'.$quotationId.'/pdf';
    }
}
