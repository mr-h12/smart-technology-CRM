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
 * presentation, and the API still enforces. Point 5.2 wired the filter that
 * reads `permission`; a person who deletes it from the DOM reaches a route
 * whose guard sends them to the denial screen, and an endpoint that refuses
 * them regardless.
 */
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';
import { NAVIGATION, type BadgeableItem, type NavigationGroup } from '@/navigation';
import { readBadges } from '@/services/badges';
import { useAuth } from '@/stores/auth';

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
const auth = useAuth();

/**
 * §5.1: "do not show a module, action, count, or record link that the role is
 * not permitted to access."
 *
 * A group whose every item is filtered out is dropped with it — an empty
 * heading is a menu section that says a module exists and refuses to show it.
 */
const groups = computed<NavigationGroup[]>(() =>
    NAVIGATION
        .map((group) => ({
            labelKey: group.labelKey,
            items: group.items.filter(
                // `holdsPermission`, not `hasPermission`: §8 asks which
                // screens belong to this role, and §3.1's unconditional access
                // answers a different question. The Super Admin's nine grants
                // are all `admin.*`, so this draws §13's screens and not the
                // business ones. The API is still the gate (`SEC-09`).
                (item) => item.permission === null || auth.holdsPermission(item.permission),
            ),
        }))
        .filter((group) => group.items.length > 0),
);

/**
 * §5.1's counters (Module 8 · 3.3): `GET /badges` on mount and on every route
 * change, so acting on `/approvals` and leaving it moves the number. A zero is
 * not drawn — it is a counter, not a status — and a failed read draws nothing:
 * the menu is not an error state.
 */
const badges = ref<Partial<Record<BadgeableItem, number>>>({});
const route = useRoute();

watch(() => route.fullPath, async () => {
    try {
        badges.value = await readBadges();
    } catch {
        badges.value = {};
    }
}, { immediate: true });

function onBackdrop(): void {
    emit('close');
}
</script>

<template>
    <!-- The backdrop is symmetric on both axes, so inset-0 carries no direction
         and needs no logical form. It exists only below 1024px. Opacity is the
         one thing animated here: it is compositor-only and mirrors nothing.
         It dims with a filter and not with a colour — see the stylesheet. -->
    <Transition name="backdrop">
        <div
            v-if="props.open"
            class="app-sidebar__backdrop fixed inset-0 z-30 lg:hidden"
            data-testid="sidebar-backdrop"
            @click="onBackdrop"
        />
    </Transition>

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
        <!-- ── Header ────────────────────────────────────────────────────────
             The collapse control stands where the product mark used to, on the
             owner's instruction (2026-08-29). §5.1 asks this rail for the screens
             a role is permitted to open and never for a logo, so the mark was
             decorative — and it was holding the most prominent slot in the
             sidebar to show an image that did nothing when clicked.

             §4.3 gives the collapsed rail to ≥1024px only, so the control does
             not exist below that width; there the sidebar is a drawer, closed by
             its backdrop or by Escape. Nothing else lived in this slot to lose.

             Icon only, in both states, and the name is always the hidden one.
             That is not a detail that can be dropped along with the visible
             label: a button whose only content is an aria-hidden svg is
             announced as "button" and nothing more. -->
        <div
            :class="[
                'flex min-h-16 items-center gap-3 border-b border-[var(--color-border)] py-3',
                props.collapsed ? 'justify-center px-0' : 'px-4',
            ]"
        >
            <button
                type="button"
                :class="[
                    'app-sidebar__toggle hidden size-9 shrink-0 place-items-center rounded-lg lg:grid',
                    'border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-text-muted)]',
                    'hover:bg-[var(--color-surface-muted)] hover:text-[var(--color-text)]',
                    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-focus-ring)]',
                ]"
                :aria-expanded="!props.collapsed"
                data-testid="sidebar-collapse"
                @click="emit('toggleCollapsed')"
            >
                <svg viewBox="0 0 20 20" class="size-5" aria-hidden="true" fill="currentColor">
                    <path d="M3 4h14a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1zm4 1v10h10V5z" />
                </svg>

                <span class="sr-only">{{ props.collapsed ? t('nav.expand') : t('nav.collapse') }}</span>
            </button>

            <span
                v-if="!props.collapsed"
                class="min-w-0 truncate text-card-title text-[var(--color-text)]"
                :title="t('app.name')"
                translate="no"
            >
                {{ t('app.name') }}
            </span>
        </div>

        <!-- ── Navigation ────────────────────────────────────────────────── -->
        <nav class="app-sidebar__scroll flex-1 overflow-y-auto px-2 py-3">
            <div v-for="group in groups" :key="group.labelKey" class="mb-5 last:mb-0">
                <p
                    v-if="!props.collapsed"
                    class="app-sidebar__group px-3 pb-2 text-start text-table text-[var(--color-text-muted)] uppercase"
                >
                    {{ t(group.labelKey) }}
                </p>

                <ul class="flex flex-col gap-1">
                    <li v-for="item in group.items" :key="item.name">
                        <RouterLink
                            :to="{ name: item.name }"
                            :title="props.collapsed ? t(item.labelKey) : undefined"
                            :class="[
                                'app-sidebar__link group relative flex min-h-11 items-center gap-3 rounded-lg',
                                'text-[var(--color-text)] hover:bg-[var(--color-surface-muted)]',
                                'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-focus-ring)]',
                                props.collapsed ? 'justify-center px-0' : 'px-3',
                            ]"
                            active-class="app-sidebar__link--active bg-[var(--color-surface-muted)] font-medium"
                        >
                            <svg
                                viewBox="0 0 20 20"
                                class="app-sidebar__icon size-5 shrink-0 text-[var(--color-text-muted)]"
                                aria-hidden="true"
                                fill="currentColor"
                            >
                                <path :d="item.icon" />
                            </svg>

                            <span v-if="!props.collapsed" class="min-w-0 truncate">{{ t(item.labelKey) }}</span>
                            <span
                                v-if="item.badge !== undefined && (badges[item.badge] ?? 0) > 0"
                                class="app-sidebar__badge ms-auto rounded-full px-2 py-0.5 text-table tabular-nums"
                                :data-testid="`nav-badge-${item.name}`"
                            >
                                {{ badges[item.badge] }}
                            </span>
                        </RouterLink>
                    </li>
                </ul>
            </div>
        </nav>

    </aside>
</template>

<style scoped>
/*
 * The drawer slides on the inline axis, and `inset-inline-start` is what makes
 * that mirror. A transform would not: translateX is physical by definition, so
 * an RTL drawer built on -translate-x-full slides in from the wrong edge and
 * every utility class still reads as correct.
 *
 * That is a deliberate departure from the "animate only transform/opacity"
 * advice in the Web Interface Guidelines. The guideline is about compositor
 * cost; §5.1 and Coding Standards §11 are about the layout being correct in
 * Arabic, and the project's own sources take precedence. The backdrop, which
 * has no side, does animate opacity.
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
    transition: inset-inline-start 220ms cubic-bezier(0.22, 1, 0.36, 1);
}

.app-sidebar--open {
    inset-inline-start: 0;
}

.app-sidebar--closed {
    inset-inline-start: -16rem;
}

/* A drawer that hands its scroll to the page underneath is the classic sheet
   defect: the list bottoms out and the document behind starts moving. */
.app-sidebar__scroll {
    overscroll-behavior: contain;
}

/*
 * The scrim dims with a filter rather than with a tinted colour, and that is
 * not a stylistic preference — it is the only theme-correct option in the
 * token set.
 *
 * A scrim has to darken in every theme. The 22 tokens contain no overlay
 * colour, and no single one behaves: `--color-text` is near-black in the two
 * light themes and near-white in Midnight Obsidian, so tinting with it lays a
 * *light* veil over dark content. That is what the first version of this did —
 * measured in the browser as `#e0e7ff` at 40%, not noticed by reading it.
 * `--color-canvas` fails the same way in the opposite direction.
 *
 * `brightness()` takes what is actually behind the element and darkens it, so
 * it is correct in all three themes and hard-codes nothing. A dedicated
 * `--color-overlay` token would be the better answer and is owed to the design
 * system as a D-73 follow-up; until that is approved, this needs no token at
 * all. Where backdrop-filter is unsupported the drawer still separates by its
 * own shadow and border.
 */
.app-sidebar__backdrop {
    backdrop-filter: brightness(0.45) blur(2px);
}

/* §4.1 gives no tracking token, so this is presentation of an existing size
   rather than a new one — the size itself is still the named `text-table`. */
.app-sidebar__group {
    letter-spacing: 0.08em;
}

.app-sidebar__link,
.app-sidebar__toggle {
    /* Removes the 300ms double-tap delay on touch, and keeps the tap flash from
       being whatever the platform picked. */
    touch-action: manipulation;
    -webkit-tap-highlight-color: transparent;
    transition-property: background-color, color;
    transition-duration: 160ms;
    transition-timing-function: ease-out;
}

/*
 * The active indicator. A pill on the inline-start edge of the row, drawn as a
 * pseudo-element so it costs no markup and cannot be tabbed to. `inset-inline-start`
 * is what puts it on the correct edge in Arabic; scaleY is on the block axis,
 * which RTL does not touch.
 */
.app-sidebar__link::before {
    content: '';
    position: absolute;
    inset-block: 0.5rem;
    inset-inline-start: 0;
    inline-size: 3px;
    border-radius: 9999px;
    background-color: var(--color-primary);
    transform: scaleY(0);
    transform-origin: center;
    opacity: 0;
    transition:
        transform 180ms cubic-bezier(0.22, 1, 0.36, 1),
        opacity 180ms ease-out;
}

.app-sidebar__link--active::before {
    transform: scaleY(1);
    opacity: 1;
}

/* Colour is reinforcement, never the only signal (§9.5): the active row also
   carries the pill, a raised surface and a heavier weight. */
.app-sidebar__link--active .app-sidebar__icon,
.app-sidebar__link:hover .app-sidebar__icon {
    color: var(--color-primary);
}

.app-sidebar__icon {
    transition: color 160ms ease-out;
}

/* §5.1's counter: the primary tone at chip strength, the number is the meaning. */
.app-sidebar__badge {
    background-color: color-mix(in srgb, var(--color-primary) 14%, transparent);
    color: var(--color-primary);
}

@media (min-width: 1024px) {
    .app-sidebar,
    .app-sidebar--open,
    .app-sidebar--closed {
        position: static;
        inset-inline-start: auto;
        transition: inline-size 220ms cubic-bezier(0.22, 1, 0.36, 1);
    }

    /* §5.1: 72px collapsed. Only at this width — §4.3 gives the rail to
       ≥1024px, and shrinking a drawer to 72px would hide its own labels. */
    .app-sidebar--collapsed {
        inline-size: 4.5rem;
    }
}

/* The backdrop's own fade. Named transition rather than a utility so the
   reduced-motion rule below can reach it. */
.backdrop-enter-active,
.backdrop-leave-active {
    transition: opacity 200ms ease-out;
}

.backdrop-enter-from,
.backdrop-leave-to {
    opacity: 0;
}

@media (prefers-reduced-motion: reduce) {
    .app-sidebar,
    .app-sidebar__link,
    .app-sidebar__link::before,
    .app-sidebar__icon,
    .app-sidebar__toggle,
    .backdrop-enter-active,
    .backdrop-leave-active {
        transition: none;
    }

    .app-sidebar__link::before {
        transform: scaleY(1);
    }
}
</style>
