import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    attachSupplierQuotationDocument,
    createSupplierQuotation,
    listSupplierQuotations,
    readSupplierQuotation,
    updateSupplierQuotation,
} from '@/services/supplier-quotations';

/**
 * Module 6, Point 6.1 — the API catalogue for the five routes Steps 2, 4 and 5
 * built, and nothing else.
 *
 * ── What this suite is, and is not ─────────────────────────────────────────
 *
 * It proves the client sends what the server documented: the path, the verb,
 * the query shape and the body. It proves **no authorization**: §3.12 rule 1
 * and `SEC-09` put that at the API, and the five endpoint suites already prove
 * it there against the seeded matrix — including the CEO's 403 on a write and
 * on an upload, which no client-side check may duplicate or contradict.
 *
 * ── The query shape is `SupplierQuotationListCriteria`'s, exactly ──────────
 *
 * `ALLOWED_FILTERS` is `['supplier_id', 'deal_id']` and `ALLOWED_SORTS` is
 * `['offer_date', 'created_at']`, both closed, and `OpenAPI §6.2` answers
 * anything else with a **400**. So there is no `q` here — this list has no
 * search — and an unset filter is omitted rather than sent empty.
 *
 * ── `total_price` is a string on the wire, and stays one ───────────────────
 *
 * `DB-07` forbids floating point anywhere near money, and `Precision::CAST_MONEY`
 * serialises `4500.000000`. Parsing it to a `number` here would reintroduce
 * the float the backend spent a decision avoiding, so the type is `string`.
 */

function json(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

const OFFER = {
    id: '0192f000-0000-7000-8000-000000000201',
    code: 'SQ-2026-0001',
    supplier_id: '0192f000-0000-7000-8000-000000000101',
    deal_id: null,
    total_price: '4500.000000',
    currency_id: '0192f000-0000-7000-8000-000000000301',
    offer_date: '2026-09-01',
    valid_until: null,
    notes: null,
};

const PAGINATION = {
    page: 1,
    per_page: 25,
    total: 1,
    total_pages: 1,
    has_next_page: false,
    has_previous_page: false,
};

function envelope(data: unknown, extra: Record<string, unknown> = {}): unknown {
    return { data, meta: { request_id: 'req-1', ...extra } };
}

describe('the supplier quotation API catalogue', () => {
    let fetchMock: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
    });

    function requestFor(call = 0): Request {
        const [input, init] = fetchMock.mock.calls[call] as [string, RequestInit];

        return new Request(input, init);
    }

    // ───────────────────────────────────────────────────────────── the list

    it('asks for the collection with no query when none is given', async () => {
        fetchMock.mockResolvedValue(json(200, envelope([OFFER], { pagination: PAGINATION })));

        const page = await listSupplierQuotations();

        expect(requestFor().url).toContain('/supplier-quotations');
        expect(requestFor().url).not.toContain('?');
        expect(page.items).toHaveLength(1);
        expect(page.pagination.total).toBe(1);
    });

    it('sends only the two filters the server declares', async () => {
        fetchMock.mockResolvedValue(json(200, envelope([], { pagination: PAGINATION })));

        await listSupplierQuotations({
            page: 2,
            perPage: 50,
            sort: '-offer_date',
            supplierId: OFFER.supplier_id,
            dealId: null,
        });

        const url = requestFor().url;

        expect(url).toContain('page=2');
        expect(url).toContain('per_page=50');
        expect(url).toContain(`filter%5Bsupplier_id%5D=${OFFER.supplier_id}`);
        // `deal_id` was null — omitted, not sent empty, because an empty filter
        // is a different question and `OpenAPI §6.2` would answer it with a 400.
        expect(url).not.toContain('deal_id');
        expect(url).toContain('sort=-offer_date');
    });

    it('never sends a search parameter, because this list declares none', async () => {
        fetchMock.mockResolvedValue(json(200, envelope([], { pagination: PAGINATION })));

        await listSupplierQuotations({ page: 1 });

        expect(requestFor().url).not.toContain('q=');
    });

    // ─────────────────────────────────────────────────────────── the detail

    it('reads one offer with its lines', async () => {
        fetchMock.mockResolvedValue(json(200, envelope({
            ...OFFER,
            items: [{ catalog_item_id: OFFER.currency_id, unit_price: '1500.000000', quantity: '3.000' }],
        })));

        const offer = await readSupplierQuotation(OFFER.id);

        expect(requestFor().url).toContain(`/supplier-quotations/${OFFER.id}`);
        expect(requestFor().method).toBe('GET');
        expect(offer.items).toHaveLength(1);
        expect(offer.total_price).toBe('4500.000000');
    });

    // ──────────────────────────────────────────────────────────── the writes

    it('creates with the body the server validates', async () => {
        fetchMock.mockResolvedValue(json(201, envelope(OFFER)));

        await createSupplierQuotation({ supplier_id: OFFER.supplier_id, total_price: '4500', currency_id: OFFER.currency_id });

        const request = requestFor();

        expect(request.method).toBe('POST');
        expect(request.url).toContain('/supplier-quotations');
        await expect(request.json()).resolves.toMatchObject({ supplier_id: OFFER.supplier_id });
    });

    it('edits with PATCH, on the route the server publishes', async () => {
        fetchMock.mockResolvedValue(json(200, envelope({ ...OFFER, notes: 'Revised' })));

        const offer = await updateSupplierQuotation(OFFER.id, { notes: 'Revised' });

        expect(requestFor().method).toBe('PATCH');
        expect(requestFor().url).toContain(`/supplier-quotations/${OFFER.id}`);
        expect(offer.notes).toBe('Revised');
    });

    /** §7.2 marks `code` "Automatic"; the server answers a caller-supplied one with a 422. */
    it('has no way to send a code, because the server refuses one', async () => {
        fetchMock.mockResolvedValue(json(201, envelope(OFFER)));

        await createSupplierQuotation({ supplier_id: OFFER.supplier_id });

        await expect(requestFor().json()).resolves.not.toHaveProperty('code');
    });

    // ─────────────────────────────────────────────────────────── the upload

    it('posts the document as multipart, on its own route', async () => {
        fetchMock.mockResolvedValue(json(201, envelope({
            id: 'file-1',
            original_name: 'offer.pdf',
            mime_type: 'application/pdf',
            size_bytes: 120,
            scan_status: 'clean',
            created_at: '2026-09-05T00:00:00+00:00',
        })));

        const file = new File(['%PDF-1.4'], 'offer.pdf', { type: 'application/pdf' });
        const document = await attachSupplierQuotationDocument(OFFER.id, file);

        const request = requestFor();

        expect(request.method).toBe('POST');
        expect(request.url).toContain(`/supplier-quotations/${OFFER.id}/documents`);

        // The field name is load-bearing twice over: the Form Request requires
        // `document`, and `ApiExceptionRenderer` hard-codes `'field' =>
        // 'document'` on a §17 rejection, so a rename would 422 every upload
        // *and* point the error at a control that does not exist. Asserted
        // because a probe proved nothing else here caught the rename.
        const sent = await request.formData();
        expect([...sent.keys()]).toEqual(['document']);
        expect(sent.get('document')).toBeInstanceOf(File);

        expect(document.scan_status).toBe('clean');
        expect(document).not.toHaveProperty('storage_path');
    });
});
