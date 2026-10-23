import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import { createMemoryHistory, createRouter } from 'vue-router';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import AlertBell from '@/components/AlertBell.vue';
import AppContextBar from '@/components/AppContextBar.vue';
import { useAuth, type AuthenticatedUser } from '@/stores/auth';

/**
 * Module 16, Point 1 — the bell (`D-153`). The header holds a bell with the
 * caller's unread count, read on every page change like the sidebar's counters;
 * opening it lists the open alerts, each linking to its record. Reading one, or
 * all, lowers the number; nothing is ever deleted from here.
 */

const USER: AuthenticatedUser = {
    id: '01a0-u',
    name: 'Test',
    email: 'sales@example.test',
    is_active: true,
    role: { id: '01a0-r', slug: 'indoor_sales', name: 'Indoor Sales' },
    permissions: ['quotation.view.own'],
    unconditional_access: false,
};

const PAGE = { page: 1, per_page: 25, total: 2, total_pages: 1, has_next_page: false, has_previous_page: false };

const EXPIRING = {
    id: 'a1',
    kind: 'quotation_expiring',
    subject_id: 'q-18',
    details: { number: 'QT-2026-0018', valid_until: '2026-10-24' },
    occurrences: 1,
    first_raised_at: '2026-10-22T06:00:00+00:00',
    last_raised_at: '2026-10-22T06:00:00+00:00',
    read: false,
};

const JOB = {
    id: 'a2',
    kind: 'job_failed',
    subject_id: 'job-7',
    details: { job: 'RefreshDashboardAggregatesJob' },
    occurrences: 3,
    first_raised_at: '2026-10-21T06:00:00+00:00',
    last_raised_at: '2026-10-21T09:00:00+00:00',
    read: true,
};

function json(body: unknown): Response {
    return new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } });
}

/** The API this component talks to: the count, the list, and the two read actions. */
function server(answers: { unread?: number; alerts?: unknown[] } = {}): ReturnType<typeof vi.fn> {
    let unread = answers.unread ?? 1;

    return vi.fn(async (input: string, init?: RequestInit) => {
        const url = String(input);
        if (/\/auth\/login$/.test(url)) {
            return json({ data: { token: 'a'.repeat(64), token_type: 'Bearer', idle_timeout_seconds: 28800, user: USER } });
        }
        if (/\/alerts\/unread-count$/.test(url)) {
            return json({ data: { unread }, meta: { request_id: 'r' } });
        }
        if (/\/alerts\/read-all$/.test(url) && init?.method === 'POST') {
            unread = 0;
            return json({ data: { unread }, meta: { request_id: 'r' } });
        }
        if (/\/alerts\/[^/]+\/read$/.test(url) && init?.method === 'POST') {
            unread = Math.max(0, unread - 1);
            return json({ data: { unread }, meta: { request_id: 'r' } });
        }
        if (/\/alerts(\?page=\d+)?$/.test(url)) {
            const alerts = answers.alerts ?? [EXPIRING, JOB];
            return json({ data: alerts, meta: { request_id: 'r', pagination: { ...PAGE, total: alerts.length } } });
        }

        return json({ data: {}, meta: { request_id: 'r' } });
    });
}

function appRouter() {
    const blank = { template: '<div />' };

    return createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', name: 'home', component: blank },
            { path: '/users', name: 'users', component: blank },
            { path: '/quotations/:id', name: 'quotation-detail', component: blank },
            { path: '/customers', name: 'customers', component: blank },
            // The context bar's user chip links here.
            { path: '/account/security', name: 'account-security', component: blank },
        ],
    });
}

async function render(fetchMock: ReturnType<typeof vi.fn>, locale = 'en') {
    vi.stubGlobal('fetch', fetchMock);
    await useAuth().login(USER.email, 'Passw0rd123');
    const router = appRouter();
    await router.push('/');
    await router.isReady();

    const wrapper = mount(AlertBell, {
        attachTo: document.body,
        global: { plugins: [createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { en, ar } }), router] },
    });
    await flushPromises();

    return { wrapper, router };
}

const calls = (fetchMock: ReturnType<typeof vi.fn>): string[] => fetchMock.mock.calls.map(([u]) => String(u));

describe('AlertBell — the count', () => {
    beforeEach(() => {
        useAuth().forgetSession();
        window.localStorage.clear();
    });
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('draws the unread count from GET /alerts/unread-count, and nothing for a zero', async () => {
        const fetchMock = server({ unread: 2 });
        const { wrapper } = await render(fetchMock);

        expect(calls(fetchMock).some((u) => u.endsWith('/api/v1/alerts/unread-count'))).toBe(true);
        expect(wrapper.get('[data-testid="alert-bell-count"]').text()).toBe('2');

        wrapper.unmount();
        useAuth().forgetSession();
        const zero = await render(server({ unread: 0 }));
        expect(zero.wrapper.find('[data-testid="alert-bell-count"]').exists()).toBe(false);
    });

    it('names the bell with its count, so a screen reader hears it', async () => {
        const { wrapper } = await render(server({ unread: 2 }));
        const bell = wrapper.get('[data-testid="alert-bell"]');

        expect(bell.attributes('aria-label')).toContain('2');
        expect(bell.attributes('aria-expanded')).toBe('false');
    });

    it('reads the count again on every page change', async () => {
        const fetchMock = server({ unread: 1 });
        const { router } = await render(fetchMock);
        const before = calls(fetchMock).filter((u) => u.endsWith('/alerts/unread-count')).length;

        await router.push('/customers');
        await flushPromises();

        expect(calls(fetchMock).filter((u) => u.endsWith('/alerts/unread-count')).length).toBe(before + 1);
    });

    it('does not ask for the list until the bell is opened', async () => {
        const fetchMock = server();
        await render(fetchMock);

        expect(calls(fetchMock).some((u) => /\/api\/v1\/alerts(\?page=\d+)?$/.test(u))).toBe(false);
    });
});

describe('AlertBell — the list', () => {
    beforeEach(() => {
        useAuth().forgetSession();
        window.localStorage.clear();
    });
    afterEach(() => {
        document.body.innerHTML = '';
    });

    async function open(fetchMock: ReturnType<typeof vi.fn>, locale = 'en') {
        const rendered = await render(fetchMock, locale);
        await rendered.wrapper.get('[data-testid="alert-bell"]').trigger('click');
        await flushPromises();

        return rendered;
    }

    it('lists each open alert in words, with a link to its record', async () => {
        const { wrapper } = await open(server());
        const rows = wrapper.findAll('[data-testid="alert-row"]');

        expect(wrapper.get('[data-testid="alert-bell"]').attributes('aria-expanded')).toBe('true');
        expect(rows).toHaveLength(2);
        expect(rows[0]!.attributes('data-kind')).toBe('quotation_expiring');
        expect(rows[0]!.text()).toContain('QT-2026-0018');
        expect(rows[0]!.text()).toContain('24/10/2026');
        expect(rows[0]!.get('a').attributes('href')).toBe('/quotations/q-18');
        expect(rows[1]!.attributes('data-kind')).toBe('job_failed');
        expect(rows[1]!.get('a').attributes('href')).toBe('/');
    });

    it('shows a repeat as one row with its counter', async () => {
        const { wrapper } = await open(server());

        expect(wrapper.findAll('[data-testid="alert-row"]')[1]!.get('[data-testid="alert-occurrences"]').text()).toContain('3');
        expect(wrapper.findAll('[data-testid="alert-row"]')[0]!.find('[data-testid="alert-occurrences"]').exists()).toBe(false);
    });

    it('marks an unread row, and only an unread row, as unread', async () => {
        const { wrapper } = await open(server());
        const rows = wrapper.findAll('[data-testid="alert-row"]');

        expect(rows[0]!.attributes('data-read')).toBe('false');
        expect(rows[0]!.find('[data-testid="alert-read"]').exists()).toBe(true);
        expect(rows[1]!.attributes('data-read')).toBe('true');
        expect(rows[1]!.find('[data-testid="alert-read"]').exists()).toBe(false);
    });

    it('marks one alert read and lowers the count, keeping the row', async () => {
        const fetchMock = server({ unread: 1 });
        const { wrapper } = await open(fetchMock);

        await wrapper.get('[data-testid="alert-read"]').trigger('click');
        await flushPromises();

        expect(fetchMock.mock.calls.some(([u, init]) => String(u).endsWith('/api/v1/alerts/a1/read') && (init as RequestInit | undefined)?.method === 'POST')).toBe(true);
        expect(wrapper.find('[data-testid="alert-bell-count"]').exists()).toBe(false);
        expect(wrapper.findAll('[data-testid="alert-row"]')).toHaveLength(2);
        expect(wrapper.findAll('[data-testid="alert-row"]')[0]!.attributes('data-read')).toBe('true');
    });

    /** The browser check (2026-10-22): the pressed button leaves with the unread state, so the focus must not fall to the page — and Escape must still close. */
    it('keeps the focus on the row it read, and still closes on Escape', async () => {
        const { wrapper } = await open(server({ unread: 1 }));

        await wrapper.get('[data-testid="alert-read"]').trigger('click');
        await flushPromises();

        expect(document.activeElement).toBe(wrapper.findAll('[data-testid="alert-row"]')[0]!.get('a').element);

        document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        await flushPromises();

        expect(wrapper.find('[data-testid="alert-list"]').exists()).toBe(false);
        expect(document.activeElement).toBe(wrapper.get('[data-testid="alert-bell"]').element);
    });

    it('marks every alert read at once', async () => {
        const fetchMock = server({ unread: 1 });
        const { wrapper } = await open(fetchMock);

        await wrapper.get('[data-testid="alert-read-all"]').trigger('click');
        await flushPromises();

        expect(fetchMock.mock.calls.some(([u, init]) => String(u).endsWith('/api/v1/alerts/read-all') && (init as RequestInit | undefined)?.method === 'POST')).toBe(true);
        expect(wrapper.find('[data-testid="alert-bell-count"]').exists()).toBe(false);
    });

    it('says so when there is nothing', async () => {
        const { wrapper } = await open(server({ unread: 0, alerts: [] }));

        expect(wrapper.find('[data-testid="alert-row"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="alert-empty"]').text()).not.toBe('');
        expect(wrapper.find('[data-testid="alert-read-all"]').exists()).toBe(false);
    });

    it('has a sentence for each of D-153\'s five kinds, in both languages', async () => {
        const kinds = ['quotation_expiring', 'account_locked', 'job_failed', 'storage_over_limit', 'backup_failed'];
        const alerts = kinds.map((kind, i) => ({ ...EXPIRING, id: `k${i}`, kind, subject_id: kind === 'quotation_expiring' ? 'q-18' : 'x' }));

        for (const locale of ['en', 'ar']) {
            const { wrapper } = await open(server({ alerts }), locale);
            const rows = wrapper.findAll('[data-testid="alert-row"]');

            expect(rows).toHaveLength(5);
            for (const row of rows) {
                // A missing key renders the key itself; no row may read like one.
                expect(row.text()).not.toMatch(/alerts\.kind\./);
            }
            wrapper.unmount();
            useAuth().forgetSession();
        }
    });

    /** Module 16 · 4: the storage alert says how full, and against what limit. */
    it('says how full the storage is and what the limit is', async () => {
        const storage = { ...JOB, id: 's1', kind: 'storage_over_limit', subject_id: null, details: { used_percent: '85', limit_percent: '80' }, occurrences: 1 };
        const { wrapper } = await open(server({ alerts: [storage] }));

        const row = wrapper.get('[data-testid="alert-row"]');
        expect(row.text()).toContain('85');
        expect(row.text()).toContain('80');
        expect(row.get('a').attributes('href')).toBe('/');
    });

    it('closes on Escape and gives the focus back to the bell', async () => {
        const { wrapper } = await open(server());

        await wrapper.get('[data-testid="alert-list"]').trigger('keydown', { key: 'Escape' });

        expect(wrapper.find('[data-testid="alert-list"]').exists()).toBe(false);
        expect(document.activeElement).toBe(wrapper.get('[data-testid="alert-bell"]').element);
    });
});

describe('AppContextBar — the bell sits in the header', () => {
    beforeEach(() => {
        useAuth().forgetSession();
        window.localStorage.clear();
    });

    it('shows the bell to a signed-in person, and not to anyone else', async () => {
        vi.stubGlobal('fetch', server());
        const router = appRouter();
        await router.push('/');
        await router.isReady();
        const plugins = [createI18n({ legacy: false, locale: 'ar', fallbackLocale: 'en', messages: { ar, en } }), router];

        const signedOut = mount(AppContextBar, { props: { sidebarOpen: false }, global: { plugins } });
        expect(signedOut.find('[data-testid="alert-bell"]').exists()).toBe(false);
        signedOut.unmount();

        await useAuth().login(USER.email, 'Passw0rd123');
        const signedIn = mount(AppContextBar, { props: { sidebarOpen: false }, global: { plugins } });
        await flushPromises();
        expect(signedIn.find('[data-testid="alert-bell"]').exists()).toBe(true);
    });
});
