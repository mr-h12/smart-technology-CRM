import { beforeEach, describe, expect, it } from 'vitest';
import { LOCALE_STORAGE_KEY, initialLocale, setLocale } from '@/i18n';

/**
 * The shell opens in the product default whatever the browser asks for
 * (SpaShellTest), so Accept-Language no longer carries a reader's choice across
 * a reload. This does — and it is the half of that contract a PHP test cannot
 * reach: LocalePreferenceTest can only assert that the two files name the same
 * storage key, not that anything ever writes to it.
 */
describe('setLocale', () => {
    beforeEach(() => {
        window.localStorage.clear();
        document.documentElement.lang = 'ar';
        document.documentElement.dir = 'rtl';
    });

    it('remembers the chosen language for the next reload', () => {
        setLocale('en');

        expect(window.localStorage.getItem(LOCALE_STORAGE_KEY)).toBe('en');
    });

    it('moves the document language and its direction together', () => {
        setLocale('en');

        expect(document.documentElement.lang).toBe('en');
        expect(document.documentElement.dir).toBe('ltr');

        setLocale('ar');

        expect(document.documentElement.lang).toBe('ar');
        expect(document.documentElement.dir).toBe('rtl');
    });

    it('reads the language back off the document the pre-paint script wrote', () => {
        // The bundle never negotiates for itself: welcome.blade.php and its
        // pre-paint script have already decided, and a second opinion here
        // would let a reload change the language without anyone asking.
        document.documentElement.lang = 'en';

        expect(initialLocale()).toBe('en');
    });
});
