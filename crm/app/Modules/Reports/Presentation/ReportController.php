<?php

declare(strict_types=1);

namespace App\Modules\Reports\Presentation;

use App\Modules\Reports\Application\CreateReport;
use App\Modules\Reports\Application\RecipientCandidates;
use App\Modules\Reports\Application\ReportAccess;
use App\Modules\Reports\Application\ShowReport;
use App\Modules\Reports\Application\SubmitReport;
use App\Modules\Reports\Application\UpdateReportNotes;
use App\Modules\Reports\Domain\Report;
use App\Modules\Reports\Domain\ReportEtag;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Module 13, first slice — the author's side of a report: "My reports",
 * create, read, edit the notes, the recipient candidates, submit. Every route
 * carries `permission:report.create` (§3.10); reach is the author's own.
 */
final class ReportController
{
    private const PER_PAGE = 25;

    public function index(Request $request, ShowReport $reports): JsonResponse
    {
        $page = max(1, $request->integer('page', 1));
        $mine = $reports->mine(self::actorId($request), $page, self::PER_PAGE);
        $pages = max(1, (int) ceil($mine['total'] / self::PER_PAGE));

        return ApiEnvelope::collection($request, array_map(self::summary(...), $mine['items']), [
            'page' => $page,
            'per_page' => self::PER_PAGE,
            'total' => $mine['total'],
            'total_pages' => $pages,
            'has_next_page' => $page < $pages,
            'has_previous_page' => $page > 1,
        ]);
    }

    public function store(CreateReportRequest $request, CreateReport $reports): JsonResponse
    {
        return ApiEnvelope::single($request, $reports->daily(self::actorId($request)), 201);
    }

    public function show(Request $request, string $report, ShowReport $reports): JsonResponse
    {
        return ApiEnvelope::single($request, self::detail($reports->one($report, self::actorId($request))));
    }

    public function update(UpdateReportRequest $request, string $report, UpdateReportNotes $notes, ShowReport $reports): JsonResponse
    {
        $actorId = self::actorId($request);
        $notes->update($report, $request->notes(), $request->headers->get('If-Match'), $actorId);

        return ApiEnvelope::single($request, self::detail($reports->one($report, $actorId)));
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
    private static function summary(Report $report): array
    {
        return [
            'id' => $report->id,
            'code' => $report->code,
            'type' => $report->type,
            'status' => $report->status,
            'period_start' => $report->periodStart,
            'period_end' => $report->periodEnd,
            'submitted_at' => $report->submittedAt,
        ];
    }

    /**
     * @param  array{report: Report, activity: array<string, mixed>, recipients: list<array{id: string, name: string}>}  $read
     * @return array<string, mixed>
     */
    private static function detail(array $read): array
    {
        return [
            ...self::summary($read['report']),
            'notes' => $read['report']->notes,
            'etag' => ReportEtag::of($read['report']),
            'activity' => $read['activity'],
            'recipients' => $read['recipients'],
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
