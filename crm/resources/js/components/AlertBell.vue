<script setup lang="ts">
/**
 * Module 16, Point 1 (`D-153`) — the bell in the context bar.
 *
 * The unread count is read on mount and on every page change, the way the
 * sidebar reads `GET /badges`; a zero draws nothing and a failed read draws
 * nothing — the header is not an error state. The list is read only when the
 * bell is opened. Each alert is one sentence linking to its record; reading
 * one, or all, lowers the number. Nothing is deleted here: an alert leaves the
 * list when its cause is fixed.
 */
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRoute, type RouteLocationRaw } from 'vue-router';
import { displayDate } from '@/domain/displayDate';
import { listAlerts, markAlertRead, markAllAlertsRead, readUnreadAlerts, type Alert } from '@/services/alerts';

const { t, locale } = useI18n();
const route = useRoute();

const unread = ref(0);
const open = ref(false);
const alerts = ref<Alert[] | null>(null);
const failed = ref(false);
const bell = ref<HTMLButtonElement | null>(null);
const panel = ref<HTMLElement | null>(null);

watch(() => route.fullPath, async () => {
    try {
        unread.value = await readUnreadAlerts();
    } catch {
        unread.value = 0;
    }
}, { immediate: true });

async function load(): Promise<void> {
    failed.value = false;
    try {
        alerts.value = await listAlerts();
    } catch {
        failed.value = true;
    }
}

async function toggle(): Promise<void> {
    open.value = !open.value;
    if (open.value) {
        await load();
    }
}

async function close(): Promise<void> {
    open.value = false;
    await nextTick();
    bell.value?.focus();
}

/** A click outside the bell and its list closes the list; the focus stays where the click put it. */
function onDocumentClick(event: MouseEvent): void {
    const target = event.target as Node | null;
    if (open.value && target !== null && !bell.value?.contains(target) && !panel.value?.contains(target)) {
        open.value = false;
    }
}
/** Escape closes the list wherever the focus is — a read row's button is gone, so the focus may be on the page. */
function onDocumentKey(event: KeyboardEvent): void {
    if (open.value && event.key === 'Escape') {
        void close();
    }
}
document.addEventListener('click', onDocumentClick);
document.addEventListener('keydown', onDocumentKey);
onBeforeUnmount(() => {
    document.removeEventListener('click', onDocumentClick);
    document.removeEventListener('keydown', onDocumentKey);
});

async function read(alert: Alert): Promise<void> {
    unread.value = await markAlertRead(alert.id);
    alert.read = true;
    // The button just pressed is gone with the unread state; keep the focus on its row (§6.1).
    await nextTick();
    panel.value?.querySelector<HTMLElement>(`[data-alert-id="${alert.id}"] a`)?.focus();
}

async function readAll(): Promise<void> {
    unread.value = await markAllAlertsRead();
    for (const alert of alerts.value ?? []) {
        alert.read = true;
    }
}

const text = (value: unknown): string => (typeof value === 'string' && value !== '' ? value : '—');

/** The sentence for one alert, from what the alert carries. */
function sentence(alert: Alert): string {
    const d = alert.details;
    switch (alert.kind) {
        case 'quotation_expiring':
            return t('alerts.kind.quotation_expiring', {
                number: text(d['number']),
                date: typeof d['valid_until'] === 'string' ? displayDate(d['valid_until'], locale.value) : '—',
            });
        case 'account_locked':
            return t('alerts.kind.account_locked', { name: text(d['name']) });
        case 'job_failed':
            return t('alerts.kind.job_failed', { job: text(d['job']) });
        case 'storage_over_limit':
            return t('alerts.kind.storage_over_limit', { used: text(d['used_percent']), limit: text(d['limit_percent']) });
        default:
            return t(`alerts.kind.${alert.kind}`);
    }
}

/** Where an alert leads: the quotation, the users screen, or the system screen. */
function destination(alert: Alert): RouteLocationRaw {
    if (alert.kind === 'quotation_expiring' && alert.subject_id !== null) {
        return { name: 'quotation-detail', params: { id: alert.subject_id } };
    }

    return alert.kind === 'account_locked' ? { name: 'users' } : { name: 'home' };
}

const label = computed(() => (unread.value > 0 ? t('alerts.bellCount', { count: unread.value }) : t('alerts.bell')));
</script>

<template>
    <div class="relative">
        <button
            ref="bell"
            type="button"
            class="context-control relative grid size-11 shrink-0 place-items-center rounded-lg text-[var(--color-text)] hover:bg-[var(--color-surface-muted)]"
            :aria-label="label"
            :aria-expanded="open"
            aria-controls="alert-list"
            :title="label"
            data-testid="alert-bell"
            @click="toggle()"
        >
            <svg viewBox="0 0 20 20" class="size-5" aria-hidden="true" fill="currentColor">
                <path d="M10 2a5 5 0 0 0-5 5v3.6l-1.4 2.6A1 1 0 0 0 4.5 15h11a1 1 0 0 0 .9-1.8L15 10.6V7a5 5 0 0 0-5-5zm-2 14a2 2 0 0 0 4 0z" />
            </svg>
            <span
                v-if="unread > 0"
                class="alert-bell__count absolute -top-0.5 -end-0.5 min-w-5 rounded-full px-1 text-center text-[0.6875rem] leading-5 font-semibold tabular-nums"
                aria-hidden="true"
                data-testid="alert-bell-count"
            >{{ unread }}</span>
        </button>

        <div
            v-if="open"
            id="alert-list"
            ref="panel"
            class="absolute end-0 top-full z-30 mt-2 flex max-h-[70vh] w-[min(22rem,calc(100vw-2rem))] flex-col overflow-hidden rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-raised)] shadow-lg"
            role="region"
            :aria-label="t('alerts.title')"
            data-testid="alert-list"
            @keydown.esc.stop="close()"
        >
            <div class="flex items-center justify-between gap-2 border-b border-[var(--color-border)] px-3 py-2">
                <p class="font-semibold">{{ t('alerts.title') }}</p>
                <button
                    v-if="alerts !== null && alerts.length > 0 && unread > 0"
                    type="button"
                    class="row-action min-h-11 rounded-lg px-3 text-table"
                    data-testid="alert-read-all"
                    @click="readAll()"
                >{{ t('alerts.readAll') }}</button>
            </div>

            <p v-if="failed" class="px-3 py-4 text-table text-[var(--color-text-muted)]" data-testid="alert-error">{{ t('alerts.error') }}</p>
            <p v-else-if="alerts !== null && alerts.length === 0" class="px-3 py-4 text-table text-[var(--color-text-muted)]" data-testid="alert-empty">{{ t('alerts.empty') }}</p>
            <ul v-else-if="alerts !== null" class="flex flex-col overflow-y-auto">
                <li
                    v-for="alert in alerts"
                    :key="alert.id"
                    class="flex items-start gap-2 border-b border-[var(--color-border)] px-3 py-2 last:border-b-0"
                    :class="alert.read ? '' : 'bg-[var(--color-surface-muted)]'"
                    :data-alert-id="alert.id"
                    :data-kind="alert.kind"
                    :data-read="alert.read ? 'true' : 'false'"
                    data-testid="alert-row"
                >
                    <span v-if="!alert.read" class="alert-bell__dot mt-2 size-2 shrink-0 rounded-full" aria-hidden="true" />
                    <div class="min-w-0 flex-1">
                        <!-- `block` (Module 16 · 6's browser check): a sentence that wraps is one tap target,
                             not two line boxes with a gap between them at 375px, and at least
                             44px tall like every other control here. -->
                        <RouterLink :to="destination(alert)" class="row-link block min-h-11 text-table [overflow-wrap:anywhere]" @click="open = false">{{ sentence(alert) }}</RouterLink>
                        <span v-if="!alert.read" class="sr-only">{{ t('alerts.unread') }}</span>
                        <span
                            v-if="alert.occurrences > 1"
                            class="ms-2 rounded-full bg-[var(--color-surface-muted)] px-2 text-table tabular-nums"
                            :title="t('alerts.occurrences', { count: alert.occurrences })"
                            data-testid="alert-occurrences"
                        ><span aria-hidden="true">×{{ alert.occurrences }}</span><span class="sr-only">{{ t('alerts.occurrences', { count: alert.occurrences }) }}</span></span>
                    </div>
                    <button
                        v-if="!alert.read"
                        type="button"
                        class="row-action min-h-11 shrink-0 rounded-lg px-2 text-table"
                        data-testid="alert-read"
                        @click="read(alert)"
                    >{{ t('alerts.markRead') }}</button>
                </li>
            </ul>
        </div>
    </div>
</template>

<style scoped>
.alert-bell__count {
    background-color: var(--color-primary);
    color: var(--color-primary-text);
}

.alert-bell__dot {
    background-color: var(--color-primary);
}
</style>
