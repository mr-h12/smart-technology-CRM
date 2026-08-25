<script setup lang="ts">
/**
 * Design System §5.1: the top context bar holds "page title, optional
 * breadcrumb, role-scoped search/filter controls, view toggle, language, theme
 * preference, and user menu."
 *
 * Four of those are here. Search and filters are role-scoped, and the view
 * toggle belongs to the §5.2 views — both need Module 1's permissions and a
 * list screen to filter, so neither is stubbed: an inert search box is a
 * promise the product cannot keep yet.
 *
 * The user menu shows the signed-in person's name and a sign-out control
 * (Point 5.1). It showed a fixed signed-out state until Module 1 had an
 * identity to put there; `user.signedOut` is still the string when there is no
 * session, which is what the login screen's own route never renders because it
 * is `meta.bare`.
 */
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import { setLocale, SUPPORTED, type Locale } from '@/i18n';
import { currentTheme, setTheme, THEMES, type Theme } from '@/theme';
import { useAuth } from '@/stores/auth';

defineProps<{
    /** Drawer state, so the control can announce what it does. */
    sidebarOpen: boolean;
}>();

const emit = defineEmits<{ toggleSidebar: [] }>();

const { t, locale } = useI18n();
const route = useRoute();
const router = useRouter();
const auth = useAuth();

/**
 * `SEC-05`. The store clears local state whatever the server answers, so a
 * token the server already revoked still ends the session here rather than
 * stranding the person in one that cannot do anything.
 */
async function signOut(): Promise<void> {
    await auth.logout();
    await router.replace({ name: 'login' });
}

/**
 * The title comes from the route, not from each page repeating it. A page that
 * declares no title gets the product name rather than an empty bar.
 */
const title = computed<string>(() => {
    const key = route.meta['titleKey'];

    return typeof key === 'string' ? t(key) : t('app.name');
});

/**
 * The segmented control needs to know which option is current, and
 * `currentTheme()` reads the DOM — it is not reactive, so the pressed state
 * would never repaint. This ref mirrors it for presentation only; theme.ts
 * remains the single thing that applies and persists the choice.
 */
const theme = ref<Theme>(currentTheme());

/**
 * One glyph per theme, so the control is legible when the names are too long
 * for the bar. §6.4 wants a state carried by more than colour: each button
 * carries an icon, a pressed border, and its name as the accessible label.
 */
const THEME_ICON: Record<Theme, readonly string[]> = {
    // Sun — the warm light theme.
    'warm-editorial': [
        'M10 5.5A4.5 4.5 0 1 0 10 14.5 4.5 4.5 0 0 0 10 5.5zM10 1a1 1 0 0 1 1 1v1.2a1 1 0 1 1-2 0V2a1 1 0 0 1 1-1zm0 15a1 1 0 0 1 1 1v1a1 1 0 1 1-2 0v-1a1 1 0 0 1 1-1zM3.2 3.2a1 1 0 0 1 1.4 0l.9.9a1 1 0 0 1-1.4 1.4l-.9-.9a1 1 0 0 1 0-1.4zm11.3 11.3a1 1 0 0 1 1.4 0l.9.9a1 1 0 0 1-1.4 1.4l-.9-.9a1 1 0 0 1 0-1.4zM1 10a1 1 0 0 1 1-1h1.2a1 1 0 1 1 0 2H2a1 1 0 0 1-1-1zm15.8 0a1 1 0 0 1 1-1H18a1 1 0 1 1 0 2h-1.2a1 1 0 0 1-1-1zM5.5 14.5a1 1 0 0 1 0 1.4l-.9.9a1 1 0 0 1-1.4-1.4l.9-.9a1 1 0 0 1 1.4 0zM16.8 3.2a1 1 0 0 1 0 1.4l-.9.9a1 1 0 1 1-1.4-1.4l.9-.9a1 1 0 0 1 1.4 0z',
    ],
    // Half-filled disc — the neutral, high-contrast theme. Two subpaths: a ring
    // drawn with opposite windings so its centre is a hole, then the filled
    // half. One path cannot do this — a single subpath pair with the same
    // winding fills the whole disc, which is what the first version shipped and
    // what looking at it in a browser caught.
    'clean-monochrome': [
        'M10 2a8 8 0 1 0 0 16 8 8 0 0 0 0-16zm0 1.6a6.4 6.4 0 1 1 0 12.8 6.4 6.4 0 0 1 0-12.8z',
        'M10 4.8a5.2 5.2 0 0 1 0 10.4z',
    ],
    // Crescent — the dark theme.
    'midnight-obsidian': [
        'M14.5 12.8A6.5 6.5 0 0 1 7.2 5.5a6.6 6.6 0 0 1 .4-2.2A7.5 7.5 0 1 0 16.7 12.4a6.6 6.6 0 0 1-2.2.4z',
    ],
};

function chooseLocale(next: Locale): void {
    // Direction, document language and the Accept-Language of the next API call
    // all move together — see i18n.ts.
    setLocale(next);
}

function chooseTheme(next: Theme): void {
    // Applies and remembers. The pre-paint script in welcome.blade.php reads
    // what this writes, which is what makes the choice survive a reload (§3.1).
    setTheme(next);
    theme.value = next;
}

function themeLabel(option: Theme): string {
    return t(`theme.${option}`);
}

function localeLabel(option: Locale): string {
    return option === 'ar' ? t('language.arabic') : t('language.english');
}

/** The code shown inside the dense control. A machine token, never translated. */
function localeCode(option: Locale): string {
    return option.toUpperCase();
}
</script>

<template>
    <header
        class="sticky top-0 z-20 flex items-center gap-2 border-b border-[var(--color-border)] bg-[var(--color-surface)] px-3 py-2 sm:gap-3 sm:px-4"
        data-testid="context-bar"
    >
        <!-- §4.3: the drawer control exists only where there is a drawer. -->
        <button
            type="button"
            class="context-control grid size-11 shrink-0 place-items-center rounded-lg text-[var(--color-text)] hover:bg-[var(--color-surface-muted)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-focus-ring)] lg:hidden"
            :aria-expanded="sidebarOpen"
            :aria-label="sidebarOpen ? t('nav.close') : t('nav.open')"
            data-testid="sidebar-toggle"
            @click="emit('toggleSidebar')"
        >
            <svg viewBox="0 0 20 20" class="size-5" aria-hidden="true" fill="currentColor">
                <path d="M3 5h14v2H3zm0 4h14v2H3zm0 4h14v2H3z" />
            </svg>
        </button>

        <h1 class="me-auto min-w-0 truncate text-start text-section-title text-balance">
            {{ title }}
        </h1>

        <!-- ── Theme ─────────────────────────────────────────────────────────
             Real buttons rather than a custom menu: §6.1 requires a keyboard
             path and visible focus on every control, and a native button has
             both without being re-implemented. Each is a toggle in a labelled
             group, so assistive technology reads the name and the pressed
             state rather than a position in a list. -->
        <div
            class="segmented flex items-center gap-0.5 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-muted)] p-1"
            role="group"
            :aria-label="t('theme.switch')"
            data-testid="theme-switch"
        >
            <button
                v-for="option in THEMES"
                :key="option"
                type="button"
                class="segmented__option grid size-11 place-items-center rounded-lg text-[var(--color-text-muted)] hover:text-[var(--color-text)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-focus-ring)]"
                :class="option === theme ? 'segmented__option--on' : ''"
                :aria-pressed="option === theme"
                :title="themeLabel(option)"
                :data-theme-option="option"
                @click="chooseTheme(option)"
            >
                <svg viewBox="0 0 20 20" class="size-5" aria-hidden="true" fill="currentColor">
                    <path v-for="(shape, index) in THEME_ICON[option]" :key="index" :d="shape" />
                </svg>
                <span class="sr-only">{{ themeLabel(option) }}</span>
            </button>
        </div>

        <!-- ── Language ──────────────────────────────────────────────────────
             The visible token is the locale code; the accessible name is the
             language. Neither button is disabled — a disabled control drops out
             of the tab order, so the current language became unreachable by
             keyboard and unannounceable. `aria-pressed` says the same thing
             without removing it. -->
        <div
            class="segmented flex items-center gap-0.5 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-muted)] p-1"
            role="group"
            :aria-label="t('language.switch')"
        >
            <button
                v-for="option in SUPPORTED"
                :key="option"
                type="button"
                class="segmented__option grid min-h-11 min-w-11 place-items-center rounded-lg px-1 text-table text-[var(--color-text-muted)] hover:text-[var(--color-text)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-focus-ring)]"
                :class="locale === option ? 'segmented__option--on' : ''"
                :aria-pressed="locale === option"
                :title="localeLabel(option)"
                :data-locale="option"
                @click="chooseLocale(option)"
            >
                <span aria-hidden="true" translate="no">{{ localeCode(option) }}</span>
                <span class="sr-only">{{ localeLabel(option) }}</span>
            </button>
        </div>

        <!-- ── Identity ──────────────────────────────────────────────────── -->
        <span
            class="hidden min-h-11 items-center gap-2 rounded-xl border border-[var(--color-border)] ps-2 pe-3 text-table text-[var(--color-text-muted)] md:inline-flex"
            data-testid="user-context"
        >
            <span
                class="grid size-7 shrink-0 place-items-center rounded-full bg-[var(--color-surface-muted)] text-[var(--color-status-neutral)]"
                aria-hidden="true"
            >
                <svg viewBox="0 0 20 20" class="size-4" fill="currentColor">
                    <path d="M10 10a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7zm0 1.5c-3 0-5.5 1.6-5.5 3.6V17h11v-1.9c0-2-2.5-3.6-5.5-3.6z" />
                </svg>
            </span>
            <span class="min-w-0 truncate">{{ auth.user.value?.name ?? t('user.signedOut') }}</span>
        </span>

        <!-- SEC-05's force-logout, applied by the person themselves. Present
             only with a session, because a sign-out control on the login screen
             is an action with nothing to act on. -->
        <button
            v-if="auth.isAuthenticated.value"
            type="button"
            class="context-control inline-flex min-h-11 items-center gap-2 rounded-xl border border-[var(--color-border)] px-3 text-table"
            data-testid="sign-out"
            @click="signOut"
        >
            <svg viewBox="0 0 20 20" class="size-4 shrink-0" fill="currentColor" aria-hidden="true">
                <path d="M11 3a1 1 0 0 1 0 2H6v10h5a1 1 0 1 1 0 2H5a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1zm3.3 3.3 3 3a1 1 0 0 1 0 1.4l-3 3a1 1 0 0 1-1.4-1.4L14.08 11H9a1 1 0 1 1 0-2h5.08l-1.18-1.3a1 1 0 0 1 1.4-1.4z" />
            </svg>
            <span>{{ t('user.signOut') }}</span>
        </button>
    </header>
</template>

<style scoped>
.context-control,
.segmented__option {
    touch-action: manipulation;
    -webkit-tap-highlight-color: transparent;
    transition-property: background-color, color, box-shadow;
    transition-duration: 160ms;
    transition-timing-function: ease-out;
}

/*
 * The selected segment. It is raised, bordered and re-coloured rather than only
 * tinted: §9.5 requires a state to be readable without relying on colour, and a
 * segmented control whose only signal is a hue is unusable in the monochrome
 * theme.
 */
.segmented__option--on {
    background-color: var(--color-surface);
    color: var(--color-text);
    box-shadow: var(--shadow-1);
    border: 1px solid var(--color-border-strong);
}

.segmented__option:not(.segmented__option--on):hover {
    background-color: var(--color-surface);
}

@media (prefers-reduced-motion: reduce) {
    .context-control,
    .segmented__option {
        transition: none;
    }
}
</style>
