<?php

declare(strict_types=1);

namespace Tests\Feature\Pdf;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Module 9, Point 4.2 — `GET /api/v1/quotations/{quotation}/pdf`, what
 * `OpenAPI §4.3` leaves to "the module contract": the newest generation of one
 * quotation's PDF and the newest completed file's id, under §3.5's
 * *export/download PDF* row.
 */
final class QuotationPdfStateEndpointTest extends TestCase
{
    use InsertsQuotationRows;
    use RefreshDatabase;
    use SignsInByRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_that_a_quotation_never_generated_answers_nothing_yet(): void
    {
        $quotationId = $this->insertQuotation();

        $this->getJson($this->endpoint($quotationId), $this->bearerFor(RoleName::Manager))
            ->assertOk()
            ->assertJsonPath('data', ['generation' => null, 'latest_file_id' => null])
            ->assertJsonStructure(['meta' => ['request_id']]);
    }

    public function test_that_a_queued_request_reads_back_under_its_job_id(): void
    {
        $quotationId = $this->insertQuotation();
        $jobId = $this->insertGeneration(['quotation_id' => $quotationId, 'created_at' => '2026-09-27 09:00:00+00']);

        $this->getJson($this->endpoint($quotationId), $this->bearerFor(RoleName::Manager))
            ->assertOk()
            ->assertJsonPath('data.generation', [
                'job_id' => $jobId,
                'status' => 'queued',
                'requested_at' => '2026-09-27T09:00:00+00:00',
                'completed_at' => null,
                'failure_reason' => null,
            ])
            ->assertJsonPath('data.latest_file_id', null);
    }

    public function test_that_a_completed_render_is_the_generation_and_the_file(): void
    {
        $quotationId = $this->insertQuotation();
        $fileId = $this->insertFile();
        $jobId = $this->insertGeneration([
            'quotation_id' => $quotationId,
            'status' => 'completed',
            'file_id' => $fileId,
            'created_at' => '2026-09-27 09:00:00+00',
            'finished_at' => '2026-09-27 09:00:05+00',
        ]);

        $this->getJson($this->endpoint($quotationId), $this->bearerFor(RoleName::Manager))
            ->assertOk()
            ->assertJsonPath('data.generation', [
                'job_id' => $jobId,
                'status' => 'completed',
                'requested_at' => '2026-09-27T09:00:00+00:00',
                'completed_at' => '2026-09-27T09:00:05+00:00',
                'failure_reason' => null,
            ])
            ->assertJsonPath('data.latest_file_id', $fileId);
    }

    public function test_that_a_failed_render_is_shown_while_the_previous_pdf_stays_the_download(): void
    {
        $quotationId = $this->insertQuotation();
        $fileId = $this->insertFile();
        $this->insertGeneration([
            'quotation_id' => $quotationId,
            'status' => 'completed',
            'file_id' => $fileId,
            'created_at' => '2026-09-26 09:00:00+00',
            'finished_at' => '2026-09-26 09:00:05+00',
        ]);
        $failed = $this->insertGeneration([
            'quotation_id' => $quotationId,
            'status' => 'failed',
            'failure_reason' => 'The renderer timed out.',
            'created_at' => '2026-09-27 09:00:00+00',
            'finished_at' => '2026-09-27 09:01:00+00',
        ]);

        // A failure never completed, so it has no `completed_at` to show.
        $this->getJson($this->endpoint($quotationId), $this->bearerFor(RoleName::Manager))
            ->assertOk()
            ->assertJsonPath('data.generation', [
                'job_id' => $failed,
                'status' => 'failed',
                'requested_at' => '2026-09-27T09:00:00+00:00',
                'completed_at' => null,
                'failure_reason' => 'The renderer timed out.',
            ])
            ->assertJsonPath('data.latest_file_id', $fileId);
    }

    public function test_that_the_newest_request_is_the_generation_and_another_quotations_is_never_shown(): void
    {
        $quotationId = $this->insertQuotation();
        $this->insertGeneration(['quotation_id' => $quotationId, 'created_at' => '2026-09-27 08:00:00+00']);
        $newest = $this->insertGeneration(['quotation_id' => $quotationId, 'created_at' => '2026-09-27 09:00:00+00']);
        $this->insertGeneration(['created_at' => '2026-09-27 10:00:00+00']);

        $this->getJson($this->endpoint($quotationId), $this->bearerFor(RoleName::Manager))
            ->assertOk()
            ->assertJsonPath('data.generation.job_id', $newest);
    }

    public function test_that_two_requests_in_the_same_instant_resolve_to_the_later_id(): void
    {
        // `D-61`: the ids are time-ordered, so they break a tie on `created_at`.
        $quotationId = $this->insertQuotation();
        $this->insertGeneration(['quotation_id' => $quotationId, 'created_at' => '2026-09-27 09:00:00+00']);
        $later = $this->insertGeneration(['quotation_id' => $quotationId, 'created_at' => '2026-09-27 09:00:00+00']);

        $this->getJson($this->endpoint($quotationId), $this->bearerFor(RoleName::Manager))
            ->assertOk()
            ->assertJsonPath('data.generation.job_id', $later);
    }

    public function test_that_a_soft_deleted_generation_and_its_file_are_absent(): void
    {
        $quotationId = $this->insertQuotation();
        $this->insertGeneration([
            'quotation_id' => $quotationId,
            'status' => 'completed',
            'file_id' => $this->insertFile(),
            'created_at' => '2026-09-27 09:00:00+00',
            'finished_at' => '2026-09-27 09:00:05+00',
            'deleted_at' => '2026-09-27 09:30:00+00',
        ]);

        $this->getJson($this->endpoint($quotationId), $this->bearerFor(RoleName::Manager))
            ->assertOk()
            ->assertJsonPath('data', ['generation' => null, 'latest_file_id' => null]);
    }

    /** @return array<string, array{RoleName}> */
    public static function checkmarks(): array
    {
        return [
            'Team Leader (D-91 reads the ✅ as All)' => [RoleName::TeamLeader],
            'CEO' => [RoleName::Ceo],
        ];
    }

    #[DataProvider('checkmarks')]
    public function test_that_a_checkmark_reads_any_quotations_pdf(RoleName $role): void
    {
        $quotationId = $this->insertQuotation($this->userWith(RoleName::IndoorSales)->id);

        $this->getJson($this->endpoint($quotationId), $this->bearerFor($role))->assertOk();
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
    public function test_that_a_sales_role_reads_its_own_deals_pdf_and_not_another_employees(RoleName $role, RoleName $other): void
    {
        $own = $this->insertQuotation($this->userWith($role)->id);
        $others = $this->insertQuotation($this->userWith($other)->id);

        $this->getJson($this->endpoint($own), $this->bearerFor($role))->assertOk();
        $this->getJson($this->endpoint($others), $this->bearerFor($role))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'resource_not_found');
    }

    public function test_that_an_unowned_deals_pdf_is_nobodys_own(): void
    {
        $quotationId = $this->insertQuotation(null);

        $this->getJson($this->endpoint($quotationId), $this->bearerFor(RoleName::IndoorSales))->assertNotFound();
        $this->getJson($this->endpoint($quotationId), $this->bearerFor(RoleName::Manager))->assertOk();
    }

    public function test_that_procurement_is_refused_by_name_because_asgn_has_no_mechanism(): void
    {
        // Q2: fail closed, and say so — a 403 naming the action, not an empty answer.
        $quotationId = $this->insertQuotation($this->userWith(RoleName::IndoorSales)->id);

        $this->getJson($this->endpoint($quotationId), $this->bearerFor(RoleName::Procurement))
            ->assertForbidden()
            ->assertJsonPath('error.details.0.code', 'unauthorized_action');
    }

    public function test_that_the_outdoor_supervisor_holds_no_grant(): void
    {
        $quotationId = $this->insertQuotation($this->userWith(RoleName::IndoorSales)->id);

        $this->getJson($this->endpoint($quotationId), $this->bearerFor(RoleName::OutdoorSupervisor))
            ->assertForbidden()
            ->assertJsonPath('error.details.0.code', 'unauthorized_action');
    }

    public function test_that_an_unknown_quotation_is_a_404(): void
    {
        $this->getJson($this->endpoint(Uuid::uuid7()->toString()), $this->bearerFor(RoleName::Manager))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'resource_not_found');
    }

    public function test_that_a_caller_with_no_session_is_a_401(): void
    {
        $this->getJson($this->endpoint($this->insertQuotation()))->assertUnauthorized();
    }

    private function endpoint(string $quotationId): string
    {
        return '/api/v1/quotations/'.$quotationId.'/pdf';
    }
}
