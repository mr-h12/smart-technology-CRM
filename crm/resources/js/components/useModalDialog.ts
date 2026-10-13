import { watch, type Ref } from 'vue';

/**
 * F-42 · A-04: a `v-if` `<dialog>` opens with `showModal()` the moment it is
 * drawn, so the browser traps Tab, makes the page behind inert and turns Escape
 * into the dialog's `cancel` event (DS §6.6). The `v-if` removes the element
 * instead of calling `close()`, and a removed dialog restores nothing, so the
 * opener is remembered here and gets focus back.
 */
export function useModalDialog(dialog: Ref<HTMLDialogElement | null>): void {
    let opener: HTMLElement | null = null;

    watch(dialog, (element) => {
        if (element !== null) {
            opener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
            element.showModal();
        } else {
            opener?.focus();
            opener = null;
        }
    }, { flush: 'post' });
}
