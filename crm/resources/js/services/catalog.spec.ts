import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createCatalogItem, importCatalogItems, listCatalogItems, readCatalogItem, updateCatalogItem } from '@/services/catalog';

/**
 * Module 4, Point 4.0 — the API catalogue for the four routes Step 3 built.
 *
 * It proves the client sends what the server documented, and **no
 * authorization**: §3.12 rule 1 puts that at the API, where
 * `CatalogItemListEndpointTest` and `CatalogItemWriteEndpointTest` prove it
 * against the seeded matrix.
 *
 * ── The two tabs are a filter, and grouping is a parameter ─────────────────
 *
 * Point 1.2 put §7.3's Product and Service in one table behind `kind`, so the
 * tab is `filter[kind]`. `group_by` is `API-06`'s server-side grouping and
 * `OpenAPI §6.2` answers an undeclared group with a 400 — `ALLOWED_GROUPS` is
 * `['company']` — so this file sends the server's own name and never invents
 * one.
 *
 * ── No price anywhere, asserted rather than assumed ────────────────────────
 *
 * §7.3 is "descriptive data only — no prices" and `D-21` puts price, cost and
 * margin on the supplier quotation, where `SaveCatalogItemRequest` refuses all
 * three with a 422. The draft type here has no such field, and the test below
 * pins that the write body carries nothing the caller did not name.
 */

function json(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

const ITEM = {
    id: '0192f000-0000-7000-8000-000000000201',
    kind: 'product',
    name: 'Copper Cable',
    product_code: 'CU-2',
    category: 'Wiring',
    unit: 'metre',
    service_type: null,
    company: 'Alpha Team',
    description: null,
    notes: null,
    is_active: true,
    is_incomplete: false,
    suppliers: [],
    created_at: '2026-08-31T00:00:00+00:00',
    updated_at: '2026-08-31T00:00:00+00:00',
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

describe('listCatalogItems', () => {
    it('reads the collection envelope and its pagination block', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => json(200, { data: [ITEM], meta: { pagination: PAGINATION } })));

        const page = await listCatalogItems();

        expect(page.items).toHaveLength(1);
        expect(page.items[0]?.kind).toBe('product');
        expect(page.pagination.total).toBe(1);
    });

    it('sends the tab as filter[kind], because §7.3 is two tabs of one table', async () => {
        const fetchMock = vi.fn(async () => json(200, { data: [], meta: { pagination: PAGINATION } }));
        vi.stubGlobal('fetch', fetchMock);

        await listCatalogItems({ kind: 'service' });

        const { url } = calledWith(fetchMock);

        expect(url).toContain(encodeURIComponent('filter[kind]'));
        expect(url).toContain('service');
        expect(url).not.toContain('/services');
    });

    it('sends group_by with the one group the server declares', async () => {
        const fetchMock = vi.fn(async () => json(200, { data: [], meta: { pagination: PAGINATION } }));
        vi.stubGlobal('fetch', fetchMock);

        await listCatalogItems({ groupBy: 'company' });

        expect(calledWith(fetchMock).url).toContain('group_by=company');
    });

    it('names every remaining filter the way the server declared it', async () => {
        const fetchMock = vi.fn(async () => json(200, { data: [], meta: { pagination: PAGINATION } }));
        vi.stubGlobal('fetch', fetchMock);

        await listCatalogItems({ page: 3, perPage: 10, q: 'cable', sort: '-created_at', category: 'Wiring' });

        const { url } = calledWith(fetchMock);

        expect(url).toContain('page=3');
        expect(url).toContain('per_page=10');
        expect(url).toContain('q=cable');
        expect(url).toContain('sort=-created_at');
        expect(url).toContain(encodeURIComponent('filter[category]'));
        expect(url).toContain('Wiring');
    });

    it('omits an empty filter rather than sending it blank', async () => {
        const fetchMock = vi.fn(async () => json(200, { data: [], meta: { pagination: PAGINATION } }));
        vi.stubGlobal('fetch', fetchMock);

        await listCatalogItems({ q: '', kind: null, category: '', groupBy: null });

        expect(calledWith(fetchMock).url).not.toContain('?');
    });

    it('treats is_active as tri-state: false is a question, absent is not', async () => {
        const fetchMock = vi.fn(async () => json(200, { data: [], meta: { pagination: PAGINATION } }));
        vi.stubGlobal('fetch', fetchMock);

        await listCatalogItems({ isActive: false });

        expect(calledWith(fetchMock).url).toContain(encodeURIComponent('filter[is_active]'));
        expect(calledWith(fetchMock).url).toContain('false');
    });
});

describe('readCatalogItem', () => {
    it('reads one item off the single envelope', async () => {
        const fetchMock = vi.fn(async () => json(200, { data: ITEM, meta: {} }));
        vi.stubGlobal('fetch', fetchMock);

        const item = await readCatalogItem(ITEM.id);

        expect(item.name).toBe('Copper Cable');
        expect(calledWith(fetchMock).url).toContain(`/catalog-items/${ITEM.id}`);
    });
});

describe('createCatalogItem', () => {
    it('posts the draft, and sends nothing the caller did not name', async () => {
        const fetchMock = vi.fn(async () => json(201, { data: ITEM, meta: {} }));
        vi.stubGlobal('fetch', fetchMock);

        await createCatalogItem({ kind: 'product', name: 'Copper Cable', unit: 'metre' });

        const { url, init } = calledWith(fetchMock);

        expect(url).toContain('/catalog-items');
        expect(init.method).toBe('POST');

        // §7.3 and `D-21`: no price, no cost, no margin — and no key the caller
        // did not write, since the server answers 422 to all three.
        expect(JSON.parse(String(init.body))).toEqual({ kind: 'product', name: 'Copper Cable', unit: 'metre' });
    });
});

describe('updateCatalogItem', () => {
    it('patches the member, because §3.7 has no deactivate action route', async () => {
        const fetchMock = vi.fn(async () => json(200, { data: ITEM, meta: {} }));
        vi.stubGlobal('fetch', fetchMock);

        await updateCatalogItem(ITEM.id, { is_active: false });

        const { url, init } = calledWith(fetchMock);

        expect(url).toContain(`/catalog-items/${ITEM.id}`);
        expect(url).not.toContain('deactivate');
        expect(init.method).toBe('PATCH');
        expect(JSON.parse(String(init.body))).toEqual({ is_active: false });
    });
});

/** `D-86` (F-10 · 1.8) — the import, the incomplete filter and the supplier set. */
describe('the catalog import and links', () => {
    it('posts the file as multipart under the field the server validates', async () => {
        const batch = { id: 'b1', original_filename: 'items.csv', row_count: 3, imported_count: 2, incomplete_count: 1 };
        const fetchMock = vi.fn(async () => json(201, { data: batch, meta: {} }));
        vi.stubGlobal('fetch', fetchMock);

        const result = await importCatalogItems(new File(['kind,name\nproduct,Cable\n'], 'items.csv', { type: 'text/csv' }));

        const { url, init } = calledWith(fetchMock);

        expect(url).toBe('/api/v1/catalog-items/import');
        expect(init.method).toBe('POST');
        expect((init.body as FormData).get('file')).toBeInstanceOf(File);
        expect(result).toEqual(batch);
    });

    it('sends filter[is_incomplete]=true when asked, and nothing about it otherwise', async () => {
        const fetchMock = vi.fn(async () => json(200, { data: [], meta: { pagination: PAGINATION } }));
        vi.stubGlobal('fetch', fetchMock);

        await listCatalogItems({});
        expect(calledWith(fetchMock).url).not.toContain(encodeURIComponent('filter[is_incomplete]'));

        await listCatalogItems({ isIncomplete: true });
        expect(String((fetchMock.mock.calls as unknown[][])[1]?.[0])).toContain(`${encodeURIComponent('filter[is_incomplete]')}=true`);
    });

    it('sends the supplier set as given, an empty set included', async () => {
        const fetchMock = vi.fn(async () => json(200, { data: { ...ITEM, is_incomplete: false, suppliers: [] }, meta: {} }));
        vi.stubGlobal('fetch', fetchMock);

        await updateCatalogItem(ITEM.id, { supplier_ids: [] });

        expect(JSON.parse(String(calledWith(fetchMock).init.body))).toEqual({ supplier_ids: [] });
    });
});
