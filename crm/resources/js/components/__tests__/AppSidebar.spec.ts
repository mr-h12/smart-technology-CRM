import { beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
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

function mountSidebar(collapsed: boolean) {
    return mount(AppSidebar, {
        props: { open: true, collapsed },
        global: {
            plugins: [createI18n({ legacy: false, locale: 'ar', fallbackLocale: 'en', messages: { ar, en } })],
            // The links are not what is under test, and a real router would
            // drag the whole route table in to render three list items.
            stubs: { RouterLink: { template: '<a><slot /></a>' } },
        },
    });
}

describe('AppSidebar collapse control', () => {
    beforeEach(() => {
        window.localStorage.clear();
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

    it('omits an item whose permission the role does not hold', async () => {
        await signIn({ ...BASE, permissions: ['customer.view.own'] });

        const items = menuItems();

        expect(items).toContain(ar.nav.item.customers);
        expect(items).not.toContain(ar.nav.item.roles);
    });
});
