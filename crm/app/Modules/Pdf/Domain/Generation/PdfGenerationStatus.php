<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Domain\Generation;

/**
 * Where one generation stands — the literals `pdf_generations_known_status`
 * CHECKs (Point 3.3). `queued` until the job (3.4) finishes it.
 */
enum PdfGenerationStatus: string
{
    case Queued = 'queued';
    case Completed = 'completed';
    case Failed = 'failed';
}
