import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import en from '@/locales/en.json';
import ar from '@/locales/ar.json';
import PermissionDiffModal from '@/components/roles/PermissionDiffModal.vue';

/**
 * Point 5.3 — the confirmation in front of `PATCH /roles/{id}/permissions`.
 *
 * §3.12 rule 5 · §6.1 · §6.6 · `AUD-02`. The endpoint takes the whole desired
 * grant set, so the one thing the grid cannot show is what changed. These tests
 * are about whether the modal says it.
 */

function mountModal(props: Record<string, unknown> = {}, locale: 'ar' | 'en' = 'en') {
    return mount(PermissionDiffModal, {
        props: {
            open: true,
            roleName: 'Procurement',
            granted: ['customer.view.all', 'deal.view.asgn'],
            revoked: ['quotation.view.own'],
            busy: false,
            ...props,
        },
        global: {
            plugins: [createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { ar, en } })],
        },
    });
}

describe('the diff', () => {
    it('lists every granted and revoked triple by name', () => {
        const wrapper = mountModal();

        const granted = wrapper.find('[data-testid="diff-granted"]').text();
        const revoked = wrapper.find('[data-testid="diff-revoked"]').text();

        expect(granted).toContain('customer.view.all');
        expect(granted).toContain('deal.view.asgn');
        expect(revoked).toContain('quotation.view.own');

        // The lists must not bleed into each other: a revoked triple shown
        // under Granted is the one mistake this modal exists to prevent.
        expect(granted).not.toContain('quotation.view.own');
        expect(revoked).not.toContain('customer.view.all');
    });

    it('states the counts, so a long list is not read to be understood', () => {
        const summary = mountModal().find('#permission-diff-summary').text();

        expect(summary).toContain('2');
        expect(summary).toContain('1');
    });

    it('renders both sections even when one side is empty', () => {
        // A modal that hides the revoked section when nothing is revoked reads
        // as "no revocations" and as "the section was forgotten" identically.
        const wrapper = mountModal({ revoked: [] });

        expect(wrapper.find('[data-testid="diff-revoked"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="diff-revoked"]').text()).toContain(en.roles.diff.none);
    });

    it('names the role being changed', () => {
        expect(mountModal().find('#permission-diff-title').text()).toContain('Procurement');
    });

    it('says the change needs no deployment (§3.12 rule 5)', () => {
        expect(mountModal().text()).toContain(en.roles.diff.effect);
    });
});

describe('the controls', () => {
    it('renders nothing at all while closed', () => {
        expect(mountModal({ open: false }).find('[data-testid="permission-diff-modal"]').exists()).toBe(false);
    });

    it('emits confirm and cancel rather than calling the API itself', async () => {
        const wrapper = mountModal();

        await wrapper.find('[data-testid="diff-confirm"]').trigger('click');
        await wrapper.find('[data-testid="diff-cancel"]').trigger('click');

        expect(wrapper.emitted('confirm')).toHaveLength(1);
        expect(wrapper.emitted('cancel')).toHaveLength(1);
    });

    it('disables both buttons while the request is in flight', () => {
        const wrapper = mountModal({ busy: true });

        expect(wrapper.find('[data-testid="diff-confirm"]').attributes('disabled')).toBeDefined();
        expect(wrapper.find('[data-testid="diff-cancel"]').attributes('disabled')).toBeDefined();
    });

    it('cancels on Escape (§6.1), and not while a request is in flight', async () => {
        const wrapper = mountModal();

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        expect(wrapper.emitted('cancel')).toHaveLength(1);

        await wrapper.setProps({ busy: true });
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        expect(wrapper.emitted('cancel')).toHaveLength(1);

        wrapper.unmount();
    });

    it('stops listening for Escape once unmounted', () => {
        const wrapper = mountModal();
        wrapper.unmount();

        // A document listener that outlives its component answers for a modal
        // that is no longer on the page.
        expect(() => document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))).not.toThrow();
        expect(wrapper.emitted('cancel')).toBeUndefined();
    });
});

describe('Arabic', () => {
    it('renders the Arabic confirmation, not a raw key', () => {
        const wrapper = mountModal({}, 'ar');

        expect(wrapper.find('[data-testid="diff-confirm"]').text()).toBe(ar.roles.diff.confirm);
        expect(wrapper.text()).not.toContain('roles.diff');
    });
});
