import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/api';
import {
    createQuotation,
    createQuotationVersion,
    deleteQuotation,
    listQuotationGroups,
    listQuotations,
    readQuotation,
    submitQuotation,
    updateQuotation,
} from '@/services/quotations';

/**
 * Module 7, Point 6.1 — the client for the seven routes Steps 3–5 built.
 *
 * It proves the client sends what the server documented: the path, the verb,
 * the query shape, the body and — new to this SPA — the two concurrency
 * headers. `If-Match` is `API-12`'s and `Idempotency-Key` is `OpenAPI §9.1`'s;
 * before this point `request()` could send neither. It proves **no
 * authorization**: §3.12 puts that at the API, and `tests/Feature/Quotations`
 * proves it there.
 *
 * The query shape is `QuotationListCriteria`'s, exactly: ten filters, five
 * sorts, two groups. An unset filter is omitted rather than sent empty,
 * because `filter[status]=` asks a different question and `OpenAPI §6.2`
 * answers an unknown shape with a 400.
 */

function json(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

const SUMMARY = {
    id: '0192f000-0000-7000-8000-000000000701',
    code: 'QT-2026-0001',
    status: 'draft',
    customer_id: '0192f000-0000-7000-8000-000000000101',
    deal_id: '0192f000-0000-7000-8000-000000000501',
    currency_id: '0192f000-0000-7000-8000-000000000001',
    final_total: '1235.00',
    quotation_date: '2026-09-13',
    valid_until: null,
    submitted_at: null,
    version: 1,
    parent_id: null,
    created_at: '2026-09-13T09:00:00+00:00',
    updated_at: '2026-09-13T09:00:00+00:00',
};

const DETAIL = {
    ...SUMMARY,
    discount_percent: '0.00',
    tax_percent: null,
    rounding_unit: '1.00',
    rounding_enabled: true,
    subtotal: '1234.67',
    additional_total: '0.00',
    discount_amount: '0.00',
    tax_base: '1234.67',
    tax_amount: null,
    net_amount: '1234.67',
    total_before_round: '1234.67',
    final_total: '1235.00',
    rounding_diff: '0.33',
    payment_terms: null,
    warranty: null,
    delivery_terms: null,
    show_delivery_terms: false,
    rejection_reason: null,
    sent_at: null,
    is_self_approved: false,
    etag: 'quotation:0192f000-0000-7000-8000-000000000701:1',
    items: [],
    additional_items: [],
    created_by: '0192f000-0000-7000-8000-000000000901',
    updated_by: '0192f000-0000-7000-8000-000000000901',
};

const PAGINATION = {
    page: 1,
    per_page: 25,
    total: 1,
    total_pages: 1,
    has_next_page: false,
    has_previous_page: false,
};

const DRAFT = {
    deal_id: SUMMARY.deal_id,
    customer_id: SUMMARY.customer_id,
    currency: 'EGP',
    default_margin: '20',
    discount_percent: '0',
    tax_percent: null,
    lines: [{ supplier_quotation_item_id: '0192f000-0000-7000-8000-000000000601', quantity: '2', margin_percent: null }],
    additional_items: [],
};

function envelope(data: unknown, extra: Record<string, unknown> = {}): unknown {
    return { data, meta: { request_id: 'req-1', ...extra } };
}

describe('the quotation API catalogue', () => {
    let fetchMock: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
    });

    function requestFor(call = 0): Request {
        const [input, init] = fetchMock.mock.calls[call] as [string, RequestInit];

        return new Request(input, init);
    }

    async function bodyOf(call = 0): Promise<unknown> {
        return await requestFor(call).json();
    }

    // ───────────────────────────────────────────────────────────────── the list

    it('asks for the collection with no query when none is given', async () => {
        fetchMock.mockResolvedValue(json(200, envelope([SUMMARY], { pagination: PAGINATION })));

        const page = await listQuotations();

        expect(requestFor().url).toContain('/quotations');
        expect(requestFor().url).not.toContain('?');
        expect(page.items[0]?.code).toBe('QT-2026-0001');
        expect(page.pagination.total).toBe(1);
    });

    it('sends the ten filters the server declares, and omits the unset ones', async () => {
        fetchMock.mockResolvedValue(json(200, envelope([], { pagination: PAGINATION })));

        await listQuotations({
            page: 2,
            perPage: 50,
            sort: '-final_total',
            status: 'pending',
            bucket: 'active',
            employee: '0192f000-0000-7000-8000-000000000901',
            customerId: '',
            dealId: null,
            currency: 'EGP',
            amountMin: '100',
            amountMax: '',
            from: '2026-09-01',
            to: null,
        });

        const url = requestFor().url;

        expect(url).toContain('page=2');
        expect(url).toContain('per_page=50');
        expect(url).toContain('sort=-final_total');
        expect(url).toContain('filter%5Bstatus%5D=pending');
        expect(url).toContain('filter%5Bbucket%5D=active');
        expect(url).toContain('filter%5Bemployee%5D=0192f000');
        expect(url).toContain('filter%5Bcurrency%5D=EGP');
        expect(url).toContain('filter%5Bamount_min%5D=100');
        expect(url).toContain('filter%5Bfrom%5D=2026-09-01');
        // Null and empty are both omitted.
        expect(url).not.toContain('customer_id');
        expect(url).not.toContain('deal_id');
        expect(url).not.toContain('amount_max');
        expect(url).not.toContain('filter%5Bto%5D');
        expect(url).not.toContain('group_by');
    });

    it('asks for the grouped shape with group_by and reads the groups back', async () => {
        fetchMock.mockResolvedValue(
            json(
                200,
                envelope(
                    [{ key: null, label: 'Unassigned', count: 1, items: [SUMMARY] }],
                    { pagination: PAGINATION },
                ),
            ),
        );

        const page = await listQuotationGroups('employee', { bucket: 'history' });

        expect(requestFor().url).toContain('group_by=employee');
        expect(requestFor().url).toContain('filter%5Bbucket%5D=history');
        expect(page.groups[0]?.key).toBeNull();
        expect(page.groups[0]?.items[0]?.id).toBe(SUMMARY.id);
        expect(page.pagination.per_page).toBe(25);
    });

    // ──────────────────────────────────────────────────────────────── the read

    it('reads one quotation and its price-drift warnings', async () => {
        fetchMock.mockResolvedValue(
            json(200, envelope(DETAIL, { warnings: [{ field: 'lines.1.unit_cost', code: 'supplier_price_changed', message: 'moved' }] })),
        );

        const read = await readQuotation(DETAIL.id);

        expect(requestFor().url).toContain(`/quotations/${DETAIL.id}`);
        expect(read.quotation.etag).toBe(DETAIL.etag);
        expect(read.warnings).toEqual([{ field: 'lines.1.unit_cost', code: 'supplier_price_changed', message: 'moved' }]);
    });

    it('reads no warnings as an empty list, not undefined', async () => {
        fetchMock.mockResolvedValue(json(200, envelope(DETAIL)));

        expect((await readQuotation(DETAIL.id)).warnings).toEqual([]);
    });

    // ─────────────────────────────────────────────────────────────── the writes

    it('creates with the Idempotency-Key the caller minted and returns the quantity warnings', async () => {
        fetchMock.mockResolvedValue(
            json(201, envelope(DETAIL, { warnings: [{ field: 'lines.1.quantity', code: 'quantity_exceeds_recorded', message: 'over' }] })),
        );

        const created = await createQuotation(DRAFT, 'key-1');

        expect(requestFor().method).toBe('POST');
        expect(requestFor().url).toContain('/quotations');
        expect(requestFor().headers.get('Idempotency-Key')).toBe('key-1');
        expect(requestFor().headers.get('Content-Type')).toBe('application/json');
        expect(await bodyOf()).toEqual(DRAFT);
        expect(created.warnings[0]?.code).toBe('quantity_exceeds_recorded');
    });

    it('updates with If-Match carrying the etag it was given', async () => {
        fetchMock.mockResolvedValue(json(200, envelope(DETAIL)));

        const { deal_id: _deal, customer_id: _customer, ...update } = DRAFT;
        await updateQuotation(DETAIL.id, DETAIL.etag, update);

        expect(requestFor().method).toBe('PATCH');
        expect(requestFor().url).toContain(`/quotations/${DETAIL.id}`);
        expect(requestFor().headers.get('If-Match')).toBe(DETAIL.etag);
        expect(await bodyOf()).toEqual(update);
    });

    it('submits for approval with If-Match and no body', async () => {
        fetchMock.mockResolvedValue(json(200, envelope({ ...DETAIL, status: 'pending' })));

        const submitted = await submitQuotation(DETAIL.id, DETAIL.etag);

        expect(requestFor().method).toBe('PATCH');
        expect(requestFor().url).toContain(`/quotations/${DETAIL.id}/submit-for-approval`);
        expect(requestFor().headers.get('If-Match')).toBe(DETAIL.etag);
        expect(requestFor().headers.get('Content-Type')).toBeNull();
        expect(submitted.status).toBe('pending');
    });

    it('creates a new version with Idempotency-Key and reads the copy back', async () => {
        fetchMock.mockResolvedValue(json(201, envelope({ id: 'copy', code: 'QT-2026-0001', version: 2 })));

        const copy = await createQuotationVersion(DETAIL.id, 'key-2');

        expect(requestFor().method).toBe('POST');
        expect(requestFor().url).toContain(`/quotations/${DETAIL.id}/new-version`);
        expect(requestFor().headers.get('Idempotency-Key')).toBe('key-2');
        expect(copy).toEqual({ id: 'copy', code: 'QT-2026-0001', version: 2 });
    });

    it('deletes with If-Match and survives the 204 that has no body', async () => {
        fetchMock.mockResolvedValue(new Response(null, { status: 204, headers: { 'X-Request-Id': 'req-9' } }));

        await expect(deleteQuotation(DETAIL.id, DETAIL.etag)).resolves.toBeUndefined();

        expect(requestFor().method).toBe('DELETE');
        expect(requestFor().url).toContain(`/quotations/${DETAIL.id}`);
        expect(requestFor().headers.get('If-Match')).toBe(DETAIL.etag);
    });

    it('surfaces a stale If-Match as the 409 ApiError the server sent', async () => {
        fetchMock.mockResolvedValue(
            json(409, { error: { code: 'stale_version', message: 'changed', details: [] }, meta: { request_id: 'req-2' } }),
        );

        const failure = await submitQuotation(DETAIL.id, 'quotation:x:1').catch((error: unknown) => error);

        expect(failure).toBeInstanceOf(ApiError);
        expect((failure as ApiError).status).toBe(409);
        expect((failure as ApiError).code).toBe('stale_version');
    });
});
