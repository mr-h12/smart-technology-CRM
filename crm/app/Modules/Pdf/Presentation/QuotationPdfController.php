<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Presentation;

use App\Modules\Pdf\Application\Generation\RequestQuotationPdf;
use App\Modules\Pdf\Application\ReadQuotationPdf;
use App\Modules\Pdf\Domain\Generation\PdfGeneration;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Module 9's HTTP surface on a quotation: `GET …/pdf` (4.2) and `POST …/pdf` (3.5).
 */
final class QuotationPdfController
{
    public function show(Request $request, string $quotation, ReadQuotationPdf $read): JsonResponse
    {
        $state = $read->of($quotation, self::actorId($request));

        return ApiEnvelope::single($request, [
            'generation' => $state['generation'] === null ? null : self::generation($state['generation']),
            'latest_file_id' => $state['latestFileId'],
        ]);
    }

    /**
     * `OpenAPI §4.3`: `202` with the `job_id`, `queued` — not the file. The job
     * is dispatched once the request is committed, so it never runs ahead of
     * the row it works on.
     */
    public function generate(GenerateQuotationPdfRequest $request, string $quotation, RequestQuotationPdf $requestPdf): JsonResponse
    {
        $requested = $requestPdf->request($quotation, $request->documentLocale(), self::actorId($request));

        RenderQuotationPdfJob::dispatch($requested['generationId'], $requested['view']);

        return ApiEnvelope::single($request, ['job_id' => $requested['generationId'], 'status' => 'queued'], 202);
    }

    /** @return array<string, string|null> */
    private static function generation(PdfGeneration $generation): array
    {
        return [
            'job_id' => $generation->id,
            'status' => $generation->status->value,
            'requested_at' => $generation->requestedAt->format(DATE_ATOM),
            'completed_at' => $generation->completedAt()?->format(DATE_ATOM),
            'failure_reason' => $generation->failureReason,
        ];
    }

    private static function actorId(Request $request): string
    {
        $user = $request->user();

        if ($user === null) {
            throw new RuntimeException('The quotation PDF routes require authentication.');
        }

        $id = $user->getAuthIdentifier();

        if (! is_string($id) && ! is_int($id)) {
            throw new RuntimeException('The authenticated user has no usable identifier.');
        }

        return (string) $id;
    }
}
