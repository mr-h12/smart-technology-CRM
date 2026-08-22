/**
 * Design System §3.1: three themes, and Odoo-inspired is the product default —
 * which is why it carries no attribute at all. A document that has never chosen
 * one is already correct.
 *
 * This applies a theme. It does not remember one: persistence and the pre-paint
 * script that stops the flash are point 4.3. Until then a reload returns to the
 * default, and that is a known gap rather than an oversight.
 */

export const THEMES = ['odoo', 'clean-white', 'dark-blue'] as const;

export type Theme = (typeof THEMES)[number];

export const DEFAULT_THEME: Theme = 'odoo';

export function applyTheme(theme: Theme): void {
    if (theme === DEFAULT_THEME) {
        // §3.1 again: the default is :root, so choosing it means removing the
        // attribute rather than setting it to a value tokens.css never matches.
        document.documentElement.removeAttribute('data-theme');

        return;
    }

    document.documentElement.setAttribute('data-theme', theme);
}

export function currentTheme(): Theme {
    const attribute = document.documentElement.getAttribute('data-theme');

    return THEMES.find((theme) => theme === attribute) ?? DEFAULT_THEME;
}
