import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import SupplierRatingChip from '@/components/suppliers/SupplierRatingChip.vue';

/**
 * Module 4, Point 4.0 — §7.1's chip.
 *
 * ── The word is the requirement, not the colour ────────────────────────────
 *
 * Design System §6.4 ends its badge table with "never color alone", and it
 * names these four as "supplier rating chips only". So every assertion below is
 * about the **text**: a person who cannot distinguish green from red must still
 * read what the chip says. The colour is carried by a class and asserted only
 * as "a different one per rating", because the value itself is a token.
 *
 * §7.1's four meanings are the source: green excellent, yellow average, red
 * problematic, white new / not yet rated.
 */

const RATINGS = ['green', 'yellow', 'red', 'white'] as const;

function mountChip(rating: (typeof RATINGS)[number], locale: 'ar' | 'en' = 'en') {
    return mount(SupplierRatingChip, {
        props: { rating },
        global: {
            plugins: [createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { ar, en } })],
        },
    });
}

describe('SupplierRatingChip', () => {
    it.each(RATINGS)('carries a localised word for %s, so colour is never alone', (rating) => {
        const chip = mountChip(rating);

        expect(chip.text().trim()).not.toBe('');
        expect(chip.text()).not.toBe(rating);
    });

    it('gives each rating its own word in English', () => {
        const words = RATINGS.map((rating) => mountChip(rating).text().trim());

        expect(new Set(words).size).toBe(RATINGS.length);
    });

    it('gives each rating its own word in Arabic', () => {
        const words = RATINGS.map((rating) => mountChip(rating, 'ar').text().trim());

        expect(new Set(words).size).toBe(RATINGS.length);
        expect(words.every((word) => /[؀-ۿ]/.test(word))).toBe(true);
    });

    it('gives each rating its own class, so the colour is a token and not a literal', () => {
        const classes = RATINGS.map((rating) => mountChip(rating).get('[data-testid="supplier-rating"]').classes().join(' '));

        expect(new Set(classes).size).toBe(RATINGS.length);
    });

    it('exposes the rating as an accessible label rather than relying on the visual', () => {
        const chip = mountChip('red');
        const element = chip.get('[data-testid="supplier-rating"]');

        expect(element.attributes('title')).toBe(chip.text().trim());
    });
});
