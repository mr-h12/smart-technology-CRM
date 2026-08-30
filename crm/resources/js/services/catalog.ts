import { apiGet, apiPatch, apiPost, collection, type Pagination } from '@/api';

/**
 * Module 4's four catalog routes, and nothing else.
 *
 * `D-67`: the SPA consumes `/api/v1` and never owns a calculation, a permission
 * decision or a state transition.
 *
 * ── The two tabs are a filter, not two endpoints ───────────────────────────
 *
 * Point 1.2 put §7.3's Product and Service in one table behind `kind`, so a tab
 * is `filter[kind]` and an unset one lists both. `group_by=company` is
 * `API-06`'s server-side grouping; `ALLOWED_GROUPS` is `['company']` and
 * `OpenAPI §6.2` answers anything else with a 400, so this file sends the
 * server's own name and never invents one. Grouping changes the **ordering,
 * not the envelope** — `data` is still the flat paginated list.
 *
 * ── No price, anywhere ─────────────────────────────────────────────────────
 *
 * §7.3 is "descriptive data only — no prices" and `D-21` puts price, cost and
 * margin on the supplier quotation. {@see CatalogItemDraft} has no such field,
 * and `SaveCatalogItemRequest` refuses all three with a 422 rather than
 * ignoring them.
 *
 * ── There is no deactivate call, and that is §3.7 ──────────────────────────
 *
 * `is_active` is a field on the row, so it travels through
 * {@see updateCatalogItem}. The server publishes no action route for it, and
 * no DELETE at any permission (§3.12 rule 3).
 */

export type { Pagination } from '@/api';

/** §7.3's fields, as `CatalogItemPayload` serialises them. Both tabs on one object, because both are one table. */
export interface CatalogItem {
    id: string;
    kind: string;
    name: string | null;
    product_code: string | null;
    category: string | null;
    unit: string | null;
    service_type: string | null;
    company: string | null;
    description: string | null;
    notes: string | null;
    is_active: boolean;
    created_at: string;
    updated_at: string;
}

export interface Page<T> {
    items: T[];
    pagination: Pagination;
}

/** The three filters, the sort, the search and the one declared group. */
export interface CatalogItemListQuery {
    page?: number;
    perPage?: number;
    q?: string | null;
    sort?: string | null;
    groupBy?: string | null;
    kind?: string | null;
    category?: string | null;
    isActive?: boolean | null;
}

/** §7.3's user-entered fields. A product needs `name` and `unit`, a service `service_type` — enforced at the API. */
export interface CatalogItemDraft {
    kind?: string;
    name?: string | null;
    product_code?: string | null;
    category?: string | null;
    unit?: string | null;
    service_type?: string | null;
    company?: string | null;
    description?: string | null;
    notes?: string | null;
    is_active?: boolean;
}

export async function listCatalogItems(query: CatalogItemListQuery = {}): Promise<Page<CatalogItem>> {
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
        ['group_by', query.groupBy],
        ['filter[kind]', query.kind],
        ['filter[category]', query.category],
    ] as const) {
        if (typeof value === 'string' && value !== '') {
            parameters.set(key, value);
        }
    }

    // Tri-state: `false` asks for the deactivated items, and absence asks for
    // both. §10.4 hides them from selection lists, not from this screen.
    if (typeof query.isActive === 'boolean') {
        parameters.set('filter[is_active]', query.isActive ? 'true' : 'false');
    }

    const suffix = parameters.size === 0 ? '' : `?${parameters.toString()}`;

    return collection<CatalogItem>(await apiGet(`/catalog-items${suffix}`));
}

export async function readCatalogItem(id: string): Promise<CatalogItem> {
    return (await apiGet<CatalogItem>(`/catalog-items/${id}`)).data;
}

export async function createCatalogItem(draft: CatalogItemDraft): Promise<CatalogItem> {
    return (await apiPost<CatalogItem>('/catalog-items', draft)).data;
}

/** Deactivation is an ordinary field here — §3.7 grants it under `edit`, and there is no DELETE. */
export async function updateCatalogItem(id: string, draft: CatalogItemDraft): Promise<CatalogItem> {
    return (await apiPatch<CatalogItem>(`/catalog-items/${id}`, draft)).data;
}
