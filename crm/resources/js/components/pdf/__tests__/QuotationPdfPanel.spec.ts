import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import QuotationPdfPanel from '@/components/pdf/QuotationPdfPanel.vue';
import { downloadFile } from '@/services/files';
import { useAuth, type AuthenticatedUser } from '@/stores/auth';

vi.mock('@/services/files', () => ({ downloadFile: vi.fn(async () => undefined) }));

/**
 * Module 9 · 5.1 — the customer PDF on its quotation. *Generate* under
 * `quotation.generate_pdf` (3.5's `202`), a queued state that polls 4.2 until
 * `completed` or `failed` with the button disabled meanwhile (Q12), the
 * failure's reason, and *Download* under `quotation.export_pdf` through
 * Storage's one file route. The API decides every refusal (§3.5); the panel
 * only draws it.
 */

function json(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

function state(data: unknown): () => Response {
    return () => json(200, { data, meta: { request_id: 'r1' } });
}

function refusal(status: number): () => Response {
    return () => json(status, { error: { code: 'permission_denied', message: 'No.', details: [{ code: 'unauthorized_action', message: 'No.' }] }, meta: { request_id: 'r1' } });
}

const NONE = { generation: null, latest_file_id: null };
const QUEUED = {
    generation: { job_id: 'j2', status: 'queued', requested_at: '2026-09-28T09:00:00+00:00', completed_at: null, failure_reason: null },
    latest_file_id: null,
};
const COMPLETED = {
    generation: { ...QUEUED.generation, status: 'completed', completed_at: '2026-09-28T09:00:05+00:00' },
    latest_file_id: 'f2',
};
const FAILED_AFTER_A_GOOD_ONE = {
    generation: { ...QUEUED.generation, status: 'failed', failure_reason: 'The renderer timed out.' },
    latest_file_id: 'f1',
};

const MANAGER: AuthenticatedUser = {
    id: 'u1',
    name: 'Test Manager',
    email: 'manager@example.test',
    role: { id: 'r1', slug: 'manager', name: 'Manager' },
    permissions: ['quotation.view.all', 'quotation.generate_pdf.all', 'quotation.export_pdf.all'],
    is_active: true,
    unconditional_access: false,
};

/** §3.5: the CEO exports an existing PDF and holds no grant to generate one. */
const CEO: AuthenticatedUser = { ...MANAGER, role: { id: 'r2', slug: 'ceo', name: 'CEO' }, permissions: ['quotation.view.all', 'quotation.export_pdf.all'] };

/** A role on neither PDF row. */
const NEITHER: AuthenticatedUser = { ...MANAGER, role: { id: 'r3', slug: 'outdoor_supervisor', name: 'Outdoor Supervisor' }, permissions: ['quotation.view.out'] };

/** GETs answer `reads` in turn, the last one repeating; a POST answers `post`. */
function server(reads: (() => Response)[], post: () => Response = () => json(202, { data: { job_id: 'j2', status: 'queued' }, meta: { request_id: 'r1' } })) {
    let read = 0;

    return vi.fn(async (_input: string, init?: RequestInit) => {
        if (init?.method === 'POST') {
            return post();
        }

        const answer = reads[Math.min(read, reads.length - 1)] as () => Response;
        read += 1;

        return answer();
    });
}

async function render(fetchMock: ReturnType<typeof vi.fn>, profile: AuthenticatedUser = MANAGER, locale: 'ar' | 'en' = 'en') {
    vi.stubGlobal('fetch', (input: string, init?: RequestInit) =>
        /\/auth\/login$/.test(String(input))
            ? Promise.resolve(json(201, { data: { token: 'a'.repeat(64), token_type: 'Bearer', idle_timeout_seconds: 28800, user: profile } }))
            : (fetchMock as unknown as typeof globalThis.fetch)(input, init));
    await useAuth().login(profile.email, 'Passw0rd123');
    vi.stubGlobal('fetch', fetchMock);

    const i18n = createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { en, ar } });
    const wrapper = mount(QuotationPdfPanel, { props: { quotationId: 'q1', quotationCode: 'QT-2026-0007' }, global: { plugins: [i18n] } });
    await flushPromises();

    return wrapper;
}

function reads(fetchMock: ReturnType<typeof vi.fn>): number {
    return fetchMock.mock.calls.filter(([, init]) => (init as RequestInit | undefined)?.method !== 'POST').length;
}

describe('the customer PDF on its quotation (Module 9 · 5.1)', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
        vi.mocked(downloadFile).mockClear();
        useAuth().forgetSession();
        window.localStorage.clear();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('says so when no PDF was ever generated, and offers Generate but no Download', async () => {
        const fetchMock = server([state(NONE)]);
        const wrapper = await render(fetchMock);

        expect(String(fetchMock.mock.calls[0]?.[0])).toContain('/quotations/q1/pdf');
        expect(wrapper.get('[data-testid="pdf-status"]').text()).toBe(en.pdf.panel.none);
        expect(wrapper.find('[data-testid="pdf-generate"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="pdf-download"]').exists()).toBe(false);
    });

    it('shows a loading state until the first answer arrives', async () => {
        let answer: (response: Response) => void = () => undefined;
        const fetchMock = vi.fn(() => new Promise<Response>((resolve) => { answer = resolve; }));
        const wrapper = await render(fetchMock as unknown as ReturnType<typeof vi.fn>);

        expect(wrapper.find('[data-testid="loading-state"]').exists()).toBe(true);

        answer(state(NONE)());
        await flushPromises();

        expect(wrapper.find('[data-testid="loading-state"]').exists()).toBe(false);
    });

    it('lets the CEO read and download, and never offers them Generate', async () => {
        const wrapper = await render(server([state(COMPLETED)]), CEO);

        expect(wrapper.get('[data-testid="pdf-status"]').text()).toBe(en.pdf.panel.ready);
        expect(wrapper.find('[data-testid="pdf-generate"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="pdf-language"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="pdf-download"]').exists()).toBe(true);
    });

    it('draws nothing, and asks nothing, for a role on neither PDF row', async () => {
        const fetchMock = server([state(NONE)]);
        const wrapper = await render(fetchMock, NEITHER);

        expect(wrapper.find('[data-testid="quotation-pdf-panel"]').exists()).toBe(false);
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('shows the refused state when the API refuses the read (Procurement, Q2)', async () => {
        const wrapper = await render(server([refusal(403)]));

        expect(wrapper.find('[data-testid="permission-denied-state"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="pdf-generate"]').exists()).toBe(false);
    });

    it('shows the error state when the read fails, and reads again on retry', async () => {
        const fetchMock = server([() => json(500, { error: { code: 'internal_error', message: 'x' } }), state(NONE)]);
        const wrapper = await render(fetchMock);

        expect(wrapper.find('[data-testid="error-state"]').exists()).toBe(true);

        await wrapper.get('[data-testid="error-retry"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-testid="pdf-status"]').text()).toBe(en.pdf.panel.none);
    });

    it('asks for the language chosen, then polls while queued and offers the file once completed', async () => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
        // The mount's read, the read-back after the 202, one poll still queued, then done.
        const fetchMock = server([state(NONE), state(QUEUED), state(QUEUED), state(COMPLETED)]);
        const wrapper = await render(fetchMock);

        await wrapper.get('[data-testid="pdf-language-en"]').setValue(true);
        await wrapper.get('[data-testid="pdf-generate"]').trigger('click');
        await flushPromises();

        const post = fetchMock.mock.calls.find(([, init]) => (init as RequestInit | undefined)?.method === 'POST');
        expect(JSON.parse(String((post?.[1] as RequestInit).body))).toEqual({ locale: 'en' });

        // Q12: queued, the button waits — and says why.
        expect(wrapper.get('[data-testid="pdf-status"]').text()).toBe(en.pdf.panel.queued);
        expect(wrapper.get('[data-testid="pdf-generate"]').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[data-testid="pdf-generate"]').attributes('aria-describedby')).toBe('pdf-status-q1');

        await vi.advanceTimersByTimeAsync(3000);
        await flushPromises();
        expect(wrapper.get('[data-testid="pdf-status"]').text()).toBe(en.pdf.panel.queued);

        await vi.advanceTimersByTimeAsync(3000);
        await flushPromises();
        expect(wrapper.get('[data-testid="pdf-status"]').text()).toBe(en.pdf.panel.ready);
        expect(wrapper.get('[data-testid="pdf-generate"]').attributes('disabled')).toBeUndefined();

        // Final: no read after the one that said so.
        const settled = reads(fetchMock);
        await vi.advanceTimersByTimeAsync(9000);
        expect(reads(fetchMock)).toBe(settled);

        await wrapper.get('[data-testid="pdf-download"]').trigger('click');
        await flushPromises();
        expect(downloadFile).toHaveBeenCalledWith('f2', 'QT-2026-0007.pdf');
    });

    it('defaults the language to the screen’s own (Q15)', async () => {
        const fetchMock = server([state(NONE)]);
        const wrapper = await render(fetchMock, MANAGER, 'ar');

        expect((wrapper.get('[data-testid="pdf-language-ar"]').element as HTMLInputElement).checked).toBe(true);

        await wrapper.get('[data-testid="pdf-generate"]').trigger('click');
        await flushPromises();

        const post = fetchMock.mock.calls.find(([, init]) => (init as RequestInit | undefined)?.method === 'POST');
        expect(JSON.parse(String((post?.[1] as RequestInit).body))).toEqual({ locale: 'ar' });
    });

    it('shows a failure’s reason and keeps the previous PDF as the download', async () => {
        const wrapper = await render(server([state(FAILED_AFTER_A_GOOD_ONE)]));

        expect(wrapper.get('[data-testid="pdf-status"]').text()).toBe(en.pdf.panel.failed);
        expect(wrapper.get('[data-testid="pdf-failure-reason"]').text()).toBe('The renderer timed out.');
        // The job's text, not ours: its direction comes from itself, not from the screen.
        expect(wrapper.get('[data-testid="pdf-failure-reason"]').attributes('dir')).toBe('auto');
        expect(wrapper.get('[data-testid="pdf-generate"]').attributes('disabled')).toBeUndefined();

        await wrapper.get('[data-testid="pdf-download"]').trigger('click');
        await flushPromises();
        expect(downloadFile).toHaveBeenCalledWith('f1', 'QT-2026-0007.pdf');
    });

    it('resumes polling a generation that was already queued, and stops when unmounted', async () => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
        const fetchMock = server([state(QUEUED)]);
        const wrapper = await render(fetchMock);

        expect(wrapper.get('[data-testid="pdf-status"]').text()).toBe(en.pdf.panel.queued);

        await vi.advanceTimersByTimeAsync(3000);
        expect(reads(fetchMock)).toBe(2);

        wrapper.unmount();
        await vi.advanceTimersByTimeAsync(9000);
        expect(reads(fetchMock)).toBe(2);
    });

    it('says so when the API refuses the render by name (the Team Leader under D-a)', async () => {
        const wrapper = await render(server([state(NONE)], refusal(403)));

        await wrapper.get('[data-testid="pdf-generate"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-testid="pdf-error"]').text()).toBe(en.pdf.panel.generateForbidden);
        expect(wrapper.get('[data-testid="pdf-status"]').text()).toBe(en.pdf.panel.none);
    });

    it('shows the server’s own words when the quotation cannot be printed yet (3.5’s 422)', async () => {
        const blocked = 'The customer PDF cannot be made yet: the company name is not set in the system settings.';
        const wrapper = await render(server([state(NONE)], () => json(422, {
            error: { code: 'business_rule_blocked', message: blocked, details: [{ code: 'pdf_view_incomplete', message: blocked }] },
            meta: { request_id: 'r1' },
        })));

        await wrapper.get('[data-testid="pdf-generate"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-testid="pdf-error"]').text()).toBe(blocked);
    });

    it('says so when the render cannot be asked for at all', async () => {
        const wrapper = await render(server([state(NONE)], () => json(500, { error: { code: 'internal_error', message: 'x' } })));

        await wrapper.get('[data-testid="pdf-generate"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-testid="pdf-error"]').text()).toBe(en.pdf.panel.generateFailed);
    });

    it('says so when the download is refused', async () => {
        vi.mocked(downloadFile).mockRejectedValueOnce(new Error('404'));
        const wrapper = await render(server([state(COMPLETED)]));

        await wrapper.get('[data-testid="pdf-download"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-testid="pdf-error"]').text()).toBe(en.pdf.panel.downloadFailed);
    });

    it('speaks Arabic on an Arabic screen', async () => {
        const wrapper = await render(server([state(QUEUED)]), MANAGER, 'ar');

        expect(wrapper.get('h2').text()).toBe(ar.pdf.panel.title);
        expect(wrapper.get('[data-testid="pdf-status"]').text()).toBe(ar.pdf.panel.queued);
        wrapper.unmount();
    });
});
