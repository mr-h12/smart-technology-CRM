<?php

declare(strict_types=1);

namespace App\Modules\Reports\Presentation;

use App\Modules\Reports\Application\CreateReport;
use App\Modules\Reports\Application\ManageReportRows;
use App\Modules\Reports\Application\ReceiveReport;
use App\Modules\Reports\Application\RecipientCandidates;
use App\Modules\Reports\Application\ReportAccess;
use App\Modules\Reports\Application\ShowReport;
use App\Modules\Reports\Application\SubmitReport;
use App\Modules\Reports\Domain\Report;
use App\Modules\Reports\Domain\ReportEtag;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

/**
 * Module 13 — a report's two sides. The author's (first slice): "My reports",
 * create, read, the follow-ups and notes (slice 3, `D-131`), the recipient candidates, submit, under
 * `permission:report.create` with the author's own reach. The recipient's
 * (slice 2): "Received reports", open (Viewed), acknowledge, return, under
 * §3.10's *view received* / *acknowledge* / *return* with a recipient's reach.
 */
final class ReportController
{
    private const PER_PAGE = 25;

    public function index(Request $request, ShowReport $reports): JsonResponse
    {
        $page = max(1, $request->integer('page', 1));
        $mine = $reports->mine(self::actorId($request), $page, self::PER_PAGE);

        return ApiEnvelope::collection($request, array_map(static fn (Report $r): array => self::summary($r, $r->shownVersion(! $r->isEditable())), $mine['items']), self::pagination($page, $mine['total']));
    }

    public function received(Request $request, ShowReport $reports): JsonResponse
    {
        $page = max(1, $request->integer('page', 1));
        $sent = $reports->received(self::actorId($request), $page, self::PER_PAGE);
        $rows = array_map(static fn (array $row): array => [...self::summary($row['report'], $row['report']->shownVersion(true)), 'author_name' => $row['author_name']], $sent['items']);

        return ApiEnvelope::collection($request, $rows, self::pagination($page, $sent['total']));
    }

    /** `D-127`: a recipient's first open is what marks it Viewed. */
    public function showReceived(Request $request, string $report, ReceiveReport $receive, ShowReport $reports): JsonResponse
    {
        $actorId = self::actorId($request);
        $receive->view($report, $actorId);

        return ApiEnvelope::single($request, self::detail($reports->oneReceived($report, $actorId)));
    }

    public function acknowledge(Request $request, string $report, ReceiveReport $receive, ShowReport $reports): JsonResponse
    {
        $actorId = self::actorId($request);
        $receive->acknowledge($report, $request->headers->get('If-Match'), $actorId);

        return ApiEnvelope::single($request, self::detail($reports->oneReceived($report, $actorId)));
    }

    public function returnToAuthor(ReturnReportRequest $request, string $report, ReceiveReport $receive, ShowReport $reports): JsonResponse
    {
        $actorId = self::actorId($request);
        $receive->returnToAuthor($report, $request->reason(), $request->headers->get('If-Match'), $actorId);

        return ApiEnvelope::single($request, self::detail($reports->oneReceived($report, $actorId)));
    }

    public function store(CreateReportRequest $request, CreateReport $reports): JsonResponse
    {
        return ApiEnvelope::single($request, $reports->daily(self::actorId($request)), 201);
    }

    public function show(Request $request, string $report, ShowReport $reports): JsonResponse
    {
        return ApiEnvelope::single($request, self::detail($reports->one($report, self::actorId($request))));
    }

    /** ponytail: `D-131` left this route no field to write; it stays to refuse the old `notes` (the `D-122` precedent). */
    public function update(UpdateReportRequest $request, string $report, ShowReport $reports): JsonResponse
    {
        return ApiEnvelope::single($request, self::detail($reports->one($report, self::actorId($request))));
    }

    public function addFollowUp(ReportFollowUpRequest $request, string $report, ManageReportRows $rows): JsonResponse
    {
        return ApiEnvelope::single($request, $rows->add('follow_up', $report, $request->fields(), self::actorId($request)), 201);
    }

    public function updateFollowUp(ReportFollowUpRequest $request, string $report, string $row, ManageReportRows $rows): JsonResponse
    {
        return ApiEnvelope::single($request, $rows->update('follow_up', $report, $row, $request->fields(), self::actorId($request)));
    }

    public function removeFollowUp(Request $request, string $report, string $row, ManageReportRows $rows): Response
    {
        $rows->remove('follow_up', $report, $row, self::actorId($request));

        return response()->noContent();
    }

    public function addNote(ReportNoteRequest $request, string $report, ManageReportRows $rows): JsonResponse
    {
        return ApiEnvelope::single($request, $rows->add('note', $report, $request->fields(), self::actorId($request)), 201);
    }

    public function updateNote(ReportNoteRequest $request, string $report, string $note, ManageReportRows $rows): JsonResponse
    {
        return ApiEnvelope::single($request, $rows->update('note', $report, $note, $request->fields(), self::actorId($request)));
    }

    public function removeNote(Request $request, string $report, string $note, ManageReportRows $rows): Response
    {
        $rows->remove('note', $report, $note, self::actorId($request));

        return response()->noContent();
    }

    public function candidates(Request $request, string $report, ReportAccess $access, RecipientCandidates $candidates): JsonResponse
    {
        return ApiEnvelope::single($request, $candidates->for($access->reach($report, self::actorId($request))));
    }

    public function submit(SubmitReportRequest $request, string $report, SubmitReport $submit, ShowReport $reports): JsonResponse
    {
        $actorId = self::actorId($request);
        $submit->submit($report, $request->recipientIds(), $request->headers->get('If-Match'), $actorId);

        return ApiEnvelope::single($request, self::detail($reports->one($report, $actorId)));
    }

    /** @return array<string, mixed> */
    private static function summary(Report $report, int $version): array
    {
        return [
            'id' => $report->id,
            'version' => $version,
            'code' => $report->code,
            'type' => $report->type,
            'status' => $report->status,
            'period_start' => $report->periodStart,
            'period_end' => $report->periodEnd,
            'submitted_at' => $report->submittedAt,
        ];
    }

    /**
     * @param  array{report: Report, version: int, follow_ups: list<array<string, string>>, notes: list<array<string, string>>, activity: array<string, mixed>, recipients: list<array{id: string, name: string}>, author_name?: string}  $read
     * @return array<string, mixed>
     */
    private static function detail(array $read): array
    {
        return [
            ...self::summary($read['report'], $read['version']),
            ...(isset($read['author_name']) ? ['author_name' => $read['author_name']] : []),
            'follow_ups' => $read['follow_ups'],
            'notes' => $read['notes'],
            'etag' => ReportEtag::of($read['report']),
            'activity' => $read['activity'],
            'recipients' => $read['recipients'],
        ];
    }

    /** @return array{page: int, per_page: int, total: int, total_pages: int, has_next_page: bool, has_previous_page: bool} */
    private static function pagination(int $page, int $total): array
    {
        $pages = max(1, (int) ceil($total / self::PER_PAGE));

        return [
            'page' => $page,
            'per_page' => self::PER_PAGE,
            'total' => $total,
            'total_pages' => $pages,
            'has_next_page' => $page < $pages,
            'has_previous_page' => $page > 1,
        ];
    }

    private static function actorId(Request $request): string
    {
        $id = $request->user()?->getAuthIdentifier();

        if (! is_string($id) && ! is_int($id)) {
            throw new RuntimeException('The report routes require authentication.');
        }

        return (string) $id;
    }
}
