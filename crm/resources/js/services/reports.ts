import { apiGet, apiPatch, apiPost, collection, type Page } from '@/api';

/**
 * Module 13 — the author's side of a report (`GET/POST /reports`,
 * `GET/PATCH /reports/{id}`, `/recipient-candidates`, `/submit`) and, since
 * slice 2, the recipient's (`GET /reports/received[/{id}]`, `/acknowledge`, `/return`).
 *
 * The screen shows what the server returns: the counts, the default recipient
 * and every refusal are the server's (`D-67`, `AP-07`).
 */

// §11.4's statuses the first two slices set; Archived and Missed arrive with the slices that set them.
export type ReportStatus = 'draft' | 'submitted' | 'viewed' | 'acknowledged' | 'returned';

export interface ReportSummary {
    id: string;
    code: string;
    type: string;
    status: ReportStatus;
    period_start: string;
    period_end: string;
    submitted_at: string | null;
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

export interface ReportDetail extends ReportSummary {
    author_name?: string;
    notes: string | null;
    etag: string;
    activity: { counts: Record<ActivityCount, number>; sections: ActivitySections };
    recipients: Array<{ id: string; name: string }>;
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

export async function saveReportNotes(id: string, etag: string, notes: string): Promise<ReportDetail> {
    return (await apiPatch<ReportDetail>(`/reports/${id}`, { notes }, { 'If-Match': etag })).data;
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
