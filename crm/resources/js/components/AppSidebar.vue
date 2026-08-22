<script setup lang="ts">
/**
 * Design System §5.1: the sidebar is 256px expanded and 72px collapsed, grouped
 * by business module, and "Use logical CSS properties, not left/right-only
 * positioning."
 *
 * §4.3 gives it three behaviours by width: a drawer below 640px, and a
 * persistent collapsible rail at 1024px and above. Between them the document is
 * silent, so this treats 640–1023px as a drawer too — "persistent" is promised
 * only at ≥1024, and promising it earlier would be inventing a requirement.
 *
 * Nothing here decides what a user may see. SEC-09: hiding an item is
 * presentation, and the API still enforces. The filter that reads `permission`
 * arrives with Module 1.
 */
import { useI18n } from 'vue-i18n';
import { NAVIGATION } from '@/navigation';

const props = defineProps<{
    /** Drawer visibility. Ignored at ≥1024px, where the rail is always present. */
    open: boolean;
    /** Rail width at ≥1024px: 72px when true, 256px when false. */
    collapsed: boolean;
}>();

const emit = defineEmits<{
    close: [];
    toggleCollapsed: [];
}>();

const { t } = useI18n();

function onBackdrop(): void {
    emit('close');
}
</script>

<template>
    <!-- The backdrop is symmetric on both axes, so inset-0 carries no direction
         and needs no logical form. It exists only below 1024px. -->
    <div
        v-if="props.open"
        class="fixed inset-0 z-30 bg-black/40 lg:hidden"
        data-testid="sidebar-backdrop"
        @click="onBackdrop"
    />

    <aside
        :class="[
            'app-sidebar z-40 flex shrink-0 flex-col border-e bg-[var(--color-surface)]',
            'border-[var(--color-border)] shadow-[var(--shadow-2)] lg:shadow-none',
            props.open ? 'app-sidebar--open' : 'app-sidebar--closed',
            props.collapsed ? 'app-sidebar--collapsed' : '',
        ]"
        :aria-label="t('nav.primary')"
        data-testid="sidebar"
    >
        <div class="flex items-center gap-3 px-4 py-4">
            <!-- The product mark is not a translated string, so it is not text.
                 The accessible name comes from the lang files. -->
            <span
                class="grid size-8 shrink-0 place-items-center rounded-md bg-[var(--color-primary)] text-[var(--color-primary-text)]"
                :aria-label="t('app.mark')"
                role="img"
            >
                <svg viewBox="0 0 20 20" class="size-5" aria-hidden="true" fill="currentColor">
                    <path d="M4 5h12v2H4zm0 4h12v2H4zm0 4h8v2H4z" />
                </svg>
            </span>

            <span v-if="!props.collapsed" class="truncate text-card-title lg:inline">
                {{ t('app.name') }}
            </span>
        </div>

        <nav class="flex-1 overflow-y-auto px-2 pb-4">
            <div v-for="group in NAVIGATION" :key="group.labelKey" class="mb-4">
                <p
                    v-if="!props.collapsed"
                    class="px-2 pb-1 text-start text-table text-[var(--color-text-muted)] uppercase"
                >
                    {{ t(group.labelKey) }}
                </p>

                <ul>
                    <li v-for="item in group.items" :key="item.name">
                        <RouterLink
                            :to="{ name: item.name }"
                            :title="props.collapsed ? t(item.labelKey) : undefined"
                            class="flex items-center gap-3 rounded-md px-2 py-2 text-[var(--color-text)] hover:bg-[var(--color-surface-muted)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-focus-ring)]"
                            active-class="bg-[var(--color-surface-muted)] font-medium"
                        >
                            <svg viewBox="0 0 20 20" class="size-5 shrink-0" aria-hidden="true" fill="currentColor">
                                <path :d="item.icon" />
                            </svg>

                            <span v-if="!props.collapsed" class="truncate">{{ t(item.labelKey) }}</span>
                        </RouterLink>
                    </li>
                </ul>
            </div>
        </nav>

        <!-- §4.3 gives the collapsed rail to ≥1024px only, so the control that
             produces it does not exist below that width. -->
        <button
            type="button"
            class="hidden items-center gap-3 border-t border-[var(--color-border)] px-4 py-3 text-[var(--color-text-muted)] hover:text-[var(--color-text)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-focus-ring)] lg:flex"
            :aria-expanded="!props.collapsed"
            data-testid="sidebar-collapse"
            @click="emit('toggleCollapsed')"
        >
            <svg viewBox="0 0 20 20" class="size-5 shrink-0" aria-hidden="true" fill="currentColor">
                <path d="M7 4h2v12H7zM11 4h2v12h-2z" />
            </svg>

            <span v-if="!props.collapsed">{{ t('nav.collapse') }}</span>
            <span class="sr-only">{{ props.collapsed ? t('nav.expand') : t('nav.collapse') }}</span>
        </button>
    </aside>
</template>

<style scoped>
/*
 * The drawer slides on the inline axis, and `inset-inline-start` is what makes
 * that mirror. A transform would not: translateX is physical by definition, so
 * an RTL drawer built on -translate-x-full slides in from the wrong edge and
 * every utility class still reads as correct.
 *
 * Below 1024px the sidebar is fixed and off-canvas until opened. At 1024px it
 * stops being positioned at all and becomes part of the flow, which is what
 * §4.3 means by persistent.
 */
.app-sidebar {
    position: fixed;
    inset-block: 0;
    /* §5.1: 256px expanded. Declared here rather than as a w-64 utility because
       the media query below is scoped and outranks it — an earlier version set
       `inline-size: auto` at ≥1024px and won, so the rail sized itself to its
       own text: 237px in English and 300px in Arabic. Measured in a browser,
       not noticed by any of the checks in this repository. */
    inline-size: 16rem;
    transition: inset-inline-start 200ms ease;
}

.app-sidebar--open {
    inset-inline-start: 0;
}

.app-sidebar--closed {
    inset-inline-start: -16rem;
}

@media (min-width: 1024px) {
    .app-sidebar,
    .app-sidebar--open,
    .app-sidebar--closed {
        position: static;
        inset-inline-start: auto;
        transition: inline-size 200ms ease;
    }

    /* §5.1: 72px collapsed. Only at this width — §4.3 gives the rail to
       ≥1024px, and shrinking a drawer to 72px would hide its own labels. */
    .app-sidebar--collapsed {
        inline-size: 4.5rem;
    }
}

@media (prefers-reduced-motion: reduce) {
    .app-sidebar {
        transition: none;
    }
}
</style>
