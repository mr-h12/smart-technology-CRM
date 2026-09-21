import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createSupplier, importSuppliers, listSuppliers, readSupplier, updateSupplier } from '@/services/suppliers';

/**
 * Module 4, Point 4.0 — the API catalogue for the four routes Step 2 built.
 *
 * ── What this suite is, and is not ─────────────────────────────────────────
 *
 * It proves the client sends what the server documented: the path, the verb,
 * the query shape and the body. It proves **no authorization**: §3.12 rule 1
 * and `SEC-09` put that at the API, and `SupplierListEndpointTest` and
 * `SupplierWriteEndpointTest` already prove it there against the seeded matrix.
 *
 * ── The query shape is `SupplierListCriteria`'s, not a convenience ─────────
 *
 * `OpenAPI §6.2` answers an unknown filter with a 400 and `ALLOWED_FILTERS` is
 * a closed set. So an unset filter is **omitted** rather than sent empty:
 * `filter[type]=` asks for suppliers whose type is the empty string, which is a
 * different question from "any type".
 */

function json(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

const SUPPLIER = {
    id: '0192f000-0000-7000-8000-000000000101',
    name: 'Alpha Supply',
    type: 'supplier',
    color_rating: 'green',
    phone: '0100',
    contact_person: 'Sara',
    has_open_account: false,
    is_active: true,
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

describe('listSuppliers', () => {
    it('reads the collection envelope and its pagination block', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => json(200, { data: [SUPPLIER], meta: { pagination: PAGINATION } })));

        const page = await listSuppliers();

        expect(page.items).toHaveLength(1);
        expect(page.items[0]?.color_rating).toBe('green');
        expect(page.pagination.total).toBe(1);
    });

    it('sends no query at all when nothing was asked for', async () => {
        const fetchMock = vi.fn(async () => json(200, { data: [], meta: { pagination: PAGINATION } }));
        vi.stubGlobal('fetch', fetchMock);

        await listSuppliers();

        expect(calledWith(fetchMock).url).toContain('/suppliers');
        expect(calledWith(fetchMock).url).not.toContain('?');
    });

    it('names every filter the way the server declared it', async () => {
        const fetchMock = vi.fn(async () => json(200, { data: [], meta: { pagination: PAGINATION } }));
        vi.stubGlobal('fetch', fetchMock);

        await listSuppliers({ page: 2, perPage: 50, q: 'alpha', sort: '-name', colorRating: 'red', type: 'distributor' });

        const { url } = calledWith(fetchMock);

        expect(url).toContain('page=2');
        expect(url).toContain('per_page=50');
        expect(url).toContain('q=alpha');
        expect(url).toContain('sort=-name');
        expect(url).toContain(encodeURIComponent('filter[color_rating]'));
        expect(url).toContain('red');
        expect(url).toContain(encodeURIComponent('filter[type]'));
        expect(url).toContain('distributor');
    });

    it('omits an empty filter rather than sending it blank', async () => {
        const fetchMock = vi.fn(async () => json(200, { data: [], meta: { pagination: PAGINATION } }));
        vi.stubGlobal('fetch', fetchMock);

        await listSuppliers({ q: '', sort: '', colorRating: '', type: null });

        expect(calledWith(fetchMock).url).not.toContain('?');
    });

    it('treats the two boolean filters as tri-state: false is a question, absent is not', async () => {
        const fetchMock = vi.fn(async () => json(200, { data: [], meta: { pagination: PAGINATION } }));
        vi.stubGlobal('fetch', fetchMock);

        await listSuppliers({ isActive: false, hasOpenAccount: null });

        const { url } = calledWith(fetchMock);

        expect(url).toContain(encodeURIComponent('filter[is_active]'));
        expect(url).toContain('false');
        expect(url).not.toContain(encodeURIComponent('filter[has_open_account]'));
    });
});

describe('readSupplier', () => {
    it('reads one supplier off the single envelope', async () => {
        const fetchMock = vi.fn(async () => json(200, { data: SUPPLIER, meta: {} }));
        vi.stubGlobal('fetch', fetchMock);

        const supplier = await readSupplier(SUPPLIER.id);

        expect(supplier.name).toBe('Alpha Supply');
        expect(calledWith(fetchMock).url).toContain(`/suppliers/${SUPPLIER.id}`);
    });
});

/** F-09 · 1.5 (`D-85`) — the importer's flag, as a filter. */
describe('listSuppliers — filter[is_incomplete]', () => {
    it('asks for the flagged ones when told to, and says nothing about the flag otherwise', async () => {
        const fetchMock = vi.fn(async (_url: string) => json(200, { data: [], meta: { pagination: PAGINATION } }));
        vi.stubGlobal('fetch', fetchMock);

        await listSuppliers({ isIncomplete: true });
        await listSuppliers({ isIncomplete: null });

        expect(String(fetchMock.mock.calls[0]?.[0])).toContain(`${encodeURIComponent('filter[is_incomplete]')}=true`);
        expect(String(fetchMock.mock.calls[1]?.[0])).not.toContain(encodeURIComponent('filter[is_incomplete]'));
    });
});

/** F-09 · 1.5 — `POST /suppliers/import`, whose request validates a field named `file`. */
describe('importSuppliers', () => {
    it('posts the file as multipart under the field the server validates', async () => {
        const batch = { id: 'b1', original_filename: 'suppliers.csv', row_count: 3, imported_count: 2, incomplete_count: 1 };
        const fetchMock = vi.fn(async () => json(201, { data: batch, meta: {} }));
        vi.stubGlobal('fetch', fetchMock);

        const result = await importSuppliers(new File(['name\nAlpha Supply\n'], 'suppliers.csv', { type: 'text/csv' }));

        const { url, init } = calledWith(fetchMock);

        expect(url).toBe('/api/v1/suppliers/import');
        expect(init.method).toBe('POST');
        expect((init.body as FormData).get('file')).toBeInstanceOf(File);
        expect(result).toEqual(batch);
    });
});

describe('createSupplier', () => {
    it('posts the draft to the collection', async () => {
        const fetchMock = vi.fn(async () => json(201, { data: SUPPLIER, meta: {} }));
        vi.stubGlobal('fetch', fetchMock);

        await createSupplier({ name: 'Alpha Supply', color_rating: 'green' });

        const { url, init } = calledWith(fetchMock);

        expect(url).toContain('/suppliers');
        expect(init.method).toBe('POST');
        expect(JSON.parse(String(init.body))).toEqual({ name: 'Alpha Supply', color_rating: 'green' });
    });
});

describe('updateSupplier', () => {
    it('patches the member, because §3.7 has no action route for deactivation or colour', async () => {
        const fetchMock = vi.fn(async () => json(200, { data: SUPPLIER, meta: {} }));
        vi.stubGlobal('fetch', fetchMock);

        await updateSupplier(SUPPLIER.id, { is_active: false });

        const { url, init } = calledWith(fetchMock);

        expect(url).toContain(`/suppliers/${SUPPLIER.id}`);
        expect(url).not.toContain('deactivate');
        expect(init.method).toBe('PATCH');
        expect(JSON.parse(String(init.body))).toEqual({ is_active: false });
    });
});
