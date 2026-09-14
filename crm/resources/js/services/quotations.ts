import { apiDelete, apiGet, apiPatch, apiPost, collection, type ApiResult, type Page, type Pagination } from '@/api';

// Module 7's client — the seven routes Steps 3–5 built, and nothing else.
//
// Every shape here is the server's, read back as it was written: money is a
// string end to end (`DB-07` has no client-side exception — a `Number` here
// would round what the backend refused to), the cost fields are optional
// because `QuotationPayload::detail()` strips them for a caller without §3.5's
// `view cost & margin`, and the client makes no decision about who sees what
// (`D-67`). Two things are new to this SPA and live here first: `If-Match`
// (`OpenAPI §9.2`) and `Idempotency-Key` (`OpenAPI §9.1`).

/** `QuotationPayload::summary()` — the 14 keys of a list row. No cost, margin or supplier field, by Step 5's Q6. */
export interface QuotationSummary {
    id: string;
    code: string;
    status: string;
    customer_id: string;
    deal_id: string;
    currency_id: string;
    /** The ISO code behind `currency_id` — the row names it because `GET /currencies` is an admin's (Point 6.3, owner's ruling A). */
    currency: string;
    final_total: string;
    quotation_date: string | null;
    valid_until: string | null;
    submitted_at: string | null;
    version: number;
    parent_id: string | null;
    created_at: string;
    updated_at: string;
}

/** One `group_by` group. `key` is `null` for a deal with no owner; `label` is the server's — the name, or the id when it has none. */
export interface QuotationGroup {
    key: string | null;
    label: string;
    count: number;
    items: QuotationSummary[];
}

/** A line as the detail returns it. The `unit_cost*`, `margin_percent` and `line_cost` keys are absent without §3.5's grant. */
export interface QuotationLine {
    id: string;
    line_no: number;
    supplier_quotation_item_id: string;
    quantity: string;
    unit_price: string;
    line_total: string;
    unit_cost?: string;
    unit_cost_currency?: string;
    unit_cost_fx_rate_at_time?: string;
    unit_cost_base?: string;
    margin_percent?: string | null;
    line_cost?: string;
}

/** `D-62`: never taxed, added after the tax. */
export interface QuotationAdditionalItem {
    id: string;
    line_no: number;
    description: string;
    amount: string;
}

/**
 * `QuotationPayload::detail()`. `tax_amount` is `null` on an exempt quotation
 * (`D-63` — no tax line, not a zero line); `rounding_diff` is `"0"` when the
 * currency does not round (`D-65`). `default_margin` is a cost field.
 */
export interface QuotationDetail extends QuotationSummary {
    default_margin?: string;
    discount_percent: string;
    tax_percent: string | null;
    rounding_unit: string;
    rounding_enabled: boolean;
    subtotal: string;
    additional_total: string;
    discount_amount: string;
    tax_base: string;
    tax_amount: string | null;
    net_amount: string;
    total_before_round: string;
    rounding_diff: string;
    payment_terms: string | null;
    warranty: string | null;
    delivery_terms: string | null;
    show_delivery_terms: boolean;
    rejection_reason: string | null;
    sent_at: string | null;
    is_self_approved: boolean;
    /** `OpenAPI §9.2`'s version token — what the next mutation sends as `If-Match`. */
    etag: string;
    items: QuotationLine[];
    additional_items: QuotationAdditionalItem[];
    created_by: string | null;
    updated_by: string | null;
}

/** One `meta.warnings` entry: `quantity_exceeds_recorded` on a save, `supplier_price_changed` on a read (`§5.6`, `D-36`). Never a block. */
export interface QuotationWarning {
    field: string;
    code: string;
    message: string;
}

/** A read or a write answers with the quotation and the warnings beside it. */
export interface QuotationRead {
    quotation: QuotationDetail;
    warnings: QuotationWarning[];
}

export interface QuotationLineDraft {
    supplier_quotation_item_id: string;
    quantity: string;
    margin_percent?: string | null;
}

export interface QuotationAdditionalItemDraft {
    description: string;
    amount: string;
}

/**
 * `SaveQuotationRequest`'s editable fields. `lines` and `additional_items`
 * are required on an update — an edit replaces every editable field (Point
 * 3.6), and an omitted list is a 422, not "keep them".
 */
export interface QuotationDraft {
    currency: string;
    default_margin: string;
    discount_percent: string;
    tax_percent: string | null;
    quotation_date?: string | null;
    valid_until?: string | null;
    payment_terms?: string | null;
    warranty?: string | null;
    delivery_terms?: string | null;
    show_delivery_terms?: boolean | null;
    lines: QuotationLineDraft[];
    additional_items: QuotationAdditionalItemDraft[];
}

/** `deal_id` and `customer_id` are set once; the server prohibits them on a `PATCH`. */
export interface QuotationCreateDraft extends QuotationDraft {
    deal_id: string;
    customer_id: string;
}

export interface GroupedPage {
    groups: QuotationGroup[];
    pagination: Pagination;
}

/** `QuotationListCriteria::ALLOWED_FILTERS`, every one under the server's own name. */
export interface QuotationListQuery {
    page?: number;
    perPage?: number;
    sort?: string | null;
    status?: string | null;
    bucket?: string | null;
    employee?: string | null;
    customerId?: string | null;
    dealId?: string | null;
    currency?: string | null;
    amountMin?: string | null;
    amountMax?: string | null;
    from?: string | null;
    to?: string | null;
}

/** `QuotationListCriteria::STATUSES` — §6.1's nine, as the server stores them. */
export const QUOTATION_STATUSES = [
    'draft',
    'pending',
    'approved',
    'sent',
    'accepted',
    'partial',
    'counter',
    'rejected',
    'expired',
] as const;

/** The server's own default (Step 5 Q6): the last touched first. */
export const QUOTATION_DEFAULT_SORT = 'updated_at';

function queryString(query: QuotationListQuery, groupBy?: string): string {
    const parameters = new URLSearchParams();

    if (query.page !== undefined) {
        parameters.set('page', String(query.page));
    }

    if (query.perPage !== undefined) {
        parameters.set('per_page', String(query.perPage));
    }

    if (typeof query.sort === 'string' && query.sort !== '') {
        parameters.set('sort', query.sort);
    }

    // An unset filter is omitted, never sent empty — an empty value is a
    // different question, and an unknown shape is a 400.
    for (const [key, value] of [
        ['filter[status]', query.status],
        ['filter[bucket]', query.bucket],
        ['filter[employee]', query.employee],
        ['filter[customer_id]', query.customerId],
        ['filter[deal_id]', query.dealId],
        ['filter[currency]', query.currency],
        ['filter[amount_min]', query.amountMin],
        ['filter[amount_max]', query.amountMax],
        ['filter[from]', query.from],
        ['filter[to]', query.to],
    ] as const) {
        if (typeof value === 'string' && value !== '') {
            parameters.set(key, value);
        }
    }

    if (groupBy !== undefined) {
        parameters.set('group_by', groupBy);
    }

    return parameters.size === 0 ? '' : `?${parameters.toString()}`;
}

function read(result: ApiResult<QuotationDetail>): QuotationRead {
    return {
        quotation: result.data,
        warnings: Array.isArray(result.meta.warnings) ? (result.meta.warnings as QuotationWarning[]) : [],
    };
}

export async function listQuotations(query: QuotationListQuery = {}): Promise<Page<QuotationSummary>> {
    return collection<QuotationSummary>(await apiGet(`/quotations${queryString(query)}`));
}

/** The same page, grouped by the server (`OpenAPI §6.2` "server-side grouping only"). Pagination counts quotations, not groups. */
export async function listQuotationGroups(groupBy: string, query: QuotationListQuery = {}): Promise<GroupedPage> {
    const { items, pagination } = collection<QuotationGroup>(await apiGet(`/quotations${queryString(query, groupBy)}`));

    return { groups: items, pagination };
}

export async function readQuotation(id: string): Promise<QuotationRead> {
    return read(await apiGet<QuotationDetail>(`/quotations/${id}`));
}

/** `idempotencyKey` is minted by the screen once per attempt (`crypto.randomUUID()`); a retry with the same key replays the first answer. */
export async function createQuotation(draft: QuotationCreateDraft, idempotencyKey: string): Promise<QuotationRead> {
    return read(await apiPost<QuotationDetail>('/quotations', draft, { 'Idempotency-Key': idempotencyKey }));
}

/** Draft only. `etag` is the detail's; a stale one is `409 stale_version`. */
export async function updateQuotation(id: string, etag: string, draft: QuotationDraft): Promise<QuotationRead> {
    return read(await apiPatch<QuotationDetail>(`/quotations/${id}`, draft, { 'If-Match': etag }));
}

/** Draft → Pending (Point 4.2). No body; the transition is the server's. */
export async function submitQuotation(id: string, etag: string): Promise<QuotationDetail> {
    return (await apiPatch<QuotationDetail>(`/quotations/${id}/submit-for-approval`, undefined, { 'If-Match': etag })).data;
}

/** `D-08`'s full copy as a new Draft (Point 4.3). Answers the copy's `id`, `code` and `version` — read it for the rest. */
export async function createQuotationVersion(
    id: string,
    idempotencyKey: string,
): Promise<{ id: string; code: string; version: number }> {
    return (
        await apiPost<{ id: string; code: string; version: number }>(`/quotations/${id}/new-version`, undefined, {
            'Idempotency-Key': idempotencyKey,
        })
    ).data;
}

/** Draft only (`D-46`, §3.5). A 204 — nothing comes back. */
export async function deleteQuotation(id: string, etag: string): Promise<void> {
    await apiDelete<undefined>(`/quotations/${id}`, { 'If-Match': etag });
}
