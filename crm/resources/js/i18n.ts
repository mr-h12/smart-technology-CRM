import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';

// §14.2 and §1: Arabic and English from the first release, and only those.
export const SUPPORTED = ['ar', 'en'] as const;

export type Locale = (typeof SUPPORTED)[number];

// Direction is a property of the locale, not of a screen. Coding Standards §11
// is explicit that RTL/LTR is controlled by locale rather than by duplicated
// screens, so nothing else in the application decides this.
const DIRECTION: Record<Locale, 'rtl' | 'ltr'> = { ar: 'rtl', en: 'ltr' };

export function isSupported(value: string): value is Locale {
    return (SUPPORTED as readonly string[]).includes(value);
}

/**
 * The server already resolved the locale and rendered it onto <html> (see
 * welcome.blade.php and SetLocaleFromRequest). Reading it back rather than
 * negotiating again keeps one decision in one place: if the client picked
 * independently, a reload could change the language without anyone asking.
 */
export function initialLocale(): Locale {
    const fromDocument = document.documentElement.lang.split('-')[0] ?? '';

    return isSupported(fromDocument) ? fromDocument : 'ar';
}

export const i18n = createI18n({
    legacy: false,
    locale: initialLocale(),
    // §1 makes this an Arabic-first system, and APP_LOCALE agrees. A missing
    // Arabic key falls back to English rather than rendering a raw key.
    fallbackLocale: 'en',
    messages: { ar, en },
});

/**
 * Switching language also switches direction and the document language, which
 * is what api.ts sends as Accept-Language — so the next request is localised to
 * match what the user is looking at.
 */
export function setLocale(locale: Locale): void {
    i18n.global.locale.value = locale;

    document.documentElement.lang = locale;
    document.documentElement.dir = DIRECTION[locale];
}
