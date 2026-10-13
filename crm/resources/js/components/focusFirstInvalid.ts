import { nextTick } from 'vue';

/**
 * A-11: after a refused save, the keyboard lands on the first field to fix, in
 * the order the form draws them. Called after `saving` clears — a disabled
 * field cannot take focus.
 */
export async function focusFirstInvalid(form: HTMLElement | null): Promise<void> {
    await nextTick();
    form?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus();
}
