/**
 * Design System §3.1: three themes, and Odoo-inspired is the product default —
 * which is why it carries no attribute at all. A document that has never chosen
 * one is already correct.
 *
 * Persistence is device-level, and that is a deliberate shortfall rather than a
 * design: §3.1 and §9.1 both say the preference belongs to the *user profile*
 * and applies "on the next session" for that account. There is no account until
 * Module 1, so localStorage stands in — it survives a reload on this browser and
 * follows nobody to another machine. Module 1 has to move it to the profile and
 * teach the pre-paint script in welcome.blade.php to prefer a server-rendered
 * value over this one.
 *
 * The attribute itself is set before first paint by that script, not here. By
 * the time this module executes the document has already painted; what this
 * adds is switching at runtime and remembering the choice.
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

/**
 * The storage key. welcome.blade.php reads the same string, and the two have to
 * agree — a mismatch is silent: the theme switches, the preference is written,
 * and the next reload simply ignores it. ThemeFlashTest asserts they match.
 */
export const STORAGE_KEY = 'crm.theme';

function isTheme(value: string | null): value is Theme {
    return (THEMES as readonly string[]).includes(value ?? '');
}

/** The remembered preference, or null when there is none this browser can read. */
export function storedTheme(): Theme | null {
    try {
        const stored = window.localStorage.getItem(STORAGE_KEY);

        return isTheme(stored) ? stored : null;
    } catch {
        // Storage can throw outright — disabled, or a restricted browsing mode.
        return null;
    }
}

/** Apply a theme and remember it. The default is stored explicitly, so choosing
 *  it back after Dark Blue is a decision the next reload can see. */
export function setTheme(theme: Theme): void {
    applyTheme(theme);

    try {
        window.localStorage.setItem(STORAGE_KEY, theme);
    } catch {
        // A preference that cannot be written is still applied for this visit.
    }
}
