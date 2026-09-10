import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import DealDocumentsPanel from '@/pages/deals/DealDocumentsPanel.vue';
import type { Deal } from '@/services/deals';
import { useAuth, type AuthenticatedUser } from '@/stores/auth';

/**
 * Module 5, Point 6.7 — §17's upload and §3.4's assign, on the deal's page.
 *
 * ⚠️ **The panel can only show this session's uploads.** There is no
 * `GET /deals/{id}/documents` anywhere, and `DealPayload` carries no documents
 * array — Module 6 Point 6.5 hit the identical wall. A reload empties the list
 * while the files stay safe on the server, and the screen says so.
 */

function json(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

const DEAL: Deal = {
    id: 'd1', code: 'DL-2026-0001', customer_id: 'c1', title: 'Twelve pumps',
    source: 'employee_entry', service_type: 'product', status: 'lead', owner_id: 'u9',
    approval_status: null, rejection_reason: null, lost_reason: null,
    last_activity_at: '2026-09-08T09:00:00+00:00',
    created_at: '2026-09-08T09:00:00+00:00', updated_at: '2026-09-08T09:00:00+00:00',
};

function document(scanStatus = 'clean'): unknown {
    return {
        id: 'f1', original_name: 'offer.pdf', mime_type: 'application/pdf',
        size_bytes: 12, scan_status: scanStatus, created_at: '2026-09-08T09:00:00+00:00',
    };
}

const FULL: AuthenticatedUser = {
    id: 'u1', name: 'Test Manager', email: 'manager@example.test',
    role: { id: 'r1', slug: 'manager', name: 'Manager' },
    permissions: ['deal.view.all', 'deal.edit.all', 'deal.assign_owner.all'],
    is_active: true, unconditional_access: false,
};

/**
 * §3.4's documented split: Indoor Sales holds `edit` as `Own` and has **no**
 * `assign_owner` cell — `assign_owner` is granted to two roles where `edit`
 * reaches five.
 */
const EDITOR_ONLY: AuthenticatedUser = {
    ...FULL, id: 'u2', permissions: ['deal.view.own', 'deal.edit.own'],
};

/** The CEO holds `view` and neither write row. */
const READER: AuthenticatedUser = {
    ...FULL, id: 'u3', permissions: ['deal.view.all'],
};

/** The call to a given path — never `calls[0]`, the picker reads `/users` on mount. */
function callTo(fetchMock: ReturnType<typeof vi.fn>, path: string): [string, RequestInit] | undefined {
    return fetchMock.mock.calls.find((c) => String(c[0]).includes(path)) as [string, RequestInit] | undefined;
}

function envelope(data: unknown): unknown {
    return { data, meta: { request_id: 'r1' } };
}

const EMPLOYEES = [
    {
        id: 'u-indoor', name: 'Test Indoor Sales', email: 'indoor.sales@example.test',
        role_id: 'r5', role: { slug: 'indoor_sales', name: 'Indoor Sales', label: 'Indoor Sales' },
        is_active: true, created_at: '2026-08-01T00:00:00+00:00', updated_at: '2026-08-01T00:00:00+00:00',
    },
];

const PAGINATION = {
    page: 1, per_page: 25, total: 1, total_pages: 1, has_next_page: false, has_previous_page: false,
};

/** Answers the employee list the picker needs, then delegates. */
function withEmployees(delegate: (input: string) => Promise<Response>, usersStatus = 200): ReturnType<typeof vi.fn> {
    return vi.fn(async (input: string) => {
        if (String(input).includes('/users')) {
            return usersStatus === 200
                ? json(200, { data: EMPLOYEES, meta: { request_id: 'r1', pagination: PAGINATION } })
                : json(usersStatus, { error: { code: 'forbidden' }, meta: { request_id: 'r1' } });
        }

        return delegate(input);
    });
}

async function signIn(profile: AuthenticatedUser): Promise<void> {
    vi.stubGlobal('fetch', () => Promise.resolve(json(201, {
        data: { token: 'a'.repeat(64), token_type: 'Bearer', idle_timeout_seconds: 28800, user: profile },
    })));

    await useAuth().login(profile.email, 'Passw0rd123');
}

async function render(profile: AuthenticatedUser, fetchMock?: ReturnType<typeof vi.fn>) {
    await signIn(profile);

    if (fetchMock) {
        vi.stubGlobal('fetch', fetchMock);
    }

    const i18n = createI18n({ legacy: false, locale: 'en', fallbackLocale: 'en', messages: { en, ar } });

    return mount(DealDocumentsPanel, { props: { deal: DEAL }, global: { plugins: [i18n] } });
}

function chooseFile(wrapper: ReturnType<typeof mount>, file: File): Promise<void> {
    const input = wrapper.find('[data-testid="deal-documents-file"]').element as HTMLInputElement;

    Object.defineProperty(input, 'files', { value: [file], configurable: true });

    return wrapper.find('[data-testid="deal-documents-file"]').trigger('change');
}

describe('the deal documents and assign panel', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
        useAuth().forgetSession();
        window.localStorage.clear();
    });

    // ────────────────────────────────────────────────────────── the stated ceiling

    it('says on screen that it only shows this session’s uploads', async () => {
        const wrapper = await render(FULL);

        // Not only in a docblock: the files are safe, this list is not a
        // record of them, and a person must be able to know that.
        expect(wrapper.find('[data-testid="deal-documents-ceiling"]').text()).toContain('this page');
    });

    // ─────────────────────────────────────────────────────────────── the upload

    it('uploads under the form field name the error renderer hard-codes', async () => {
        const fetchMock = vi.fn(async () => json(201, envelope(document())));
        const wrapper = await render(FULL, fetchMock);

        await chooseFile(wrapper, new File(['x'], 'offer.pdf', { type: 'application/pdf' }));
        await flushPromises();

        const [url, init] = callTo(fetchMock, '/documents') as [string, RequestInit];
        const form = init.body as FormData;

        expect(String(url)).toContain('/deals/d1/documents');
        // `ApiExceptionRenderer` hard-codes `'field' => 'document'`; a rename
        // 422s every upload and points the error at a control that does not
        // exist. Module 6 found that by probe.
        expect(form.get('document')).toBeInstanceOf(File);
        expect(form.get('file')).toBeNull();
    });

    it('lists what it just uploaded, newest first', async () => {
        const fetchMock = vi.fn(async () => json(201, envelope(document())));
        const wrapper = await render(FULL, fetchMock);

        await chooseFile(wrapper, new File(['x'], 'offer.pdf', { type: 'application/pdf' }));
        await flushPromises();

        expect(wrapper.find('[data-testid="deal-documents-row"]').text()).toContain('offer.pdf');
    });

    it('shows a pending scan as itself rather than a broken download', async () => {
        const fetchMock = vi.fn(async () => json(201, envelope(document('pending'))));
        const wrapper = await render(FULL, fetchMock);

        await chooseFile(wrapper, new File(['x'], 'offer.pdf', { type: 'application/pdf' }));
        await flushPromises();

        // `SEC-15` gates the download on the scan, not on ownership.
        expect(wrapper.find('[data-testid="deal-documents-pending"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="deal-documents-download"]').exists()).toBe(false);
    });

    it('shows an infected file as refused, to the person who uploaded it', async () => {
        const fetchMock = vi.fn(async () => json(201, envelope(document('infected'))));
        const wrapper = await render(FULL, fetchMock);

        await chooseFile(wrapper, new File(['x'], 'offer.pdf', { type: 'application/pdf' }));
        await flushPromises();

        expect(wrapper.find('[data-testid="deal-documents-infected"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="deal-documents-download"]').exists()).toBe(false);
    });

    it('says a refused upload was a permission problem when it was one', async () => {
        const fetchMock = vi.fn(async () => json(403, { error: { code: 'forbidden' }, meta: { request_id: 'r1' } }));
        const wrapper = await render(FULL, fetchMock);

        await chooseFile(wrapper, new File(['x'], 'offer.pdf', { type: 'application/pdf' }));
        await flushPromises();

        expect(wrapper.find('[data-testid="deal-documents-error"]').text()).toContain('do not have permission');
        expect(wrapper.find('[data-testid="deal-documents-row"]').exists()).toBe(false);
    });

    it('draws no upload control without deal.edit', async () => {
        const wrapper = await render(READER);

        // §3.4 seeds no "attach document" row, so the route carries `deal.edit`
        // — and so does the control.
        expect(wrapper.find('[data-testid="deal-documents-file"]').exists()).toBe(false);
    });

    // ─────────────────────────────────────────────────────────────── the assign

    it('draws the assign section only for §3.4’s own assign_owner row', async () => {
        expect((await render(FULL)).find('[data-testid="deal-assign"]').exists()).toBe(true);
        // Holding `edit` is not holding `assign_owner`: two rows, and
        // `assign_owner` reaches two roles where `edit` reaches five.
        expect((await render(EDITOR_ONLY)).find('[data-testid="deal-assign"]').exists()).toBe(false);
    });

    it('offers a picker when the employee list can be read', async () => {
        const fetchMock = withEmployees(async () => json(200, envelope(DEAL)));
        const wrapper = await render(FULL, fetchMock);
        await flushPromises();

        const control = wrapper.find('[data-testid="deal-assign-owner"]');

        // The owner reported the text box as unusable: it demanded a UUID
        // nobody knows by heart. `GET /users` carries `admin.create_user`,
        // which every role that can actually assign a deal today also holds.
        expect(control.element.tagName).toBe('SELECT');
        expect(control.findAll('option').map((o) => o.attributes('value'))).toContain('u-indoor');
    });

    it('falls back to the identifier box when the list is refused', async () => {
        const fetchMock = withEmployees(async () => json(200, envelope(DEAL)), 403);
        const wrapper = await render(FULL, fetchMock);
        await flushPromises();

        // §3.11 gates the user list separately, so a caller may legitimately
        // assign and not list. The box behind the picker still works.
        const control = wrapper.find('[data-testid="deal-assign-owner"]');

        expect(control.element.tagName).toBe('INPUT');
        expect(wrapper.find('[data-testid="deal-assign"]').exists()).toBe(true);
        // ⚠️ Asserted because an empty list and a refused one are different
        // facts that the input box alone cannot tell apart — a probe that
        // removed the catch entirely reddened nothing without this.
        expect(wrapper.find('[data-testid="deal-assign-list-unavailable"]').exists()).toBe(true);
    });

    it('never asks for the employee list without the assign permission', async () => {
        const fetchMock = withEmployees(async () => json(200, envelope(DEAL)));
        await render(EDITOR_ONLY, fetchMock);
        await flushPromises();

        // No section, so no call — a request that could only be refused.
        expect(fetchMock.mock.calls.filter((c) => String(c[0]).includes('/users'))).toHaveLength(0);
    });

    it('assigns the employee chosen from the picker', async () => {
        const fetchMock = withEmployees(async () => json(200, envelope({ ...DEAL, owner_id: 'u-indoor' })));
        const wrapper = await render(FULL, fetchMock);
        await flushPromises();

        await wrapper.find('[data-testid="deal-assign-owner"]').setValue('u-indoor');
        await wrapper.find('[data-testid="deal-assign-form"]').trigger('submit');
        await flushPromises();

        const write = fetchMock.mock.calls.find((c) => String(c[0]).includes('/assign'));

        expect(JSON.parse(String((write?.[1] as RequestInit).body))).toEqual({ owner_id: 'u-indoor' });
    });

    it('assigns through its own route and its own field name', async () => {
        const fetchMock = vi.fn(async () => json(200, envelope({ ...DEAL, owner_id: 'u42' })));
        const wrapper = await render(FULL, fetchMock);

        await wrapper.find('[data-testid="deal-assign-owner"]').setValue('u42');
        await wrapper.find('[data-testid="deal-assign-form"]').trigger('submit');
        await flushPromises();

        const [url, init] = callTo(fetchMock, '/assign') as [string, RequestInit];

        expect(String(url)).toContain('/deals/d1/assign');
        expect(JSON.parse(String(init.body))).toEqual({ owner_id: 'u42' });
        expect(wrapper.emitted('assigned')).toHaveLength(1);
    });

    it('refuses a blank owner without asking the server', async () => {
        const fetchMock = vi.fn(async () => json(200, envelope(DEAL)));
        const wrapper = await render(FULL, fetchMock);

        await wrapper.find('[data-testid="deal-assign-owner"]').setValue('   ');
        await wrapper.find('[data-testid="deal-assign-form"]').trigger('submit');
        await flushPromises();

        // §3.4 has no unassign row and `AssignDealRequest` requires the field.
        // The picker's own `/users` read is not the assignment, so the
        // assertion names the call that must not happen.
        expect(callTo(fetchMock, '/assign')).toBeUndefined();
        expect(wrapper.find('[data-testid="deal-assign-owner-error"]').exists()).toBe(true);
    });

    it('renders the server’s own sentence when it refuses the owner', async () => {
        const fetchMock = vi.fn(async () => json(422, {
            error: {
                code: 'validation_failed',
                details: [{ field: 'owner_id', message: 'That employee cannot own a deal.' }],
            },
            meta: { request_id: 'r1' },
        }));
        const wrapper = await render(FULL, fetchMock);

        await wrapper.find('[data-testid="deal-assign-owner"]').setValue('u42');
        await wrapper.find('[data-testid="deal-assign-form"]').trigger('submit');
        await flushPromises();

        expect(wrapper.find('[data-testid="deal-assign-owner-error"]').text())
            .toBe('That employee cannot own a deal.');
    });
});
