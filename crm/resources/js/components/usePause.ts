import { onBeforeUnmount } from 'vue';

/**
 * The pause a typed search waits after the last key (F-42 · A-49): one value
 * and one timer for the pickers and the list filters alike. `cancel` drops a
 * pending run, for a search that was answered another way first.
 */
export function usePause(run: () => void): { restart: () => void; cancel: () => void } {
    let timer: ReturnType<typeof setTimeout> | undefined;
    const cancel = (): void => clearTimeout(timer);

    onBeforeUnmount(cancel);

    return {
        restart: () => {
            cancel();
            timer = setTimeout(run, 300);
        },
        cancel,
    };
}
