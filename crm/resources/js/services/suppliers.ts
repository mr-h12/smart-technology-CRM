import { apiGet, apiPatch, apiPost, apiUpload, collection, type ImportBatch, type Pagination } from '@/api';

/**
 * Module 4's four supplier routes and F-09's import (`D-85`), and nothing else.
 *
 * `D-67`: the SPA consumes `/api/v1` and never owns a calculation, a permission
 * decision or a state transition. Every rule this file could be tempted to
 * restate — who may write, what a colour means for a quotation, which supplier
 * a person may see — is the server's, and is proved there.
 *
 * ── There is no deactivate call, and that is §3.7 ──────────────────────────
 *
 * §3.7's write row is one cell: "create · edit · deactivate · set colour".
 * `is_active` and `color_rating` are fields on the row, so both travel through
 * {@see updateSupplier} — the server publishes no action route for either, and
 * inventing one here would produce a 405 at runtime.
 *
 * ── An unset filter is omitted, not sent empty ─────────────────────────────
 *
 * `OpenAPI §6.2` answers an unknown filter with a 400 and `ALLOWED_FILTERS` is
 * closed. `filter[type]=` asks for suppliers whose type is the empty string,
 * which is a different question from "any type".
 */

export type { Pagination } from '@/api';

/** §7.1's fields, as `SupplierPayload` serialises them. */
export interface Supplier {
    id: string;
    name: string;
    type: string | null;
    color_rating: string;
    phone: string | null;
    contact_person: string | null;
    has_open_account: boolean;
    is_active: boolean;
    /** `D-85`/`D-31` — set by the importer only; a write that sends it is a 422. */
    is_incomplete: boolean;
    created_at: string;
    updated_at: string;
}

export interface Page<T> {
    items: T[];
    pagination: Pagination;
}

/** The five filters, the sort and the search — every one of them the server's own name. */
export interface SupplierListQuery {
    page?: number;
    perPage?: number;
    q?: string | null;
    sort?: string | null;
    colorRating?: string | null;
    type?: string | null;
    isActive?: boolean | null;
    hasOpenAccount?: boolean | null;
    isIncomplete?: boolean | null;
}

/** §7.1's user-entered fields. `linked_quotations` is refused with a 422 — Module 6 derives it. */
export interface SupplierDraft {
    name?: string;
    type?: string | null;
    color_rating?: string;
    phone?: string | null;
    contact_person?: string | null;
    has_open_account?: boolean;
    is_active?: boolean;
}

export async function listSuppliers(query: SupplierListQuery = {}): Promise<Page<Supplier>> {
    const parameters = new URLSearchParams();

    if (query.page !== undefined) {
        parameters.set('page', String(query.page));
    }

    if (query.perPage !== undefined) {
        parameters.set('per_page', String(query.perPage));
    }

    for (const [key, value] of [
        ['q', query.q],
        ['sort', query.sort],
        ['filter[color_rating]', query.colorRating],
        ['filter[type]', query.type],
    ] as const) {
        if (typeof value === 'string' && value !== '') {
            parameters.set(key, value);
        }
    }

    for (const [key, value] of [
        ['filter[is_active]', query.isActive],
        ['filter[has_open_account]', query.hasOpenAccount],
        ['filter[is_incomplete]', query.isIncomplete],
    ] as const) {
        // `false` is a filter and `null`/`undefined` are not one: both of these
        // are tri-state on the server, where unset lists both halves.
        if (typeof value === 'boolean') {
            parameters.set(key, value ? 'true' : 'false');
        }
    }

    const suffix = parameters.size === 0 ? '' : `?${parameters.toString()}`;

    return collection<Supplier>(await apiGet(`/suppliers${suffix}`));
}

export async function readSupplier(id: string): Promise<Supplier> {
    return (await apiGet<Supplier>(`/suppliers/${id}`)).data;
}

export async function createSupplier(draft: SupplierDraft): Promise<Supplier> {
    return (await apiPost<Supplier>('/suppliers', draft)).data;
}

/** `D-85` — the customers' import, on the suppliers' route. */
export async function importSuppliers(file: File): Promise<ImportBatch> {
    const form = new FormData();
    form.append('file', file);

    return (await apiUpload<ImportBatch>('/suppliers/import', form)).data;
}

/** Deactivation and the colour are ordinary fields here — §3.7 grants them under `edit`. */
export async function updateSupplier(id: string, draft: SupplierDraft): Promise<Supplier> {
    return (await apiPatch<Supplier>(`/suppliers/${id}`, draft)).data;
}
