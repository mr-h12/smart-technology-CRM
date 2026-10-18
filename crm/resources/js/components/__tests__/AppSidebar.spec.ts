import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import { createMemoryHistory, createRouter } from 'vue-router';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import AppSidebar from '@/components/AppSidebar.vue';
import { useAuth, type AuthenticatedUser } from '@/stores/auth';

/**
 * The collapse control.
 *
 * Design System §5.1 gives the rail two widths and says nothing about where the
 * control that switches them lives. It sat at the very bottom of the aside,
 * below a navigation list that grows with every module — so the control moved
 * further down the screen as the product grew, and on a short viewport it was
 * below the fold entirely. The owner asked for it at the top of the menu and
 * icon-only.
 *
 * Icon-only is the part that needs a test rather than an eye: dropping the
 * visible label is one line, and dropping the *accessible* name with it is the
 * same line. A button whose only content is an aria-hidden svg is announced as
 * "button" and nothing else.
 */

/** A one-route memory router: the sidebar reads `route.fullPath` for its counters (3.3), nothing more. */
function memoryRouter() {
    return createRouter({ history: createMemoryHistory(), routes: [{ path: '/:any(.*)*', component: { template: '<div />' } }] });
}

function mountSidebar(collapsed: boolean) {
    return mount(AppSidebar, {
        props: { open: true, collapsed },
        global: {
            plugins: [createI18n({ legacy: false, locale: 'ar', fallbackLocale: 'en', messages: { ar, en } }), memoryRouter()],
            // The links are not what is under test, and the app's router would
            // drag the whole route table in to render three list items.
            stubs: { RouterLink: { template: '<a><slot /></a>' } },
        },
    });
}

describe('AppSidebar collapse control', () => {
    beforeEach(() => {
        window.localStorage.clear();
        // The counters' read (3.3) is not under test here; answer it with nothing.
        vi.stubGlobal('fetch', vi.fn(async () => new Response('{"data":{}}', { status: 200, headers: { 'Content-Type': 'application/json' } })));
    });

    it.each([true, false])('shows no visible label when collapsed is %s', (collapsed) => {
        const toggle = mountSidebar(collapsed).get('[data-testid="sidebar-collapse"]');

        const visible = toggle.findAll('span').filter(
            (span) => !span.classes('sr-only') && span.text().trim() !== '',
        );

        expect(visible).toHaveLength(0);
    });

    it.each([
        [false, ar.nav.collapse],
        [true, ar.nav.expand],
    ])('keeps an accessible name when collapsed is %s', (collapsed, expected) => {
        const toggle = mountSidebar(collapsed).get('[data-testid="sidebar-collapse"]');
        const name = toggle.get('.sr-only');

        // Read out of the lang file rather than written here, so the assertion
        // is that the button announces what this state is *called* — not that
        // somebody kept two copies of an Arabic sentence in step.
        expect(name.text()).toBe(expected);
        expect(expected.trim()).not.toBe('');
    });

    it('sits in the header row, above the navigation', () => {
        const aside = mountSidebar(false).get('[data-testid="sidebar"]');
        const toggle = aside.get('[data-testid="sidebar-collapse"]').element;
        const nav = aside.get('nav').element;

        // Document order rather than child index: the control is nested inside
        // the header now, so comparing direct children of the aside would say
        // it is absent rather than that it is early.
        const order = Array.from(aside.element.querySelectorAll('*'));

        expect(order.indexOf(toggle)).toBeLessThan(order.indexOf(nav));
        expect(aside.element.firstElementChild?.contains(toggle)).toBe(true);
    });

    it('puts the control where the product mark used to be, not beside it', () => {
        // The owner asked for the collapse control *instead of* the blue mark.
        // Two controls in a 72px rail is the outcome this rules out — and the
        // mark was decorative: §5.1 asks the sidebar for permitted screens and
        // never for a logo.
        const aside = mountSidebar(false).get('[data-testid="sidebar"]');

        expect(aside.find('[role="img"]').exists()).toBe(false);
    });
});


/**
 * §8's screen sets, and the one role where authorisation and navigation differ.
 *
 * §3.3's seven columns are Manager · TL · Out.Sup · Out.Sales · Indoor ·
 * Procure · CEO — **there is no Super Admin column** — and §8 gives the Super
 * Admin "22 administrative screens (section 13)", with no Customers among
 * them. But §3.1 gives them scope *All*, so `hasPermission` says yes to
 * everything and the Customers item was drawn for them.
 *
 * The fix is a menu question answered from the grant rows, not a new gate:
 * `AuthorizeAction` still short-circuits on the server, so nothing here is a
 * client-side restriction without a server counterpart (`SEC-09`). Typing the
 * URL still works, by the owner's decision of 2026-08-30.
 */
describe('AppSidebar — §8 draws the menu from the role’s grants', () => {
    beforeEach(() => {
        useAuth().forgetSession();
        window.localStorage.clear();
        vi.restoreAllMocks();
    });

    const BASE: AuthenticatedUser = {
        id: '01a0-u',
        name: 'Test',
        email: 'test@example.test',
        is_active: true,
        role: { id: '01a0-r', slug: 'manager', name: 'Manager' },
        permissions: [],
        unconditional_access: false,
    };

    /**
     * The nav links only — never the whole sidebar's text.
     *
     * The first version of these tests matched `mountSidebar().text()`, and
     * every one of them was wrong in the same invisible way: the product name
     * is "سمارت تكنولوجي — نظام إدارة العملاء", which *contains* the Customers
     * label. The assertion was reading the brand line, not the menu.
     */
    function menuItems(collapsed = false): string[] {
        return mountSidebar(collapsed).findAll('nav a').map((link) => link.text());
    }

    async function signIn(profile: AuthenticatedUser): Promise<void> {
        vi.stubGlobal('fetch', vi.fn(async () => new Response(
            JSON.stringify({ data: { token: 'a'.repeat(64), token_type: 'Bearer', idle_timeout_seconds: 28800, user: profile } }),
            { status: 201, headers: { 'Content-Type': 'application/json' } },
        )));

        await useAuth().login(profile.email, 'Passw0rd123');
    }

    /**
     * Measured, not assumed: the seeded `super_admin` role holds exactly nine
     * grants and every one of them is `admin.*`. So §13's screens survive and
     * only the business screen goes.
     */
    it('hides Customers from the Super Admin while keeping their §13 screens', async () => {
        await signIn({
            ...BASE,
            role: { id: '01a0-sa', slug: 'super_admin', name: 'Super Admin' },
            permissions: ['admin.create_user.all', 'admin.manage_roles.all', 'admin.fx_rates.all'],
            unconditional_access: true,
        });

        const items = menuItems();

        expect(items).not.toContain(ar.nav.item.customers);
        // The other half. Without it this passes against a sidebar that renders
        // no navigation at all — which is the failure this change could cause.
        expect(items).toContain(ar.nav.item.roles);
        expect(items).toContain(ar.nav.item.users);
    });

    it('still draws Customers for a role that actually holds customer.view', async () => {
        await signIn({ ...BASE, permissions: ['customer.view.all', 'admin.create_user.all'] });

        const items = menuItems();

        expect(items).toContain(ar.nav.item.customers);
        expect(items).toContain(ar.nav.item.users);
    });

    /** Module 13, slice 2 — §3.10: *view received* is the Manager's, never Sales'. */
    it('draws Received reports for a Manager and not for Sales', async () => {
        const label = (ar.nav.item as Record<string, string>).receivedReports;
        expect(label).toBeTruthy();

        await signIn({ ...BASE, permissions: ['report.create.all', 'report.view_received.all'] });
        expect(menuItems()).toContain(label);

        useAuth().forgetSession();
        await signIn({
            ...BASE,
            role: { id: '01a0-s', slug: 'indoor_sales', name: 'Indoor Sales' },
            permissions: ['report.create.own', 'customer.view.own'],
        });
        const items = menuItems();
        expect(items).toContain(ar.nav.item.reports);
        expect(items).not.toContain(label);
    });

    it('omits an item whose permission the role does not hold', async () => {
        await signIn({ ...BASE, permissions: ['customer.view.own'] });

        const items = menuItems();

        expect(items).toContain(ar.nav.item.customers);
        expect(items).not.toContain(ar.nav.item.roles);
    });
});

/**
 * Module 8 · 3.3 — Design System §5.1's two MVP counters this application
 * can produce: *Approvals* and *My Quotations*, from 2.3's `GET /badges`.
 * A zero draws nothing (a counter, not a status); the numbers are re-read on
 * every route change, so acting on `/approvals` and leaving it moves the count.
 */
describe('AppSidebar — the counters', () => {
    beforeEach(() => {
        useAuth().forgetSession();
        window.localStorage.clear();
        vi.restoreAllMocks();
    });

    const MANAGER: AuthenticatedUser = {
        id: '01a0-u',
        name: 'Test',
        email: 'manager@example.test',
        is_active: true,
        role: { id: '01a0-r', slug: 'manager', name: 'Manager' },
        permissions: ['quotation.view.all', 'quotation.approve.all'],
        unconditional_access: false,
    };

    function server(badges: unknown, user: AuthenticatedUser = MANAGER): ReturnType<typeof vi.fn> {
        return vi.fn(async (input: string) => {
            const body = /\/auth\/login$/.test(String(input))
                ? { data: { token: 'a'.repeat(64), token_type: 'Bearer', idle_timeout_seconds: 28800, user } }
                : { data: badges, meta: { request_id: 'r1' } };

            return new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } });
        });
    }

    async function mountWithRouter(fetchMock: ReturnType<typeof vi.fn>, props = { open: true, collapsed: false }) {
        vi.stubGlobal('fetch', fetchMock);
        await useAuth().login(MANAGER.email, 'Passw0rd123');

        const router = memoryRouter();
        await router.push('/');
        await router.isReady();

        const wrapper = mount(AppSidebar, {
            props,
            global: {
                plugins: [createI18n({ legacy: false, locale: 'ar', fallbackLocale: 'en', messages: { ar, en } }), router],
                stubs: { RouterLink: { template: '<a><slot /></a>' } },
            },
        });
        await flushPromises();

        return { wrapper, router };
    }

    it('draws the two counts from GET /badges, and nothing for a zero', async () => {
        const fetchMock = server({ approvals: 3, my_quotations: 0 });
        const { wrapper } = await mountWithRouter(fetchMock);

        expect(fetchMock.mock.calls.some((c) => String(c[0]).endsWith('/api/v1/badges'))).toBe(true);
        expect(wrapper.find('[data-testid="nav-badge-approvals"]').text()).toBe('3');
        expect(wrapper.find('[data-testid="nav-badge-quotations"]').exists()).toBe(false);
    });

    /** Module 13 · 7.5d — §5.1's *Reports* counter: the team's Missed daily reports, from the same `GET /badges`. */
    it('draws the Reports count for a holder of report.create, and nothing for a zero', async () => {
        const leader: AuthenticatedUser = {
            ...MANAGER,
            role: { id: '01a0-tl', slug: 'team_leader', name: 'Team Leader' },
            permissions: ['report.create.team', 'report.view_received.team'],
        };

        const { wrapper } = await mountWithRouter(server({ approvals: 0, my_quotations: 0, reports: 3 }, leader));
        expect(wrapper.findAll('nav a').some((link) => link.text().includes(ar.nav.item.reports))).toBe(true);
        expect(wrapper.find('[data-testid="nav-badge-reports"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="nav-badge-reports"]').text()).toBe('3');

        wrapper.unmount();
        useAuth().forgetSession();
        const zero = await mountWithRouter(server({ approvals: 0, my_quotations: 0, reports: 0 }, leader));
        expect(zero.wrapper.findAll('nav a').some((link) => link.text().includes(ar.nav.item.reports))).toBe(true);
        expect(zero.wrapper.find('[data-testid="nav-badge-reports"]').exists()).toBe(false);
    });

    /** F-42 · 5.33 (A-23): the collapsed rail drops the visible label; the link keeps it as its name, with the count. */
    it('names each collapsed link by its label, and the badged one with its count', async () => {
        const { wrapper } = await mountWithRouter(server({ approvals: 3, my_quotations: 0 }), { open: false, collapsed: true });
        const links = wrapper.findAll('nav a');
        const approvals = links.find((link) => link.find('[data-testid="nav-badge-approvals"]').exists());

        expect(approvals?.attributes('aria-label')).toBe(`${ar.nav.item.approvals}، 3`);
        expect(links.filter((link) => (link.attributes('aria-label') ?? '') === '')).toEqual([]);
    });

    it('re-reads the counts when the route changes', async () => {
        const fetchMock = server({ approvals: 0, my_quotations: 2 });
        const { wrapper, router } = await mountWithRouter(fetchMock);

        expect(wrapper.find('[data-testid="nav-badge-quotations"]').text()).toBe('2');
        const before = fetchMock.mock.calls.filter((c) => String(c[0]).endsWith('/api/v1/badges')).length;

        await router.push('/quotations');
        await flushPromises();

        expect(fetchMock.mock.calls.filter((c) => String(c[0]).endsWith('/api/v1/badges')).length).toBe(before + 1);
    });
});

/** F-42 · 5.33 (A-05): below 1024px the closed drawer sits off-screen, so it must leave the Tab order too. */
describe('AppSidebar — the closed drawer', () => {
    beforeEach(() => {
        useAuth().forgetSession();
        vi.stubGlobal('fetch', vi.fn(async () => new Response('{"data":{}}', { status: 200, headers: { 'Content-Type': 'application/json' } })));
    });

    it.each([
        [375, false, true],
        [375, true, false],
        [1280, false, false],
    ])('at %ipx with open=%s, inert is %s', (width, open, inert) => {
        vi.spyOn(window, 'matchMedia').mockImplementation((query: string) => ({
            matches: width >= 1024,
            media: query,
            addEventListener: () => undefined,
            removeEventListener: () => undefined,
        }) as unknown as MediaQueryList);

        const aside = mount(AppSidebar, {
            props: { open, collapsed: false },
            global: {
                plugins: [createI18n({ legacy: false, locale: 'ar', fallbackLocale: 'en', messages: { ar, en } }), memoryRouter()],
                stubs: { RouterLink: { template: '<a><slot /></a>' } },
            },
        }).get('[data-testid="sidebar"]');

        expect(aside.attributes('inert') !== undefined).toBe(inert);
    });
});

describe('AppSidebar landmarks (A-66)', () => {
    beforeEach(() => {
        vi.stubGlobal('fetch', vi.fn(async () => new Response('{"data":{}}', { status: 200, headers: { 'Content-Type': 'application/json' } })));
    });

    it.each([true, false])('SH-52: names each group’s list by its label when collapsed is %s', (collapsed) => {
        const lists = mountSidebar(collapsed).findAll('nav ul');

        expect(lists.length).toBeGreaterThan(0);
        expect(lists.filter((list) => (list.attributes('aria-label') ?? '').trim() === '')).toEqual([]);
    });

    it('SH-24: the drawer carries the id the menu button controls', () => {
        expect(mountSidebar(false).find('aside').attributes('id')).toBe('app-sidebar');
    });
});
