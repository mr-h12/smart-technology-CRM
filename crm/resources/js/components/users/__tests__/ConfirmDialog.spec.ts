import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import en from '@/locales/en.json';
import ar from '@/locales/ar.json';
import ConfirmDialog from '@/components/users/ConfirmDialog.vue';

/** F-42's shared confirm. A-30 (5.14): a busy confirm says so and is not closed under the request. */
function render(busy: boolean) {
    return mount(ConfirmDialog, {
        props: {
            open: true,
            titleKey: 'customers.archive.confirmTitle',
            messageKey: 'customers.archive.confirmMessage',
            confirmKey: 'customers.archive.confirmAction',
            subject: 'Alpha Trading',
            busy,
            danger: true,
        },
        global: { plugins: [createI18n({ legacy: false, locale: 'en', fallbackLocale: 'en', messages: { en, ar } })] },
    });
}

describe('ConfirmDialog', () => {
    it('reads the busy label on the confirm button while busy, and its own word otherwise', () => {
        expect(render(true).find('[data-testid="confirm-accept"]').text()).toBe(en.action.saving);
        expect(render(false).find('[data-testid="confirm-accept"]').text()).toBe(en.customers.archive.confirmAction);
    });

    it('ignores Escape and the scrim while busy', async () => {
        const view = render(true);

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await view.find('.dialog-scrim').trigger('click');

        expect(view.emitted('cancel')).toBeUndefined();
        view.unmount();
    });

    it('still cancels on Escape when idle', () => {
        const view = render(false);

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));

        expect(view.emitted('cancel')).toHaveLength(1);
        view.unmount();
    });
});
