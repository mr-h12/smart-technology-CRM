import { beforeEach, describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import AppSidebar from '@/components/AppSidebar.vue';

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
