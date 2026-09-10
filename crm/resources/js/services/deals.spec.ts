import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    approveDeal,
    assignDeal,
    attachDealDocument,
    changeDealStatus,
    createDeal,
    listDealTimeline,
    listDeals,
    readDeal,
    rejectDeal,
    updateDeal,
} from '@/services/deals';

/**
 * Module 5, Point 6.1 — the API catalogue for the ten routes Steps 2, 4 and 5
 * built, and nothing else.
 *
 * ── What this suite is, and is not ─────────────────────────────────────────
 *
 * It proves the client sends what the server documented: the path, the verb,
 * the query shape and the body. It proves **no authorization** — §3.12 rule 1
 * and `SEC-09` put that at the API, and the `tests/Feature/Deals` suites
 * already prove it there against the seeded matrix, including the 404 a Team
 * Leader gets on every row because `Team` has no mechanism.
 *
 * ── The query shape is `DealListCriteria`'s, exactly ───────────────────────
 *
 * Four filters, three sorts, and — unlike Module 6's list — a **real `q`**:
 * `SearchIndex::Deals` indexes `title`, §4.3's one free-text field. An unset
 * filter is omitted rather than sent empty, because `filter[status]=` asks a
 * different question and `OpenAPI §6.2` answers an unknown key with a 400.
 *
 * ── The timeline's contract is smaller, and stricter ───────────────────────
 *
 * `DealTimelineCriteria` declares `page` and `per_page` **only** and refuses
 * anything else with a 400 rather than ignoring it, so the client must never
 * be tempted into sending a sort or a filter it has no server for.
 */

function json(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

const DEAL = {
    id: '0192f000-0000-7000-8000-000000000501',
    code: 'DL-2026-0001',
    customer_id: '0192f000-0000-7000-8000-000000000101',
    title: 'Twelve pumps',
    source: 'employee_entry',
    service_type: 'product',
    status: 'lead',
    owner_id: '0192f000-0000-7000-8000-000000000901',
    approval_status: 'pending',
    rejection_reason: null,
    lost_reason: null,
    last_activity_at: '2026-09-08T09:00:00+00:00',
    created_at: '2026-09-08T09:00:00+00:00',
    updated_at: '2026-09-08T09:00:00+00:00',
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

describe('the deal API catalogue', () => {
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
        fetchMock.mockResolvedValue(json(200, envelope([DEAL], { pagination: PAGINATION })));

        const page = await listDeals();

        expect(requestFor().url).toContain('/deals');
        expect(requestFor().url).not.toContain('?');
        expect(page.items).toHaveLength(1);
        expect(page.items[0]?.code).toBe('DL-2026-0001');
        expect(page.pagination.total).toBe(1);
    });

    it('sends the four filters the server declares, and omits the unset ones', async () => {
        fetchMock.mockResolvedValue(json(200, envelope([], { pagination: PAGINATION })));

        await listDeals({
            page: 2,
            perPage: 50,
            sort: '-last_activity_at',
            status: 'negotiations',
            serviceType: 'product',
            source: null,
            approvalStatus: '',
        });

        const url = requestFor().url;

        expect(url).toContain('page=2');
        expect(url).toContain('per_page=50');
        expect(url).toContain('filter%5Bstatus%5D=negotiations');
        expect(url).toContain('filter%5Bservice_type%5D=product');
        expect(url).toContain('sort=-last_activity_at');
        // Null and empty are both omitted: an empty filter asks a different
        // question, and the server answers the unknown shape with a 400.
        expect(url).not.toContain('source');
        expect(url).not.toContain('approval_status');
    });

    it('does send a search, because this list declares one', async () => {
        fetchMock.mockResolvedValue(json(200, envelope([], { pagination: PAGINATION })));

        await listDeals({ q: 'pumps' });

        // The difference from Module 6's list: `SearchIndex::Deals` indexes
        // `title`, so `q` is a control with a server behind it.
        expect(requestFor().url).toContain('q=pumps');
    });

    it('omits an empty search rather than sending it', async () => {
        fetchMock.mockResolvedValue(json(200, envelope([], { pagination: PAGINATION })));

        await listDeals({ q: '' });

        expect(requestFor().url).not.toContain('q=');
    });

    // ─────────────────────────────────────────────────────────────── the detail

    it('reads one deal', async () => {
        fetchMock.mockResolvedValue(json(200, envelope(DEAL)));

        const deal = await readDeal(DEAL.id);

        expect(requestFor().url).toContain(`/deals/${DEAL.id}`);
        expect(requestFor().method).toBe('GET');
        expect(deal.status).toBe('lead');
    });

    // ───────────────────────────────────────────────────────────── the writes

    it('creates a deal with §4.3 fields the server accepts on a POST', async () => {
        fetchMock.mockResolvedValue(json(201, envelope(DEAL)));

        await createDeal({
            customer_id: DEAL.customer_id,
            owner_id: DEAL.owner_id,
            title: 'Twelve pumps',
            source: 'employee_entry',
            service_type: 'product',
        });

        expect(requestFor().method).toBe('POST');
        expect(await bodyOf()).toEqual({
            customer_id: DEAL.customer_id,
            owner_id: DEAL.owner_id,
            title: 'Twelve pumps',
            source: 'employee_entry',
            service_type: 'product',
        });
    });

    it('edits a deal without resending the fields a PATCH prohibits', async () => {
        fetchMock.mockResolvedValue(json(200, envelope(DEAL)));

        await updateDeal(DEAL.id, { title: 'Fourteen pumps' });

        const body = await bodyOf();

        expect(requestFor().method).toBe('PATCH');
        expect(body).toEqual({ title: 'Fourteen pumps' });
        // `customer_id` and `owner_id` are create-only and `code`/`status`/
        // `approval_status` are never caller-writable — all `prohibited`, a
        // 422 rather than a silent drop.
        for (const field of ['customer_id', 'owner_id', 'code', 'status', 'approval_status']) {
            expect(body).not.toHaveProperty(field);
        }
    });

    it('assigns an owner through its own route and its own field name', async () => {
        fetchMock.mockResolvedValue(json(200, envelope(DEAL)));

        await assignDeal(DEAL.id, DEAL.owner_id);

        expect(requestFor().url).toContain(`/deals/${DEAL.id}/assign`);
        expect(await bodyOf()).toEqual({ owner_id: DEAL.owner_id });
    });

    // ────────────────────────────────────────────────────────── Flow 3's decision

    it('approves with no body at all', async () => {
        fetchMock.mockResolvedValue(json(200, envelope({ ...DEAL, approval_status: 'approved' })));

        await approveDeal(DEAL.id);

        expect(requestFor().url).toContain(`/deals/${DEAL.id}/approve`);
        expect(requestFor().method).toBe('PATCH');
    });

    it('rejects with the mandatory reason', async () => {
        fetchMock.mockResolvedValue(json(200, envelope({ ...DEAL, approval_status: 'rejected' })));

        await rejectDeal(DEAL.id, 'Budget withdrawn');

        expect(requestFor().url).toContain(`/deals/${DEAL.id}/reject`);
        expect(await bodyOf()).toEqual({ reason: 'Budget withdrawn' });
    });

    // ─────────────────────────────────────────────────────────── §4.4's transition

    it('sends the reason only when the target is lost', async () => {
        fetchMock.mockResolvedValue(json(200, envelope(DEAL)));

        await changeDealStatus(DEAL.id, 'lost', 'Lost on price');

        expect(await bodyOf()).toEqual({ status: 'lost', reason: 'Lost on price' });
    });

    it('never sends a reason on any other status, because the server prohibits it', async () => {
        fetchMock.mockResolvedValue(json(200, envelope(DEAL)));

        // A null would be a 422 on every status but `lost` — `prohibited`
        // means absent, not empty.
        await changeDealStatus(DEAL.id, 'contacted', 'ignored');

        expect(await bodyOf()).toEqual({ status: 'contacted' });
    });

    // ───────────────────────────────────────────────────────────── §17's upload

    it('uploads under the form field name the error renderer hard-codes', async () => {
        fetchMock.mockResolvedValue(json(201, envelope({
            id: 'f1', original_name: 'offer.pdf', mime_type: 'application/pdf',
            size_bytes: 12, scan_status: 'pending', created_at: DEAL.created_at,
        })));

        const file = new File(['x'], 'offer.pdf', { type: 'application/pdf' });
        await attachDealDocument(DEAL.id, file);

        const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
        const form = init.body as FormData;

        expect(requestFor().url).toContain(`/deals/${DEAL.id}/documents`);
        // ⚠️ `ApiExceptionRenderer` hard-codes `'field' => 'document'`. A
        // rename 422s every upload *and* points the error at a control that
        // does not exist — Module 6 Point 6.1 found this by probe, with no
        // test asserting the key. Asserted here from the start.
        expect(form.get('document')).toBe(file);
        expect(form.get('file')).toBeNull();
    });

    // ─────────────────────────────────────────────────────────────── the timeline

    it('reads a timeline with the two parameters it declares', async () => {
        fetchMock.mockResolvedValue(json(200, envelope([{
            id: 'a1', event: 'DEAL_STATUS_CHANGED', old_status: 'lead', new_status: 'contacted',
            actor_id: DEAL.owner_id, impersonated_user_id: null, occurred_at: DEAL.created_at,
        }], { pagination: PAGINATION })));

        const page = await listDealTimeline(DEAL.id, { page: 2, perPage: 10 });

        const url = requestFor().url;

        expect(url).toContain(`/deals/${DEAL.id}/timeline`);
        expect(url).toContain('page=2');
        expect(url).toContain('per_page=10');
        expect(page.items[0]?.old_status).toBe('lead');
        expect(page.items[0]?.new_status).toBe('contacted');
    });

    it('never sends a sort, a search or an event filter to the timeline', async () => {
        fetchMock.mockResolvedValue(json(200, envelope([], { pagination: PAGINATION })));

        await listDealTimeline(DEAL.id);

        const url = requestFor().url;

        // `DealTimelineCriteria` refuses an undeclared parameter with a 400
        // rather than ignoring it, so each of these would be a runtime failure
        // rather than a missing feature.
        expect(url).not.toContain('sort');
        expect(url).not.toContain('q=');
        expect(url).not.toContain('filter');
        expect(url).not.toContain('?');
    });
});
