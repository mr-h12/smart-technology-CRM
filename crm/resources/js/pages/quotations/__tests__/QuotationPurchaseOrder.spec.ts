import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import QuotationPurchaseOrder from '@/pages/quotations/QuotationPurchaseOrder.vue';
import { downloadFile } from '@/services/files';
import { useAuth, type AuthenticatedUser } from '@/stores/auth';

vi.mock('@/services/files', () => ({ downloadFile: vi.fn(async () => undefined) }));

/**
 * Module 10 · 3.3 — the purchase order on its quotation (Q-B: there is no PO
 * page of its own). The reference comes with the quotation; the files are
 * `GET /purchase-orders/{id}`'s `documents`, oldest first (2.3, A1); the upload
 * is `POST …/documents` under `quotation.record_customer_response` (Q10), and a
 * download goes through Storage's one route under `quotation.view`.
 */

function json(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

const REFERENCE = { id: 'po1', po_number: 'PO-2026-0001', customer_po_reference: '4500123987', po_date: '2026-09-20' };

const OLDER = { id: 'f1', original_name: 'order.pdf', mime_type: 'application/pdf', size_bytes: 10, scan_status: 'clean', created_at: '2026-09-20T09:00:00+00:00' };
const NEWER = { ...OLDER, id: 'f2', original_name: 'stamp.png', mime_type: 'image/png', created_at: '2026-09-21T09:00:00+00:00' };
const UPLOADED = { ...OLDER, id: 'f3', original_name: 'signed.pdf', scan_status: 'pending', created_at: '2026-09-23T09:00:00+00:00' };

const VIEWER: AuthenticatedUser = {
    id: 'u1',
    name: 'Test Procurement',
    email: 'procurement@example.test',
    role: { id: 'r1', slug: 'procurement', name: 'Procurement' },
    permissions: ['quotation.view.all'],
    is_active: true,
    unconditional_access: false,
};

const RECORDER: AuthenticatedUser = { ...VIEWER, permissions: ['quotation.view.own', 'quotation.record_customer_response.own'] };

function respond(options: { documents?: unknown[]; readStatus?: number; upload?: () => Response } = {}): ReturnType<typeof vi.fn> {
    const { documents = [OLDER, NEWER], readStatus = 200, upload = () => json(201, { data: UPLOADED, meta: { request_id: 'r1' } }) } = options;

    return vi.fn(async (_input: string, init?: RequestInit) => {
        if (init?.method === 'POST') {
            return upload();
        }

        return readStatus === 200
            ? json(200, { data: { ...REFERENCE, documents }, meta: { request_id: 'r1' } })
            : json(readStatus, { error: { code: 'forbidden', message: 'no' }, meta: { request_id: 'r1' } });
    });
}

async function render(fetchMock: ReturnType<typeof vi.fn>, profile: AuthenticatedUser = RECORDER) {
    vi.stubGlobal('fetch', (input: string, init?: RequestInit) =>
        /\/auth\/login$/.test(String(input))
            ? Promise.resolve(json(201, { data: { token: 'a'.repeat(64), token_type: 'Bearer', idle_timeout_seconds: 28800, user: profile } }))
            : (fetchMock as unknown as typeof globalThis.fetch)(input, init));
    await useAuth().login(profile.email, 'Passw0rd123');
    vi.stubGlobal('fetch', fetchMock);

    const i18n = createI18n({ legacy: false, locale: 'en', fallbackLocale: 'en', messages: { en, ar } });
    const wrapper = mount(QuotationPurchaseOrder, { props: { order: REFERENCE }, global: { plugins: [i18n] } });
    await flushPromises();

    return wrapper;
}

async function choose(wrapper: Awaited<ReturnType<typeof render>>, file: File): Promise<void> {
    const input = wrapper.find('[data-testid="purchase-order-file"]');
    Object.defineProperty(input.element, 'files', { value: [file], configurable: true });
    await input.trigger('change');
    await flushPromises();
}

describe('the purchase order on its quotation (Module 10 · 3.3)', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
        vi.mocked(downloadFile).mockClear();
        useAuth().forgetSession();
        window.localStorage.clear();
    });

    it('shows the PO’s numbers and date, and its files oldest first', async () => {
        const fetchMock = respond();
        const wrapper = await render(fetchMock);

        expect(String(fetchMock.mock.calls[0]?.[0])).toContain('/purchase-orders/po1');
        expect(wrapper.find('[data-testid="purchase-order-number"]').text()).toBe('PO-2026-0001');
        expect(wrapper.find('[data-testid="purchase-order-reference"]').text()).toBe('4500123987');
        expect(wrapper.find('[data-testid="purchase-order-date"]').text()).toBe('20/09/2026');
        expect(wrapper.findAll('[data-testid="purchase-order-document"]').map((row) => row.text())).toEqual([
            expect.stringContaining('order.pdf'),
            expect.stringContaining('stamp.png'),
        ]);
    });

    it('says when no file is attached yet', async () => {
        const wrapper = await render(respond({ documents: [] }));

        expect(wrapper.find('[data-testid="purchase-order-no-documents"]').exists()).toBe(true);
    });

    it('uploads under record_customer_response and lists the answer last, its scan as itself', async () => {
        const fetchMock = respond();
        const wrapper = await render(fetchMock);

        await choose(wrapper, new File(['x'], 'signed.pdf', { type: 'application/pdf' }));

        const upload = fetchMock.mock.calls.find((call) => (call[1] as RequestInit | undefined)?.method === 'POST');
        expect(String(upload?.[0])).toContain('/purchase-orders/po1/documents');
        const rows = wrapper.findAll('[data-testid="purchase-order-document"]');
        expect(rows.map((row) => row.text())).toEqual([
            expect.stringContaining('order.pdf'),
            expect.stringContaining('stamp.png'),
            expect.stringContaining('signed.pdf'),
        ]);
        // `SEC-15`: a file whose scan has not finished has no download yet.
        expect(rows[2]?.find('[data-testid="purchase-order-download"]').exists()).toBe(false);
        expect(rows[2]?.text()).toContain('The virus scan has not finished');
    });

    it('says a refused upload in words and keeps the list', async () => {
        const wrapper = await render(respond({ upload: () => json(422, { error: { code: 'validation_failed', message: 'no' }, meta: { request_id: 'r1' } }) }));

        await choose(wrapper, new File(['x'], 'virus.exe'));

        expect(wrapper.find('[data-testid="purchase-order-error"]').text()).toContain('The file was not accepted.');
        expect(wrapper.findAll('[data-testid="purchase-order-document"]')).toHaveLength(2);
    });

    it('offers no upload without the grant, and still downloads a clean file', async () => {
        const wrapper = await render(respond(), VIEWER);

        expect(wrapper.find('[data-testid="purchase-order-file"]').exists()).toBe(false);

        await wrapper.find('[data-testid="purchase-order-download"]').trigger('click');
        await flushPromises();

        expect(downloadFile).toHaveBeenCalledWith('f1', 'order.pdf');
    });

    it('contains a refusal or a fault on the files inside the block, the reference still shown', async () => {
        const refused = await render(respond({ readStatus: 403 }));
        expect(refused.find('[data-testid="purchase-order-number"]').text()).toBe('PO-2026-0001');
        expect(refused.find('[data-testid="permission-denied-state"]').exists()).toBe(true);

        const broken = await render(respond({ readStatus: 500 }));
        expect(broken.find('[data-testid="error-state"]').exists()).toBe(true);
    });
});
