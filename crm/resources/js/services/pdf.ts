import { apiGet, apiPost } from '@/api';

/**
 * Module 9's two routes on a quotation, and nothing else: `GET …/pdf` reads
 * the state of its customer PDF (4.2) and `POST …/pdf` asks for a new render
 * (3.5). The download is Storage's one file route, in `services/files.ts`.
 */

type PdfGenerationStatus = 'queued' | 'completed' | 'failed';

/** The newest generation — a render asked for, with its `job_id` (`OpenAPI §4.3`). */
interface PdfGeneration {
    job_id: string;
    status: PdfGenerationStatus;
    requested_at: string;
    /** Only once the render completed; a failure never has one. */
    completed_at: string | null;
    failure_reason: string | null;
}

export interface QuotationPdfState {
    generation: PdfGeneration | null;
    /** The newest *completed* render's file — the one a download serves (Q4). */
    latest_file_id: string | null;
}

/** Q15: the document's language, named by whoever asks for it. */
export type PdfLocale = 'ar' | 'en';

export async function readQuotationPdf(quotationId: string): Promise<QuotationPdfState> {
    return (await apiGet<QuotationPdfState>(`/quotations/${quotationId}/pdf`)).data;
}

/**
 * `OpenAPI §4.3`: a `202` — queued, not ready. Its `job_id` is not kept: the
 * caller reads the state back from 4.2, which names the same job.
 */
export async function requestQuotationPdf(quotationId: string, locale: PdfLocale): Promise<void> {
    await apiPost<unknown>(`/quotations/${quotationId}/pdf`, { locale });
}
