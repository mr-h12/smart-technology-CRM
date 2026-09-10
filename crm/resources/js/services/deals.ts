import { apiGet, apiPatch, apiPost, apiUpload, collection, type Pagination } from '@/api';

/**
 * Module 5's ten endpoints, and nothing else.
 *
 * `D-67`: the SPA consumes `/api/v1` and never owns a calculation, a permission
 * decision or a state transition. Every rule this file could be tempted to
 * restate — who may approve, which scope reaches which row, which status may
 * follow which — is the server's, and is proved against the server by the
 * `tests/Feature/Deals` suites.
 *
 * ── The query shape is `DealListCriteria`'s, not a convenience ─────────────
 *
 * `OpenAPI §6.2` answers an unknown filter with a 400 and `ALLOWED_FILTERS` is
 * a closed set of four, so an unset filter is **omitted** rather than sent
 * empty: `filter[status]=` asks for deals whose status is the empty string,
 * which is a different question from "any status".
 *
 * **Unlike Module 6's list, `q` is real.** `SearchIndex::Deals` indexes
 * `title`, §4.3's one free-text field, so this list does declare a search and a
 * search box is a control the server will answer.
 *
 * ── The timeline's query is a different, smaller contract ──────────────────
 *
 * `DealTimelineCriteria` declares `page` and `per_page` **only**, and refuses
 * anything else with a 400 rather than ignoring it. So `listDealTimeline` sends
 * those two and never a sort, a search or an event filter — none of which
 * exist, and each of which would be a runtime 400 rather than a missing
 * feature.
 */

export type { Pagination } from '@/api';

/** §4.3's fields, as `DealPayload` serialises them. No customer or owner name: they belong to other modules. */
export interface Deal {
    id: string;
    code: string;
    customer_id: string;
    title: string | null;
    source: string | null;
    service_type: string | null;
    status: string;
    owner_id: string | null;
    approval_status: string | null;
    rejection_reason: string | null;
    lost_reason: string | null;
    last_activity_at: string;
    created_at: string;
    updated_at: string;
}

/** One `DealTimelinePayload` row — §4.4's four fields, plus the event and `SEC-10`'s second identity. */
export interface DealTimelineEntry {
    id: string;
    event: string;
    old_status: string | null;
    new_status: string | null;
    actor_id: string | null;
    impersonated_user_id: string | null;
    occurred_at: string;
}

/** One `DealDocumentPayload` row. No storage path — download is `/files/{id}/download`. */
export interface DealDocument {
    id: string;
    original_name: string;
    mime_type: string;
    size_bytes: number;
    scan_status: string;
    created_at: string;
}

export interface Page<T> {
    items: T[];
    pagination: Pagination;
}

/** The four filters, the sort and the search — every one of them the server's own name. */
export interface DealListQuery {
    page?: number;
    perPage?: number;
    q?: string | null;
    sort?: string | null;
    status?: string | null;
    serviceType?: string | null;
    source?: string | null;
    approvalStatus?: string | null;
}

/**
 * §4.3's caller-writable fields.
 *
 * `code`, `status`, `approval_status`, `rejection_reason` and
 * `last_activity_at` are `prohibited` server-side — a 422, not a silent drop —
 * so they are absent from this type rather than optional on it. `customer_id`
 * and `owner_id` are create-only for the same reason: `PATCH` prohibits both,
 * §3.4 making `assign_owner` its own permission and a deal's customer being the
 * one thing about it that cannot change.
 */
export interface DealDraft {
    title?: string | null;
    source?: string | null;
    service_type?: string | null;
}

export interface DealCreateDraft extends DealDraft {
    customer_id: string;
    owner_id?: string | null;
}

/** §4.4's twelve statuses, as the server stores them. The SPA renders these; it never decides which may follow which. */
export const DEAL_STATUSES = [
    'lead',
    'contacted',
    'waiting_customer_request',
    'supplier_rfq',
    'supplier_quotation',
    'quotation_sent',
    'negotiations',
    'won',
    'purchasing',
    'delivery',
    'delivery_complete',
    'lost',
] as const;

/** §4.3's `source` vocabulary. */
export const DEAL_SOURCES = ['outlook_whatsapp', 'outdoor_visit', 'employee_entry'] as const;

/** §4.3's `service_type` vocabulary. */
export const DEAL_SERVICE_TYPES = ['product', 'service'] as const;

/** §4.3's `approval_status` vocabulary. Null — never submitted — is not a value a filter can ask for. */
export const DEAL_APPROVAL_STATUSES = ['pending', 'approved', 'rejected'] as const;

/** `DealListCriteria::ALLOWED_SORTS`, closed. Prefix `-` for descending. */
export const DEAL_SORTS = ['code', 'created_at', 'last_activity_at'] as const;

/** The server's own default: "what needs attention" is the newest activity first. */
export const DEAL_DEFAULT_SORT = '-last_activity_at';

export async function listDeals(query: DealListQuery = {}): Promise<Page<Deal>> {
    const parameters = new URLSearchParams();

    if (query.page !== undefined) {
        parameters.set('page', String(query.page));
    }

    if (query.perPage !== undefined) {
        parameters.set('per_page', String(query.perPage));
    }

    if (typeof query.q === 'string' && query.q !== '') {
        parameters.set('q', query.q);
    }

    if (typeof query.sort === 'string' && query.sort !== '') {
        parameters.set('sort', query.sort);
    }

    // An unset filter is omitted, never sent empty — an empty value is a
    // different question, and an unknown key is a 400.
    for (const [key, value] of [
        ['filter[status]', query.status],
        ['filter[service_type]', query.serviceType],
        ['filter[source]', query.source],
        ['filter[approval_status]', query.approvalStatus],
    ] as const) {
        if (typeof value === 'string' && value !== '') {
            parameters.set(key, value);
        }
    }

    const suffix = parameters.size === 0 ? '' : `?${parameters.toString()}`;

    return collection<Deal>(await apiGet(`/deals${suffix}`));
}

export async function readDeal(id: string): Promise<Deal> {
    return (await apiGet<Deal>(`/deals/${id}`)).data;
}

export async function createDeal(draft: DealCreateDraft): Promise<Deal> {
    return (await apiPost<Deal>('/deals', draft)).data;
}

export async function updateDeal(id: string, draft: DealDraft): Promise<Deal> {
    return (await apiPatch<Deal>(`/deals/${id}`, draft)).data;
}

/**
 * §3.4's own row, separate from `edit`. `ownerId` is required: §3.4 has no
 * unassign row, and the server refuses a null.
 */
export async function assignDeal(id: string, ownerId: string): Promise<Deal> {
    return (await apiPatch<Deal>(`/deals/${id}/assign`, { owner_id: ownerId })).data;
}

/** Flow 3's decision. One permission, `deal.approve`, covers both directions. */
export async function approveDeal(id: string): Promise<Deal> {
    return (await apiPatch<Deal>(`/deals/${id}/approve`)).data;
}

/** The reason is mandatory and non-blank — `RejectDealRequest` refuses whitespace. */
export async function rejectDeal(id: string, reason: string): Promise<Deal> {
    return (await apiPatch<Deal>(`/deals/${id}/reject`, { reason })).data;
}

/**
 * §4.4's transition. `reason` is required when the target is `lost` and
 * **prohibited** otherwise, so it is omitted rather than sent null — a null
 * would be a 422 on every other status.
 */
export async function changeDealStatus(id: string, status: string, reason?: string): Promise<Deal> {
    const body: Record<string, string> = { status };

    if (status === 'lost' && typeof reason === 'string') {
        body.reason = reason;
    }

    return (await apiPatch<Deal>(`/deals/${id}/status`, body)).data;
}

/**
 * §17's upload. The form field is **`document`** and must stay that name:
 * `ApiExceptionRenderer` hard-codes it when mapping the server's refusal back
 * onto a control, so a rename 422s every upload *and* points the error at a
 * field that does not exist.
 */
export async function attachDealDocument(id: string, file: File): Promise<DealDocument> {
    const form = new FormData();
    form.append('document', file);

    return (await apiUpload<DealDocument>(`/deals/${id}/documents`, form)).data;
}

/**
 * §4.4's timeline. Two parameters and no others — the server refuses an
 * undeclared one with a 400 rather than ignoring it.
 */
export async function listDealTimeline(
    id: string,
    query: { page?: number; perPage?: number } = {},
): Promise<Page<DealTimelineEntry>> {
    const parameters = new URLSearchParams();

    if (query.page !== undefined) {
        parameters.set('page', String(query.page));
    }

    if (query.perPage !== undefined) {
        parameters.set('per_page', String(query.perPage));
    }

    const suffix = parameters.size === 0 ? '' : `?${parameters.toString()}`;

    return collection<DealTimelineEntry>(await apiGet(`/deals/${id}/timeline${suffix}`));
}
