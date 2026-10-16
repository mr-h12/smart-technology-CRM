<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Pdf\Application\Generation\RenderReportPdf;
use App\Modules\Pdf\Domain\Contracts\PdfRendererInterface;
use App\Modules\Storage\Domain\AttachmentParent;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Storage\RemovesOnlyFilesItStored;
use Tests\TestCase;

/**
 * Module 13, slice 4 · 4.1 red — who reaches a submitted version's PDF (`D-132`,
 * spec §4.3): `GET /api/v1/reports/{report}/versions/{version}/pdf` and
 * `GET /api/v1/files/{file}/download`.
 *
 * - A caller reaches it only with `report.export` (a refusal is `403`, from the route)
 *   and as the report's author or a live recipient (anything else is `404`, as opening the report is).
 * - No sign-in is `401`. A draft, a missing version and a version with no generation row are `404`.
 * - `Team` and `Out` narrow nothing until teams exist (`D-129`): a Team Leader who is not a recipient is `404`.
 */
final class ReportPdfEndpointTest extends TestCase
{
    use RefreshDatabase;
    use RemovesOnlyFilesItStored;

    private const PASSWORD = 'Passw0rd123';

    private const ENDPOINT = '/api/v1/reports';

    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF\n";

    /** @var array<string, string> one login per user per test — the login limit counts every call */
    private array $tokens = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(9, 0));
        $this->rememberStoredFiles(AttachmentParent::Report);

        Queue::fake();
        $this->app->instance(PdfRendererInterface::class, new ReportPdfFakeRenderer(self::PDF));
    }

    protected function tearDown(): void
    {
        $this->removeFilesStoredSince(AttachmentParent::Report);

        parent::tearDown();
    }

    // ── status ──────────────────────────────────────────────────────────────

    public function test_that_the_author_and_a_recipient_read_a_queued_version(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $id = $this->submitted($author, [$manager]);

        foreach ([$author, $manager] as $reader) {
            $this->pdfStatus($reader, $id, 1)
                ->assertStatus(200)
                ->assertJsonPath('data.status', 'queued')
                ->assertJsonPath('data.file_id', null)
                ->assertJsonPath('data.failure_reason', null)
                ->assertJsonPath('data.finished_at', null)
                ->assertJsonStructure(['data' => ['status', 'file_id', 'failure_reason', 'finished_at']]);
        }
    }

    public function test_that_a_completed_version_answers_its_file_and_a_failed_one_its_reason(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $id = $this->submitted($author, [$manager]);
        $generation = $this->generation($id, 1);
        $this->render($generation);

        $fileId = DB::table('report_pdf_generations')->where('id', $generation)->value('file_id');
        self::assertIsString($fileId, 'The render did not complete, so there is no file to answer.');
        $this->pdfStatus($manager, $id, 1)
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.file_id', $fileId)
            ->assertJsonPath('data.failure_reason', null);
        self::assertNotNull($this->pdfStatus($manager, $id, 1)->json('data.finished_at'));

        DB::table('report_pdf_generations')->where('id', $generation)->update([
            'status' => 'failed', 'file_id' => null, 'failure_reason' => 'The PDF could not be rendered.',
        ]);
        $this->pdfStatus($author, $id, 1)
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.file_id', null)
            ->assertJsonPath('data.failure_reason', 'The PDF could not be rendered.');
    }

    /** @return array<string, array{RoleName}> */
    public static function outsiders(): array
    {
        return [
            'another sales employee' => [RoleName::OutdoorSales],
            'Procurement (Own: not its report)' => [RoleName::Procurement],
            'a Manager the report was not sent to' => [RoleName::Manager],
            'the CEO, when not a recipient' => [RoleName::Ceo],
            'a Team Leader who is not a recipient (D-129)' => [RoleName::TeamLeader],
        ];
    }

    #[DataProvider('outsiders')]
    public function test_that_anyone_else_gets_a_404_for_the_status_and_the_download(RoleName $role): void
    {
        $author = $this->user(RoleName::IndoorSales);
        // The recipient is a second Manager, so the Manager outsider above is a different user.
        $recipient = $this->user(RoleName::Manager, 'recipient');
        $id = $this->submitted($author, [$recipient]);
        $generation = $this->generation($id, 1);
        $this->render($generation);
        $fileId = (string) DB::table('report_pdf_generations')->where('id', $generation)->value('file_id');
        self::assertNotSame('', $fileId, 'The render did not complete.');

        $outsider = $this->user($role, 'outsider');

        $this->pdfStatus($outsider, $id, 1)->assertStatus(404)->assertJsonPath('error.code', 'resource_not_found');
        $this->get('/api/v1/files/'.$fileId.'/download', $this->bearer($outsider))->assertNotFound();
    }

    public function test_that_a_caller_who_is_not_signed_in_gets_a_401(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $id = $this->submitted($author, [$this->user(RoleName::Manager)]);
        // The request guard keeps the user it resolved before; drop it so the call carries no identity.
        $this->app['auth']->forgetGuards();

        $this->getJson(self::ENDPOINT.'/'.$id.'/versions/1/pdf')->assertStatus(401);
    }

    /** `report.export` is checked by the route, ahead of any row rule: a role with no grants is `403`, not `404`. */
    public function test_that_a_role_without_report_export_gets_a_403(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $id = $this->submitted($author, [$this->user(RoleName::Manager)]);

        $role = new Role;
        $role->fill(['slug' => 'auditor_without_grants', 'name' => 'Auditor', 'is_editable' => true]);
        $role->save();
        $user = new User;
        $user->fill([
            'name' => 'Grantless', 'email' => 'grantless@example.test', 'password' => self::PASSWORD,
            'role_id' => $role->id, 'is_active' => true, 'is_hidden' => false,
        ]);
        $user->save();

        $this->pdfStatus($user, $id, 1)->assertStatus(403);
    }

    public function test_that_a_draft_a_missing_version_and_a_version_with_no_generation_are_404(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);

        $draft = $this->create($author);
        $this->pdfStatus($author, $draft, 1)->assertStatus(404);

        $id = $this->submitted($author, [$manager]);
        $this->pdfStatus($author, $id, 9)->assertStatus(404);

        // A version submitted before slice 4 has no row, and nothing back-fills it (the owner's answer (a)).
        DB::table('report_pdf_generations')->where('report_id', $id)->delete();
        $this->pdfStatus($author, $id, 1)->assertStatus(404);
        $this->pdfStatus($manager, $id, 1)->assertStatus(404);
    }

    // ── download ────────────────────────────────────────────────────────────

    /** The files route is the only download (`D-38`); `Report` joins its composite permission (spec §4.3). */
    public function test_that_the_author_and_a_recipient_download_the_stored_pdf(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $id = $this->submitted($author, [$manager]);
        $generation = $this->generation($id, 1);
        $this->render($generation);
        $fileId = (string) DB::table('report_pdf_generations')->where('id', $generation)->value('file_id');
        self::assertNotSame('', $fileId, 'The render did not complete.');

        foreach ([$author, $manager] as $reader) {
            $response = $this->get('/api/v1/files/'.$fileId.'/download', $this->bearer($reader));

            $response->assertOk();
            self::assertSame(self::PDF, $response->streamedContent());
        }
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    /** @return TestResponse<\Illuminate\Http\JsonResponse> */
    private function pdfStatus(User $caller, string $reportId, int $version): TestResponse
    {
        return $this->getJson(self::ENDPOINT.'/'.$reportId.'/versions/'.$version.'/pdf', $this->bearer($caller));
    }

    private function render(string $generationId): void
    {
        $this->app->make(RenderReportPdf::class)->handle($generationId, 1);
    }

    private function generation(string $reportId, int $version): string
    {
        $id = DB::table('report_pdf_generations')->where('report_id', $reportId)->where('version', $version)->value('id');
        self::assertIsString($id, "The submit queued no v{$version} generation.");

        return $id;
    }

    /** @param list<User> $recipients */
    private function submitted(User $author, array $recipients): string
    {
        $id = $this->create($author);
        $this->patchJson(
            self::ENDPOINT.'/'.$id.'/submit',
            ['recipient_ids' => array_map(static fn (User $u): string => $u->id, $recipients)],
            [...$this->bearer($author), 'If-Match' => 'report:'.$id.':1'],
        )->assertStatus(200);

        return $id;
    }

    private function create(User $author): string
    {
        $id = $this->postJson(self::ENDPOINT, ['type' => 'daily'], $this->bearer($author))
            ->assertStatus(201)
            ->json('data.id');
        self::assertIsString($id);

        return $id;
    }

    private function user(RoleName $role, string $tag = ''): User
    {
        $user = new User;
        $user->fill([
            'name' => 'Test '.$role->label().($tag !== '' ? ' '.$tag : ''),
            'email' => str_replace('_', '.', $role->value).($tag !== '' ? '.'.$tag : '').'@example.test',
            'password' => self::PASSWORD,
            'role_id' => Role::query()->where('slug', $role->value)->firstOrFail()->id,
            'is_active' => true,
            'is_hidden' => $role->isHidden(),
        ]);
        $user->save();

        return $user;
    }

    /** @return array<string, string> */
    private function bearer(User $user): array
    {
        if (! isset($this->tokens[$user->id])) {
            $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])
                ->assertStatus(201)->json('data.token');
            self::assertIsString($token);
            $this->tokens[$user->id] = $token;
        }

        return ['Authorization' => 'Bearer '.$this->tokens[$user->id], 'Idempotency-Key' => (string) Str::uuid()];
    }
}
