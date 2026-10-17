import { apiDelete, apiGet, apiPatch, apiPost, collection, type Page } from '@/api';
import { downloadFrom } from '@/services/files';

/**
 * Module 13 — the author's side of a report (`GET/POST /reports`,
 * `GET /reports/{id}`, `/recipient-candidates`, `/submit`, and slice 3's
 * `/follow-ups` and `/notes`, `D-131`) and, since
 * slice 2, the recipient's (`GET /reports/received[/{id}]`, `/acknowledge`, `/return`).
 *
 * The screen shows what the server returns: the counts, the default recipient
 * and every refusal are the server's (`D-67`, `AP-07`).
 */

// §11.4's statuses; Missed is J-04's (`D-135`), Archived arrives with the slice that sets it.
export type ReportStatus = 'draft' | 'submitted' | 'viewed' | 'acknowledged' | 'returned' | 'missed';

export interface ReportSummary {
    id: string;
    code: string;
    type: string;
    status: ReportStatus;
    /** The version the payload shows (`D-132`); a submitted one has its stored PDF. */
    version: number;
    period_start: string;
    period_end: string;
    submitted_at: string | null;
    /** `D-135`: submitted after it became Missed. */
    submitted_late: boolean;
    /** `D-136`: a weekly report J-06 submitted unchanged after the review window. */
    not_reviewed: boolean;
}

/** `D-126` RPT-DY's six counts. */
export const ACTIVITY_COUNTS = [
    'deals_created',
    'deal_status_moves',
    'quotations_created',
    'quotations_sent',
    'customer_responses',
    'purchase_orders',
] as const;

export type ActivityCount = (typeof ACTIVITY_COUNTS)[number];

/** `D-130`: each kind's records as their own pages show them — selling totals only (`D-126`). */
interface DealRow {
    deal_id: string;
    code: string;
    customer_name: string;
}

interface QuotationRow {
    quotation_id: string;
    code: string;
    version: number;
    customer_name: string;
    status: string;
    final_total: string;
    currency: string;
}

export interface ActivitySections {
    deals_created: Array<DealRow & { status: string }>;
    deal_status_moves: Array<DealRow & { from_status: string; to_status: string }>;
    quotations_created: QuotationRow[];
    quotations_sent: QuotationRow[];
    customer_responses: Array<QuotationRow & { response: 'accepted' | 'rejected' | 'partial' | 'countered' }>;
    purchase_orders: Array<{
        purchase_order_id: string;
        po_number: string;
        po_date: string;
        quotation_id: string;
        quotation_code: string;
        customer_name: string;
        final_total: string;
        currency: string;
    }>;
}

/** `D-131`: a draft's rows carry their id; a submitted report's are its frozen copy's, without one. */
export interface ReportFollowUp {
    id?: string;
    customer_id: string;
    customer_name: string;
    note: string;
}

export interface ReportNote {
    id?: string;
    body: string;
}

export interface ReportDetail extends ReportSummary {
    author_name?: string;
    follow_ups: ReportFollowUp[];
    notes: ReportNote[];
    etag: string;
    activity: { counts: Record<ActivityCount, number>; sections: ActivitySections };
    recipients: Array<{ id: string; name: string }>;
    /** `D-126`/`D-134`: RPT-WK's own sections; present only on a weekly report. */
    daily_reports?: Array<{ code: string; period_start: string; status: ReportStatus }>;
    purchase_orders_received?: number;
    new_customers?: { count: number; items: Array<{ customer_id: string; name: string }> };
}

/** A report sent to the caller: the list row and the detail both name its author. */
export interface ReceivedReportSummary extends ReportSummary {
    author_name: string;
}

export interface RecipientCandidate {
    id: string;
    name: string;
    is_default: boolean;
}

export async function listMyReports(page = 1): Promise<Page<ReportSummary>> {
    return collection<ReportSummary>(await apiGet(`/reports?page=${page}`));
}

/** Today's daily report; `OpenAPI §9.1`'s key makes a retried click one report. */
export async function createDailyReport(idempotencyKey: string): Promise<{ id: string; code: string }> {
    return (await apiPost<{ id: string; code: string }>('/reports', { type: 'daily' }, { 'Idempotency-Key': idempotencyKey })).data;
}

export async function readReport(id: string): Promise<ReportDetail> {
    return (await apiGet<ReportDetail>(`/reports/${id}`)).data;
}

export interface ReportPdfState {
    status: 'queued' | 'completed' | 'failed';
    file_id: string | null;
    failure_reason: string | null;
    finished_at: string | null;
}

/** `D-132`: the stored PDF of one submitted version; the author and a recipient read it. */
export async function readReportPdf(id: string, version: number): Promise<ReportPdfState> {
    return (await apiGet<ReportPdfState>(`/reports/${id}/versions/${version}/pdf`)).data;
}

/** `D-132`, 7.2: a `failed` version goes back to the queue; the server answers the new state (`202`) or refuses (`422`). */
export async function retryReportPdf(id: string, version: number): Promise<ReportPdfState> {
    return (await apiPost<ReportPdfState>(`/reports/${id}/versions/${version}/pdf/retry`, {})).data;
}

/** `D-132`, 7.2: the stored PDF through the report's route, which audits each download. */
export async function downloadReportPdf(id: string, version: number, filename: string): Promise<void> {
    return downloadFrom(`/reports/${id}/versions/${version}/pdf/download`, filename);
}

/** `D-131`'s row writes carry no `If-Match`: the last write to a row wins; the server answers the row. */
export async function addReportFollowUp(id: string, row: { customer_id: string; note: string }): Promise<{ id: string; customer_id: string; note: string }> {
    return (await apiPost<{ id: string; customer_id: string; note: string }>(`/reports/${id}/follow-ups`, row)).data;
}

export async function updateReportFollowUp(id: string, rowId: string, row: { customer_id: string; note: string }): Promise<{ id: string; customer_id: string; note: string }> {
    return (await apiPatch<{ id: string; customer_id: string; note: string }>(`/reports/${id}/follow-ups/${rowId}`, row)).data;
}

export async function removeReportFollowUp(id: string, rowId: string): Promise<void> {
    await apiDelete(`/reports/${id}/follow-ups/${rowId}`);
}

export async function addReportNote(id: string, body: string): Promise<{ id: string; body: string }> {
    return (await apiPost<{ id: string; body: string }>(`/reports/${id}/notes`, { body })).data;
}

export async function updateReportNote(id: string, noteId: string, body: string): Promise<{ id: string; body: string }> {
    return (await apiPatch<{ id: string; body: string }>(`/reports/${id}/notes/${noteId}`, { body })).data;
}

export async function removeReportNote(id: string, noteId: string): Promise<void> {
    await apiDelete(`/reports/${id}/notes/${noteId}`);
}

export async function readRecipientCandidates(id: string): Promise<RecipientCandidate[]> {
    return (await apiGet<RecipientCandidate[]>(`/reports/${id}/recipient-candidates`)).data;
}

export async function submitReport(id: string, etag: string, recipientIds: string[]): Promise<ReportDetail> {
    return (await apiPatch<ReportDetail>(`/reports/${id}/submit`, { recipient_ids: recipientIds }, { 'If-Match': etag })).data;
}

export async function listReceivedReports(page = 1): Promise<Page<ReceivedReportSummary>> {
    return collection<ReceivedReportSummary>(await apiGet(`/reports/received?page=${page}`));
}

/** `D-127`: the server marks the report Viewed on a recipient's first open. */
export async function readReceivedReport(id: string): Promise<ReportDetail> {
    return (await apiGet<ReportDetail>(`/reports/received/${id}`)).data;
}

export async function acknowledgeReport(id: string, etag: string): Promise<ReportDetail> {
    return (await apiPatch<ReportDetail>(`/reports/${id}/acknowledge`, {}, { 'If-Match': etag })).data;
}

export async function returnReport(id: string, etag: string, reason: string): Promise<ReportDetail> {
    return (await apiPatch<ReportDetail>(`/reports/${id}/return`, { reason }, { 'If-Match': etag })).data;
}
