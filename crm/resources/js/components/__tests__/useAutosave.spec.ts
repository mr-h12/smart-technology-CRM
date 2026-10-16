import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { useAutosave } from '@/components/useAutosave';

/**
 * `D-124`'s autosave, shared by `D-131` (Module 13, slice 3): the timer that
 * lived in `CustomerNotesSection.vue` moves here, so the customer page and the
 * daily report run one copy (spec §5).
 *
 * - typing restarts a 2-second pause, per box (key); the save runs once it ends;
 * - leaving the box saves at once and drops its pause;
 * - a box removed before its pause ends is not saved (`cancel`);
 * - leaving the page saves what is still waiting, and no timer fires after.
 */

type Autosave = ReturnType<typeof useAutosave>;

function host(save: (key: string) => void): { autosave: Autosave; unmount: () => void } {
    let autosave: Autosave | undefined;
    const wrapper = mount(defineComponent({
        setup() {
            autosave = useAutosave(save);

            return () => h('div');
        },
    }));

    return { autosave: autosave!, unmount: () => wrapper.unmount() };
}

describe('useAutosave', () => {
    beforeEach(() => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('saves a box 2 seconds after typing stops, and not before', () => {
        const save = vi.fn();
        const { autosave } = host(save);

        autosave.typed('a');
        vi.advanceTimersByTime(1999);
        expect(save).not.toHaveBeenCalled();
        expect(autosave.pending('a')).toBe(true);

        vi.advanceTimersByTime(1);
        expect(save).toHaveBeenCalledTimes(1);
        expect(save).toHaveBeenCalledWith('a');
        expect(autosave.pending('a')).toBe(false);
    });

    it('restarts the pause on every keystroke', () => {
        const save = vi.fn();
        const { autosave } = host(save);

        autosave.typed('a');
        vi.advanceTimersByTime(1500);
        autosave.typed('a');
        vi.advanceTimersByTime(1500);
        expect(save).not.toHaveBeenCalled();

        vi.advanceTimersByTime(500);
        expect(save).toHaveBeenCalledTimes(1);
    });

    it('keeps one pause per box', () => {
        const save = vi.fn();
        const { autosave } = host(save);

        autosave.typed('a');
        vi.advanceTimersByTime(1000);
        autosave.typed('b');
        vi.advanceTimersByTime(1000);
        expect(save.mock.calls).toEqual([['a']]);

        vi.advanceTimersByTime(1000);
        expect(save.mock.calls).toEqual([['a'], ['b']]);
    });

    it('saves at once when the box is left, and the pause does not save it again', () => {
        const save = vi.fn();
        const { autosave } = host(save);

        autosave.typed('a');
        autosave.left('a');
        expect(save).toHaveBeenCalledTimes(1);
        expect(autosave.pending('a')).toBe(false);

        vi.advanceTimersByTime(5000);
        expect(save).toHaveBeenCalledTimes(1);
    });

    it('drops a cancelled pause without saving', () => {
        const save = vi.fn();
        const { autosave } = host(save);

        autosave.typed('a');
        autosave.cancel('a');
        vi.advanceTimersByTime(5000);

        expect(save).not.toHaveBeenCalled();
        expect(autosave.pending('a')).toBe(false);
    });

    it('saves what is still waiting when the page is left, and no timer fires after', () => {
        const save = vi.fn();
        const { autosave, unmount } = host(save);

        autosave.typed('a');
        autosave.typed('b');
        unmount();
        expect(save.mock.calls).toEqual([['a'], ['b']]);

        vi.advanceTimersByTime(5000);
        expect(save).toHaveBeenCalledTimes(2);
    });
});
