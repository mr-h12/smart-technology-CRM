import { apiGet, apiPatch, apiPost, collection, type Page } from '@/api';

/**
 * Module 13, first slice — the author's side of a report (`GET/POST /reports`,
 * `GET/PATCH /reports/{id}`, `/recipient-candidates`, `/submit`).
 *
 * The screen shows what the server returns: the counts, the default recipient
 * and every refusal are the server's (`D-67`, `AP-07`).
 */

// This slice writes Draft and Submitted only; §11.4's other statuses arrive with the slices that set them.
export type ReportStatus = 'draft' | 'submitted';

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

export interface ActivityItem {
    event: string;
    entity_type: string;
    entity_id: string;
    occurred_at: string;
}

export interface ReportDetail extends ReportSummary {
    notes: string | null;
    etag: string;
    activity: { counts: Record<ActivityCount, number>; items: ActivityItem[] };
    recipients: Array<{ id: string; name: string }>;
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
