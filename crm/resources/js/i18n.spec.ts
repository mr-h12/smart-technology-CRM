import { beforeEach, describe, expect, it } from 'vitest';
import { LOCALE_STORAGE_KEY, initialLocale, setLocale } from '@/i18n';
import en from '@/locales/en.json';
import ar from '@/locales/ar.json';

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

/**
 * The field explanations are for **a person using the system**, not for someone
 * who has read the documentation.
 *
 * ── Why this guard exists ──────────────────────────────────────────────────
 *
 * Because it was broken on the first attempt. The hints shipped in S-02.4 read
 * *"…when the customer sets none (D-63)"*, *"converted for display (DB-08)"*,
 * *"§5.3 uses 1 for EGP"* and *"in PHP letters"* — every sentence correct, every
 * sentence written for the wrong reader. The owner's correction was plain: these
 * are for people who have not seen the docs, do not write code, and may be
 * opening the system for the first time.
 *
 * The citations did not disappear; they moved to where they belong. Every one of
 * them is still in the component's docblock and in `CHECKLIST.md`, which is
 * where a reviewer checks whether a sentence is *true*. The screen is where a
 * person reads what to type.
 *
 * ⚠️ **This scans the lang files, not the components.** A hint is only ever a
 * key, so the string is the only place the jargon can be — and both languages
 * are scanned, because Arabic is a first-release language and a guard that only
 * reads English is half a guard.
 */
describe('the field explanations', () => {
    /** Decision, database, security and section references — reviewer language. */
    const REFERENCES = /\b(?:D|DB|SEC|AP|AUD|PRF|ST|DEV|OBS|J)-\d+\b|§/;

    /** Words that name the machinery rather than the task. */
    const JARGON = ['PHP', 'API', 'UTC', 'enum', 'JSON', 'null', 'endpoint', 'nullable'];

    function hints(bundle: typeof en): [string, string][] {
        return [
            ...Object.entries(bundle.settings.hint),
            ...Object.entries(bundle.limits.hint),
            ...Object.entries(bundle.currencies.rounding.hint),
            ...Object.entries(bundle.currencies.rates.hint),
            ...Object.entries(bundle.lists.hint),
        ];
    }

    it.each([['en', en], ['ar', ar]] as const)('%s carries every hint this screen renders', (_name, bundle) => {
        // Assert the count, not merely that the loop ran: an empty scan passes
        // every assertion below it and proves nothing.
        expect(hints(bundle)).toHaveLength(23);
    });

    it.each([['en', en], ['ar', ar]] as const)('%s cites no decision or section number', (_name, bundle) => {
        for (const [key, text] of hints(bundle)) {
            expect(REFERENCES.test(text), `${key}: ${text}`).toBe(false);
        }
    });

    it.each([['en', en], ['ar', ar]] as const)('%s names no piece of the machinery', (_name, bundle) => {
        for (const [key, text] of hints(bundle)) {
            for (const word of JARGON) {
                expect(text.includes(word), `${key} contains "${word}": ${text}`).toBe(false);
            }
        }
    });

    /** A sentence, not a label — the point is that it explains something. */
    it.each([['en', en], ['ar', ar]] as const)('%s writes each one as a sentence', (_name, bundle) => {
        for (const [key, text] of hints(bundle)) {
            expect(text.length, key).toBeGreaterThan(20);
            expect(text.trim().endsWith('.'), `${key} should end in a full stop`).toBe(true);
        }
    });
});
