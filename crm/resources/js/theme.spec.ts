import { afterEach, describe, expect, it } from 'vitest';
import { applyTheme } from '@/theme';

/** A-66 (SH-48): the phone's browser bar follows the theme — the surface the context bar draws. */
describe('theme-color', () => {
    afterEach(() => {
        document.head.innerHTML = '';
        document.documentElement.removeAttribute('data-theme');
    });

    it('follows the applied theme’s surface token', () => {
        document.head.innerHTML = '<meta name="theme-color" content="#FFFFFF">'
            + '<style>:root { --color-surface: #FFFFFF; } :root[data-theme="midnight-obsidian"] { --color-surface: #111827; }</style>';
        const meta = () => document.querySelector('meta[name="theme-color"]')?.getAttribute('content');

        applyTheme('midnight-obsidian');
        expect(meta()).toBe('#111827');

        applyTheme('warm-editorial');
        expect(meta()).toBe('#FFFFFF');
    });
});
