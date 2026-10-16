import { onBeforeUnmount } from 'vue';

/** `D-124`'s pause before a save. */
const PAUSE_MS = 2000;

/**
 * `D-124`'s autosave, shared by a customer's notes and a daily report's
 * follow-ups and notes (`D-131`): one pause per box (`key`). Typing restarts
 * it; when it ends, or the box is left, `save(key)` runs once. `cancel` drops
 * a box's pause unsaved (a removed row); leaving the page saves what is still waiting.
 */
export function useAutosave(save: (key: string) => unknown): {
    typed: (key: string) => void;
    left: (key: string) => void;
    cancel: (key: string) => void;
    pending: (key: string) => boolean;
} {
    const timers = new Map<string, ReturnType<typeof setTimeout>>();

    function cancel(key: string): void {
        clearTimeout(timers.get(key));
        timers.delete(key);
    }

    function left(key: string): void {
        cancel(key);
        void save(key);
    }

    onBeforeUnmount(() => {
        for (const key of [...timers.keys()]) {
            left(key);
        }
    });

    return {
        typed: (key) => {
            clearTimeout(timers.get(key));
            timers.set(key, setTimeout(() => left(key), PAUSE_MS));
        },
        left,
        cancel,
        pending: (key) => timers.has(key),
    };
}
