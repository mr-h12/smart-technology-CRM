<script setup lang="ts">
/**
 * The application shell — Design System §5.1.
 *
 *   LTR: [Sidebar] [Top context bar] [Page content]
 *   RTL: [Page content] [Top context bar] [Sidebar]
 *
 * There is one DOM order and one stylesheet. The mirroring above is not a
 * second layout: the sidebar is the inline-start child of a flex row, and
 * `start` follows `dir`, which follows the locale (Coding Standards §11). Every
 * rule that could take a side is written on the inline axis, and
 * LogicalPropertiesTest fails the build if one is not.
 */
import { onMounted, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';
import AppSidebar from '@/components/AppSidebar.vue';
import AppContextBar from '@/components/AppContextBar.vue';

const { t } = useI18n();
const route = useRoute();

/** Drawer visibility below 1024px. Above it the rail is always present (§4.3). */
const sidebarOpen = ref(false);

/** Rail width at ≥1024px. Not persisted — that is point 4.3, with the theme. */
const collapsed = ref(false);

function closeSidebar(): void {
    sidebarOpen.value = false;
}

function onKeydown(event: KeyboardEvent): void {
    // §6.1: "Escape closes dialogs/menus without discarding silently." A drawer
    // over the content is one, and there is nothing here to discard.
    if (event.key === 'Escape' && sidebarOpen.value) {
        closeSidebar();
    }
}

onMounted(() => document.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown));

// Navigating with the drawer open would otherwise leave it covering the page
// the user just asked for.
watch(() => route.fullPath, closeSidebar);
</script>

<template>
    <div class="flex min-h-dvh bg-[var(--color-canvas)] text-[var(--color-text)]">
        <!-- §8 asks for a full keyboard path. Without this, reaching the page
             means tabbing the whole sidebar on every navigation. -->
        <a
            href="#page-content"
            class="sr-only rounded-lg border border-[var(--color-border-strong)] bg-[var(--color-surface)] px-3 py-2 shadow-[var(--shadow-2)] focus:not-sr-only focus:absolute focus:z-50 focus:m-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
        >
            {{ t('shell.skipToContent') }}
        </a>

        <AppSidebar
            :open="sidebarOpen"
            :collapsed="collapsed"
            @close="closeSidebar"
            @toggle-collapsed="collapsed = !collapsed"
        />

        <div class="flex min-w-0 flex-1 flex-col">
            <AppContextBar :sidebar-open="sidebarOpen" @toggle-sidebar="sidebarOpen = !sidebarOpen" />

            <!-- §4.2: 1600px standard desktop content width; the shell itself
                 stays full width so data tables can use it.
                 scroll-mt keeps the skip link honest: the context bar is sticky,
                 so jumping to this anchor would otherwise land the first line
                 underneath it. -->
            <main id="page-content" class="mx-auto w-full max-w-[1600px] flex-1 scroll-mt-20 p-4 sm:p-6">
                <RouterView />
            </main>
        </div>
    </div>
</template>
