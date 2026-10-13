import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { createI18n } from 'vue-i18n';
import en from '@/locales/en.json';
import ar from '@/locales/ar.json';
import ConfirmDialog from '@/components/users/ConfirmDialog.vue';

/** F-42's shared confirm. A-30 (5.14): a busy confirm says so and is not closed under the request. */
function render(busy: boolean, open = true) {
    return mount(ConfirmDialog, {
        attachTo: document.body,
        props: {
            open,
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

        view.get('dialog').element.dispatchEvent(new Event('cancel', { cancelable: true }));
        await view.find('.dialog-scrim').trigger('click');

        expect(view.emitted('cancel')).toBeUndefined();
        view.unmount();
    });


    /** F-42 · 5.10 (A-04): a native modal, so the browser traps Tab and the opener gets focus back. */
    it('opens as a native modal <dialog>', async () => {
        const showModal = vi.spyOn(HTMLDialogElement.prototype, 'showModal');
        const view = render(false);
        // The template ref is set after render, so showModal() runs on the next tick.
        await nextTick();

        expect(view.get('[data-testid="confirm-dialog"]').element.tagName).toBe('DIALOG');
        expect(showModal).toHaveBeenCalledTimes(1);
        view.unmount();
    });

    it('puts focus inside on open and hands it back to the opener on close', async () => {
        const opener = document.createElement('button');
        document.body.append(opener);
        opener.focus();

        const view = render(false, false);
        await view.setProps({ open: true });
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(view.get('[data-testid="confirm-dialog"]').element.contains(document.activeElement)).toBe(true);

        await view.setProps({ open: false });
        expect(document.activeElement).toBe(opener);
        view.unmount();
        opener.remove();
    });

    it('cancels on the dialog\'s own Escape (the cancel event), and keeps the browser from closing it alone', () => {
        const view = render(false);
        const escape = new Event('cancel', { cancelable: true });

        view.get('[data-testid="confirm-dialog"]').element.dispatchEvent(escape);

        expect(view.emitted('cancel')).toHaveLength(1);
        expect(escape.defaultPrevented).toBe(true);
        view.unmount();
    });

    /**
     * A-04: a second Escape with no user activation in between fires `cancel` uncancelable and the
     * browser closes the dialog anyway (HTML "request to close", canPreventClose false). A dialog the
     * component still draws must be modal again, never a painted non-modal ghost.
     */
    it('reopens as a modal when the browser closes it while it is still drawn', async () => {
        const view = render(true);
        await nextTick();
        const dialog = view.get('[data-testid="confirm-dialog"]').element as HTMLDialogElement;

        dialog.dispatchEvent(new Event('cancel'));
        dialog.close();
        await nextTick();

        expect(dialog.open).toBe(true);
        expect(view.emitted('cancel')).toBeUndefined();
        view.unmount();
    });

    it('A-66 (SH-11): two mounted dialogs each point at their own title and message', () => {
        // One app, as on a page: `useId` is unique within an app.
        const props = { open: true, titleKey: 'customers.archive.confirmTitle', messageKey: 'customers.archive.confirmMessage', confirmKey: 'customers.archive.confirmAction', subject: 'A', busy: false };
        const host = mount({ components: { ConfirmDialog }, setup: () => ({ props }), template: '<ConfirmDialog v-bind="props" /><ConfirmDialog v-bind="props" />' }, {
            attachTo: document.body,
            global: { plugins: [createI18n({ legacy: false, locale: 'en', fallbackLocale: 'en', messages: { en, ar } })] },
        });
        const dialogs = host.findAll('dialog');
        const titles = dialogs.map((dialog) => dialog.attributes('aria-labelledby') ?? '');

        expect(new Set(titles).size).toBe(2);
        expect(new Set(dialogs.map((dialog) => dialog.attributes('aria-describedby'))).size).toBe(2);
        titles.forEach((id) => expect(document.getElementById(id)?.tagName).toBe('H2'));
    });
});
