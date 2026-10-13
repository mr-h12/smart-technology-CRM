import { nextTick, watch, type Ref } from 'vue';

/**
 * F-42 · A-04: a `v-if` `<dialog>` opens with `showModal()` the moment it is
 * drawn, so the browser traps Tab, makes the page behind inert and turns Escape
 * into the dialog's `cancel` event (DS §6.6). The `v-if` removes the element
 * instead of calling `close()`, and a removed dialog restores nothing, so the
 * opener is remembered here and gets focus back.
 *
 * A second Escape with no user activation in between fires `cancel`
 * uncancelable, and the browser closes the dialog whatever the handler said
 * (HTML "request to close", canPreventClose). The handler has still run; if the
 * component still draws the dialog afterwards, it is made modal again.
 */
export function useModalDialog(dialog: Ref<HTMLDialogElement | null>): void {
    let opener: HTMLElement | null = null;

    async function reopenIfStillDrawn(event: Event): Promise<void> {
        await nextTick();
        const element = dialog.value;

        if (element !== null && element === event.target && !element.open) {
            element.showModal();
        }
    }

    watch(dialog, (element) => {
        if (element !== null) {
            opener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
            element.addEventListener('close', reopenIfStillDrawn);
            element.showModal();
        } else {
            opener?.focus();
            opener = null;
        }
    }, { flush: 'post' });
}
