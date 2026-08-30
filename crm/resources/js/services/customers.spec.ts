import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    archiveCustomer,
    assignCustomer,
    createCustomer,
    importCustomers,
    listCustomers,
    readCustomer,
    restoreCustomer,
    updateCustomer,
} from '@/services/customers';

/**
 * Module 3, Point 4.0 — the API catalogue for the eight endpoints Step 3 built.
 *
 * ── What this suite is, and is not ─────────────────────────────────────────
 *
 * It proves the client sends what the server documented: the path, the verb,
 * the query shape, and the body. It proves **no authorization**: §3.12 rule 1
 * and `SEC-09` put that at the API, and `CustomerListEndpointTest` and its five
 * siblings already prove it there against the seeded matrix.
 *
 * ── The query shape is the server's, not a convenience ─────────────────────
 *
 * `OpenAPI §6.2` refuses an unknown filter with a 400, and
 * `CustomerListCriteria::ALLOWED_FILTERS` is the closed set. So an unset filter
 * is **omitted** rather than sent empty — a `filter[sector]=` would be a
 * request for customers whose sector is the empty string.
 */

function json(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

const CUSTOMER = {
    id: '0192f000-0000-7000-8000-000000000001',
    name: 'Alpha Trading',
    customer_status: 'prospect',
    sector: 'Medical',
    region: 'Cairo',
    contact_person: 'Ahmed',
    phone: '0100',
    phone2: null,
    whatsapp: null,
    email: null,
    sales_owner_id: null,
    start_date: null,
    notes: null,
    is_archived: false,
    is_incomplete: false,
    created_at: '2026-08-30T00:00:00+00:00',
    updated_at: '2026-08-30T00:00:00+00:00',
};

const PAGINATION = {
    page: 1,
    per_page: 25,
    total: 1,
    total_pages: 1,
    has_next_page: false,
    has_previous_page: false,
};

function calledWith(fetchMock: ReturnType<typeof vi.fn>): { url: string; init: RequestInit } {
    const call = fetchMock.mock.calls[0];

    expect(call).toBeDefined();

    return { url: String(call?.[0]), init: (call?.[1] ?? {}) as RequestInit };
}

beforeEach(() => {
    vi.restoreAllMocks();
});

describe('listCustomers', () => {
    it('reads the collection envelope and its pagination block', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => json(200, { data: [CUSTOMER], meta: { pagination: PAGINATION } })));

        const page = await listCustomers();

        expect(page.items).toHaveLength(1);
        expect(page.items[0]?.name).toBe('Alpha Trading');
        expect(page.pagination.total).toBe(1);
    });

    it('sends no query at all when nothing is asked for', async () => {
        const fetchMock = vi.fn(async () => json(200, { data: [], meta: { pagination: PAGINATION } }));
        vi.stubGlobal('fetch', fetchMock);

        await listCustomers();

        expect(calledWith(fetchMock).url).toBe('/api/v1/customers');
    });

    /** `CustomerListCriteria::ALLOWED_FILTERS`, spelled the way §6.2 expects them. */
    it('sends every filter the server declares, and only when it is set', async () => {
        const fetchMock = vi.fn(async () => json(200, { data: [], meta: { pagination: PAGINATION } }));
        vi.stubGlobal('fetch', fetchMock);

        await listCustomers({
            page: 2,
            perPage: 50,
            q: 'أحمد',
            sort: 'name',
            customerStatus: 'prospect',
            sector: 'Medical',
            isArchived: true,
            isIncomplete: false,
            ownerInactive: true,
        });

        const url = new URL(calledWith(fetchMock).url, 'http://localhost');

        expect(url.pathname).toBe('/api/v1/customers');
        expect(url.searchParams.get('page')).toBe('2');
        expect(url.searchParams.get('per_page')).toBe('50');
        expect(url.searchParams.get('q')).toBe('أحمد');
        expect(url.searchParams.get('sort')).toBe('name');
        expect(url.searchParams.get('filter[customer_status]')).toBe('prospect');
        expect(url.searchParams.get('filter[sector]')).toBe('Medical');
        expect(url.searchParams.get('filter[is_archived]')).toBe('true');
        expect(url.searchParams.get('filter[is_incomplete]')).toBe('false');
        expect(url.searchParams.get('filter[owner_inactive]')).toBe('true');
    });

    it('omits a filter that was not asked for rather than sending it empty', async () => {
        const fetchMock = vi.fn(async () => json(200, { data: [], meta: { pagination: PAGINATION } }));
        vi.stubGlobal('fetch', fetchMock);

        // `null` is the case that matters and the one this test first missed:
        // `false` is a filter ("only the unarchived") and `null` is the absence
        // of one. A client that sent `filter[is_archived]=false` for `null`
        // would quietly hide every archived row from a screen that asked for
        // both — and the earlier version of this test, which passed only
        // `sector` and `q`, could not tell the two apart.
        await listCustomers({ sector: null, q: '', isArchived: null, isIncomplete: null, ownerInactive: null });

        expect(calledWith(fetchMock).url).toBe('/api/v1/customers');
    });

    it('sends a false filter, because false is a filter and null is not one', async () => {
        const fetchMock = vi.fn(async () => json(200, { data: [], meta: { pagination: PAGINATION } }));
        vi.stubGlobal('fetch', fetchMock);

        await listCustomers({ isArchived: false });

        const url = new URL(calledWith(fetchMock).url, 'http://localhost');

        expect(url.searchParams.get('filter[is_archived]')).toBe('false');
    });
});

describe('the single-record calls', () => {
    it('reads one customer', async () => {
        const fetchMock = vi.fn(async () => json(200, { data: CUSTOMER, meta: {} }));
        vi.stubGlobal('fetch', fetchMock);

        const customer = await readCustomer(CUSTOMER.id);

        expect(calledWith(fetchMock).url).toBe(`/api/v1/customers/${CUSTOMER.id}`);
        expect(customer.id).toBe(CUSTOMER.id);
    });

    it('creates with POST and reports no warning when the server sent none', async () => {
        const fetchMock = vi.fn(async () => json(201, { data: CUSTOMER, meta: {} }));
        vi.stubGlobal('fetch', fetchMock);

        const result = await createCustomer({ name: 'Alpha Trading' });
        const { url, init } = calledWith(fetchMock);

        expect(url).toBe('/api/v1/customers');
        expect(init.method).toBe('POST');
        expect(result.similar).toEqual([]);
    });

    /**
     * `D-35`'s yellow warning travels in `meta.similar_customers` — the key is
     * absent rather than an empty array when nothing is similar, which is why
     * the caller is handed `[]` either way and never has to tell the two apart.
     */
    it('carries D-35 similar customers off the meta block when the server sent them', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => json(201, {
            data: CUSTOMER,
            meta: { similar_customers: [{ ...CUSTOMER, id: 'other', name: 'أحمد للتجارة' }] },
        })));

        const result = await createCustomer({ name: 'احمد للتجاره' });

        expect(result.similar).toHaveLength(1);
        expect(result.similar[0]?.name).toBe('أحمد للتجارة');
    });

    it('updates with PATCH', async () => {
        const fetchMock = vi.fn(async () => json(200, { data: CUSTOMER, meta: {} }));
        vi.stubGlobal('fetch', fetchMock);

        await updateCustomer(CUSTOMER.id, { region: 'Giza' });
        const { url, init } = calledWith(fetchMock);

        expect(url).toBe(`/api/v1/customers/${CUSTOMER.id}`);
        expect(init.method).toBe('PATCH');
        expect(init.body).toBe(JSON.stringify({ region: 'Giza' }));
    });
});

describe('the domain actions', () => {
    it('archives and restores through the two action suffixes', async () => {
        const archiveMock = vi.fn(async () => json(200, { data: { ...CUSTOMER, is_archived: true }, meta: {} }));
        vi.stubGlobal('fetch', archiveMock);
        await archiveCustomer(CUSTOMER.id);
        expect(calledWith(archiveMock).url).toBe(`/api/v1/customers/${CUSTOMER.id}/archive`);
        expect(calledWith(archiveMock).init.method).toBe('PATCH');

        const restoreMock = vi.fn(async () => json(200, { data: CUSTOMER, meta: {} }));
        vi.stubGlobal('fetch', restoreMock);
        await restoreCustomer(CUSTOMER.id);
        expect(calledWith(restoreMock).url).toBe(`/api/v1/customers/${CUSTOMER.id}/restore`);
    });

    /** Flow 10. `sales_owner_id` is required — §3.3 has no unassign row. */
    it('assigns through its own route and its own body', async () => {
        const fetchMock = vi.fn(async () => json(200, { data: CUSTOMER, meta: {} }));
        vi.stubGlobal('fetch', fetchMock);

        await assignCustomer(CUSTOMER.id, 'owner-1');
        const { url, init } = calledWith(fetchMock);

        expect(url).toBe(`/api/v1/customers/${CUSTOMER.id}/assign`);
        expect(init.method).toBe('PATCH');
        expect(init.body).toBe(JSON.stringify({ sales_owner_id: 'owner-1' }));
    });
});

describe('importCustomers', () => {
    /**
     * The one call that is not JSON. `request()` in `api.ts` stringifies every
     * body and pins `Content-Type: application/json`, which would turn a file
     * into `{}` — measured, not assumed. The upload helper omits the header so
     * the browser writes the multipart boundary itself.
     */
    it('posts the file as multipart and lets the browser set the content type', async () => {
        const fetchMock = vi.fn(async () => json(201, {
            data: { id: 'b1', original_filename: 'customers.csv', row_count: 2, imported_count: 2, incomplete_count: 1 },
            meta: {},
        }));
        vi.stubGlobal('fetch', fetchMock);

        const batch = await importCustomers(new File(['name\nAlpha'], 'customers.csv', { type: 'text/csv' }));
        const { url, init } = calledWith(fetchMock);

        expect(url).toBe('/api/v1/customers/import');
        expect(init.method).toBe('POST');
        expect(init.body).toBeInstanceOf(FormData);
        expect((init.body as FormData).get('file')).toBeInstanceOf(File);
        expect((init.headers as Record<string, string>)['Content-Type']).toBeUndefined();
        expect(batch.imported_count).toBe(2);
    });
});
