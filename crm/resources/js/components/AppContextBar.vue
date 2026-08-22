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
 * The user menu shows a signed-out state and no name. There is no identity
 * before Module 1, and inventing one would put a fiction on every screen.
 */
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';
import { setLocale, SUPPORTED, type Locale } from '@/i18n';
import { currentTheme, setTheme, THEMES, type Theme } from '@/theme';

defineProps<{
    /** Drawer state, so the control can announce what it does. */
    sidebarOpen: boolean;
}>();

const emit = defineEmits<{ toggleSidebar: [] }>();

const { t, locale } = useI18n();
const route = useRoute();

/**
 * The title comes from the route, not from each page repeating it. A page that
 * declares no title gets the product name rather than an empty bar.
 */
const title = computed<string>(() => {
    const key = route.meta['titleKey'];

    return typeof key === 'string' ? t(key) : t('app.name');
});

function chooseLocale(next: Locale): void {
    // Direction, document language and the Accept-Language of the next API call
    // all move together — see i18n.ts.
    setLocale(next);
}

function chooseTheme(next: Theme): void {
    // Applies and remembers. The pre-paint script in welcome.blade.php reads
    // what this writes, which is what makes the choice survive a reload (§3.1).
    setTheme(next);
}

function themeLabel(theme: Theme): string {
    return t(`theme.${theme}`);
}
</script>

<template>
    <header
        class="flex items-center gap-3 border-b border-[var(--color-border)] bg-[var(--color-surface)] px-4 py-3"
        data-testid="context-bar"
    >
        <!-- §4.3: the drawer control exists only where there is a drawer. -->
        <button
            type="button"
            class="grid size-9 place-items-center rounded-md text-[var(--color-text)] hover:bg-[var(--color-surface-muted)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-focus-ring)] lg:hidden"
            :aria-expanded="sidebarOpen"
            :aria-label="sidebarOpen ? t('nav.close') : t('nav.open')"
            data-testid="sidebar-toggle"
            @click="emit('toggleSidebar')"
        >
            <svg viewBox="0 0 20 20" class="size-5" aria-hidden="true" fill="currentColor">
                <path d="M3 5h14v2H3zm0 4h14v2H3zm0 4h14v2H3z" />
            </svg>
        </button>

        <h1 class="me-auto truncate text-start text-section-title">
            {{ title }}
        </h1>

        <!-- Native selects on purpose. §6.1 requires a keyboard path and visible
             focus on every control; a custom menu would owe both, and neither
             belongs to this point. §6.3's select styling arrives with 4.4. -->
        <label class="flex items-center gap-2">
            <span class="sr-only">{{ t('theme.switch') }}</span>
            <select
                class="rounded-md border border-[var(--color-border)] bg-[var(--color-surface)] px-2 py-1 text-[var(--color-text)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-focus-ring)]"
                data-testid="theme-switch"
                :value="currentTheme()"
                @change="chooseTheme(($event.target as HTMLSelectElement).value as Theme)"
            >
                <option v-for="option in THEMES" :key="option" :value="option">
                    {{ themeLabel(option) }}
                </option>
            </select>
        </label>

        <nav :aria-label="t('language.switch')" class="flex items-center gap-1">
            <button
                v-for="option in SUPPORTED"
                :key="option"
                type="button"
                class="rounded-md px-2 py-1 text-[var(--color-text)] hover:bg-[var(--color-surface-muted)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-focus-ring)] disabled:font-semibold disabled:text-[var(--color-primary)]"
                :aria-current="locale === option ? 'true' : undefined"
                :disabled="locale === option"
                :data-locale="option"
                @click="chooseLocale(option)"
            >
                {{ option === 'ar' ? t('language.arabic') : t('language.english') }}
            </button>
        </nav>

        <span
            class="border-s border-[var(--color-border)] ps-3 text-[var(--color-text-muted)]"
            data-testid="user-context"
        >
            {{ t('user.signedOut') }}
        </span>
    </header>
</template>
