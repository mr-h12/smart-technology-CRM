import { readFileSync } from 'node:fs';
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

/**
 * The component's own source, read as text.
 *
 * `jsdom` does not apply an SFC's scoped `<style>`, so `getComputedStyle` here
 * reports the browser default for every rule below — which is exactly how the
 * defect this file now guards got through Point 4.0 with a green suite. The
 * stylesheet is therefore asserted as source. Crude, and the only thing in
 * reach that can actually fail when the colour goes faint again.
 */
const SOURCE = readFileSync('resources/js/components/suppliers/SupplierRatingChip.vue', 'utf8');

/** The body of one `.rating-chip--<rating>` rule. */
function ruleFor(rating: string): string {
    const match = new RegExp(`\\.rating-chip--${rating}\\s*\\{([^}]*)\\}`).exec(SOURCE);

    expect(match, `no .rating-chip--${rating} rule found`).not.toBeNull();

    return match?.[1] ?? '';
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

/**
 * Point 5.0 — the defect the owner found on the screen, and the checks that
 * missed it.
 *
 * §7.1: "The colour appears as a chip beside the supplier name on **every**
 * screen." Design System §6.4 says how: "Success … **Green icon + text/chip**;
 * never color alone", and "Danger … **Red icon** + label".
 *
 * Point 4.0 read the second half of that sentence and delivered the word alone.
 * The chip was therefore a 14% tint of `#166534` over white — `#DEE9E3`, which
 * is grey — carrying no icon, and visually identical in weight to the neutral
 * status chip beside it. Every test above passed throughout, because each one
 * asks about text or about class names and none of them can see a colour.
 */
describe('SupplierRatingChip — §6.4 asks for an icon AND text', () => {
    it.each(RATINGS)('draws a circle beside the word for %s', (rating) => {
        const dot = mountChip(rating).find('[data-testid="supplier-rating-dot"]');

        expect(dot.exists()).toBe(true);
    });

    /** The word is the meaning; the circle repeats it for a sighted reader only. */
    it.each(RATINGS)('keeps the circle decorative for %s, so the word stays the whole accessible name', (rating) => {
        const chip = mountChip(rating);
        const element = chip.get('[data-testid="supplier-rating"]');

        expect(chip.get('[data-testid="supplier-rating-dot"]').attributes('aria-hidden')).toBe('true');
        // The svg contributes no text, so `title` and the visible label still agree.
        expect(element.attributes('title')).toBe(chip.text().trim());
    });

    /**
     * The half that actually failed on screen. A chip whose border is
     * `transparent` and whose fill is a low-percentage mix reads as grey, and
     * §7.1 asks for a colour that *appears*.
     */
    it.each(RATINGS)('paints a real border for %s rather than leaving it transparent', (rating) => {
        // The *value*, not the rule text: every coloured border here is a
        // `color-mix(... , transparent)`, so searching the whole rule for the
        // word would fail on a border that is doing its job.
        const declaration = /border-color:\s*([^;]+);/.exec(ruleFor(rating));

        expect(declaration, `no border-color in .rating-chip--${rating}`).not.toBeNull();
        expect(declaration?.[1]?.trim()).not.toBe('transparent');
    });
});
