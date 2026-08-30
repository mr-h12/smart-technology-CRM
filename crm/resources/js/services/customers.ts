import { apiGet, apiPatch, apiPost, apiUpload, collection, type Pagination } from '@/api';

/**
 * Module 3's eight endpoints, and nothing else.
 *
 * `D-67`: the SPA consumes `/api/v1` and never owns a calculation, a permission
 * decision or a state transition. Every rule this file could be tempted to
 * restate — who may archive, which scope reaches which row, what makes a record
 * incomplete — is the server's, and is proved against the server by the six
 * `tests/Feature/Customers` suites.
 *
 * ── The query shape is `CustomerListCriteria`'s, not a convenience ─────────
 *
 * `OpenAPI §6.2` answers an unknown filter with a 400, and `ALLOWED_FILTERS` is
 * a closed set of five. So an unset filter is **omitted** rather than sent
 * empty: `filter[sector]=` asks for customers whose sector is the empty string,
 * which is a different question from "any sector".
 */

export type { Pagination } from '@/api';

/** §4.2's fields, as `CustomerPayload` serialises them. No owner name: it belongs to Identity. */
export interface Customer {
    id: string;
    name: string;
    customer_status: string;
    sector: string | null;
    region: string | null;
    contact_person: string | null;
    phone: string | null;
    phone2: string | null;
    whatsapp: string | null;
    email: string | null;
    sales_owner_id: string | null;
    start_date: string | null;
    notes: string | null;
    is_archived: boolean;
    is_incomplete: boolean;
    created_at: string;
    updated_at: string;
}

export interface Page<T> {
    items: T[];
    pagination: Pagination;
}

/** The five filters, the sort and the search — every one of them the server's own name. */
export interface CustomerListQuery {
    page?: number;
    perPage?: number;
    q?: string | null;
    sort?: string | null;
    customerStatus?: string | null;
    sector?: string | null;
    isArchived?: boolean | null;
    isIncomplete?: boolean | null;
    ownerInactive?: boolean | null;
}

/** §4.2's user-entered fields. `customer_status`, `is_archived` and `is_incomplete` are refused with a 422. */
export interface CustomerDraft {
    name?: string;
    sector?: string | null;
    region?: string | null;
    contact_person?: string | null;
    phone?: string | null;
    phone2?: string | null;
    whatsapp?: string | null;
    email?: string | null;
    start_date?: string | null;
    notes?: string | null;
    /** Create only. `PATCH` refuses it — §3.3 makes `assign` its own permission. */
    sales_owner_id?: string | null;
}

/** `D-35` — the record, and the yellow warning when the server raised one. */
export interface CustomerWritten {
    customer: Customer;
    similar: Customer[];
}

/** One `import_batches` row. Failures are `row_count - imported_count`; the server stores no fourth count. */
export interface ImportBatch {
    id: string;
    original_filename: string;
    row_count: number;
    imported_count: number;
    incomplete_count: number;
}

export async function listCustomers(query: CustomerListQuery = {}): Promise<Page<Customer>> {
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

    if (typeof query.customerStatus === 'string' && query.customerStatus !== '') {
        parameters.set('filter[customer_status]', query.customerStatus);
    }

    if (typeof query.sector === 'string' && query.sector !== '') {
        parameters.set('filter[sector]', query.sector);
    }

    for (const [key, value] of [
        ['filter[is_archived]', query.isArchived],
        ['filter[is_incomplete]', query.isIncomplete],
        ['filter[owner_inactive]', query.ownerInactive],
    ] as const) {
        // `false` is a filter and `null`/`undefined` are not one: §10.1's
        // "customers of deactivated employees" is asked as `true`, and its
        // absence is not `false` — it is "do not filter on this at all".
        if (typeof value === 'boolean') {
            parameters.set(key, value ? 'true' : 'false');
        }
    }

    const suffix = parameters.size === 0 ? '' : `?${parameters.toString()}`;

    return collection<Customer>(await apiGet(`/customers${suffix}`));
}

export async function readCustomer(id: string): Promise<Customer> {
    return (await apiGet<Customer>(`/customers/${id}`)).data;
}

export async function createCustomer(draft: CustomerDraft): Promise<CustomerWritten> {
    return written(await apiPost<Customer>('/customers', draft));
}

export async function updateCustomer(id: string, draft: CustomerDraft): Promise<CustomerWritten> {
    return written(await apiPatch<Customer>(`/customers/${id}`, draft));
}

/** Flow 7. Archive is a flag and never a delete (`DB-01`, §3.12 rule 3). */
export async function archiveCustomer(id: string): Promise<Customer> {
    return (await apiPatch<Customer>(`/customers/${id}/archive`)).data;
}

export async function restoreCustomer(id: string): Promise<Customer> {
    return (await apiPatch<Customer>(`/customers/${id}/restore`)).data;
}

/**
 * Flow 10. `salesOwnerId` is required, not nullable: §3.3 has no unassign row,
 * and an ownerless customer is `D-34`'s deactivation path rather than this one.
 */
export async function assignCustomer(id: string, salesOwnerId: string): Promise<Customer> {
    return (await apiPatch<Customer>(`/customers/${id}/assign`, { sales_owner_id: salesOwnerId })).data;
}

export async function importCustomers(file: File): Promise<ImportBatch> {
    const form = new FormData();
    form.append('file', file);

    return (await apiUpload<ImportBatch>('/customers/import', form)).data;
}

/**
 * `D-35`'s warning off the `meta` block, narrowed rather than trusted.
 *
 * The server omits the key when nothing is similar — deliberately, so a client
 * checking for truthiness and one checking for the key agree. Both arrive here
 * as `[]`, so no caller has to know that.
 */
function written(result: { data: Customer; meta: Record<string, unknown> }): CustomerWritten {
    const similar = result.meta.similar_customers;

    return {
        customer: result.data,
        similar: Array.isArray(similar) ? (similar as Customer[]) : [],
    };
}
