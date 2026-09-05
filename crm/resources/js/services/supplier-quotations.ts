import { apiGet, apiPatch, apiPost, apiUpload, collection, type Pagination } from '@/api';

/**
 * Module 6's five routes, and nothing else.
 *
 * `D-67`: the SPA consumes `/api/v1` and never owns a calculation, a permission
 * decision or a state transition. Every rule this file could be tempted to
 * restate is the server's and is proved there — who may write (§3.6 gives the
 * CEO `view` and no `create`), who may attach (a **third** grant,
 * `upload_attachment`, which the CEO also lacks), and what an offer totals.
 *
 * ── `total_price` is entered, never summed — and never a `number` ──────────
 *
 * The owner's ruling of 2026-09-02: the total is typed by the user, not derived
 * from the lines. So nothing here adds anything up. It is also a `string`:
 * `DB-07` forbids floating point anywhere near money and the server sends
 * `4500.000000`, so parsing it to a JavaScript number would reintroduce the
 * float the backend spent `D-68` avoiding.
 *
 * ── The list has two filters and no search ────────────────────────────────
 *
 * `SupplierQuotationListCriteria::ALLOWED_FILTERS` is `['supplier_id',
 * 'deal_id']` and `ALLOWED_SORTS` is `['offer_date', 'created_at']`, both
 * closed sets, and `OpenAPI §6.2` answers anything else with a **400**. There
 * is deliberately no `q` here: this list declares no search, and offering one
 * would produce a runtime 400 rather than a missing feature.
 *
 * ── No delete, at any permission ──────────────────────────────────────────
 *
 * §3.6 seeds no `delete` grant and `DB-01` forbids removing business data, so
 * the server publishes no such route and this file offers no such call.
 */

export type { Pagination } from '@/api';

/** §7.2's header fields, as `SupplierQuotationPayload::of()` serialises them. */
export interface SupplierQuotation {
    id: string;
    code: string;
    supplier_id: string;
    deal_id: string | null;
    total_price: string | null;
    currency_id: string | null;
    offer_date: string | null;
    valid_until: string | null;
    notes: string | null;
}

/** A line as the detail returns it. There is no line `id` on the wire, by design. */
export interface SupplierQuotationLine {
    catalog_item_id: string;
    unit_price: string;
    quantity: string;
}

/** The detail adds the lines the summary omits. */
export interface SupplierQuotationDetail extends SupplierQuotation {
    items: SupplierQuotationLine[];
}

/** §17's `pdf_file`, as the upload reports it. No `storage_path` — the server never sends one. */
export interface SupplierQuotationDocument {
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

export interface SupplierQuotationListQuery {
    page?: number;
    perPage?: number;
    sort?: string | null;
    supplierId?: string | null;
    dealId?: string | null;
}

/**
 * What a write may carry. `code` is absent on purpose — §7.2 marks it
 * "Automatic" and the server answers a caller-supplied one with a 422, so a
 * field here would only let the UI build a request that cannot succeed.
 *
 * A line names its product by `catalog_item_id` **or** `product_name`, never
 * both (`D-22`, the owner's ruling of 2026-09-03); the server refuses the pair.
 */
export interface SupplierQuotationLineDraft {
    catalog_item_id?: string;
    product_name?: string;
    unit_price: string;
    quantity: string;
}

export interface SupplierQuotationDraft {
    supplier_id?: string;
    deal_id?: string | null;
    total_price?: string | null;
    currency_id?: string | null;
    offer_date?: string | null;
    valid_until?: string | null;
    notes?: string | null;
    items?: SupplierQuotationLineDraft[];
}

export async function listSupplierQuotations(query: SupplierQuotationListQuery = {}): Promise<Page<SupplierQuotation>> {
    const parameters = new URLSearchParams();

    if (query.page !== undefined) {
        parameters.set('page', String(query.page));
    }

    if (query.perPage !== undefined) {
        parameters.set('per_page', String(query.perPage));
    }

    for (const [key, value] of [
        ['sort', query.sort],
        ['filter[supplier_id]', query.supplierId],
        ['filter[deal_id]', query.dealId],
    ] as const) {
        // An unset filter is omitted, never sent empty: `filter[deal_id]=` asks
        // for offers whose deal is the empty string, which is a different
        // question from "any deal" and is not a UUID the server will accept.
        if (typeof value === 'string' && value !== '') {
            parameters.set(key, value);
        }
    }

    const suffix = parameters.size === 0 ? '' : `?${parameters.toString()}`;

    return collection<SupplierQuotation>(await apiGet(`/supplier-quotations${suffix}`));
}

export async function readSupplierQuotation(id: string): Promise<SupplierQuotationDetail> {
    return (await apiGet<SupplierQuotationDetail>(`/supplier-quotations/${id}`)).data;
}

export async function createSupplierQuotation(draft: SupplierQuotationDraft): Promise<SupplierQuotation> {
    return (await apiPost<SupplierQuotation>('/supplier-quotations', draft)).data;
}

/**
 * `items` present replaces the whole set, absent leaves the lines alone, `[]`
 * clears them — the owner's ruling of 2026-09-02, enforced on the server. This
 * client passes the draft through and decides none of it.
 */
export async function updateSupplierQuotation(id: string, draft: SupplierQuotationDraft): Promise<SupplierQuotation> {
    return (await apiPatch<SupplierQuotation>(`/supplier-quotations/${id}`, draft)).data;
}

/**
 * §17's upload. The field name is `document`, which is also the field the 422
 * names, so a rejection can be shown against the control the person used.
 *
 * No client-side type or size check: §17 decides the type from the **bytes**,
 * and a `mimes`-style guess here would disagree with the one check that reads
 * the file. Let the server refuse, and render its reason.
 */
export async function attachSupplierQuotationDocument(id: string, file: File): Promise<SupplierQuotationDocument> {
    const form = new FormData();
    form.append('document', file);

    return (await apiUpload<SupplierQuotationDocument>(`/supplier-quotations/${id}/documents`, form)).data;
}
