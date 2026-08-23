<script setup lang="ts">
/**
 * The infrastructure health card.
 *
 * Everything on this page is measured or returned. `/api/v1/ping` answers with
 * a service name and a server time (OpenAPI §4.1's envelope, plus the request
 * id §3.3 puts on every response), and the round trip is timed in this browser.
 * Nothing else is claimed.
 *
 * In particular there are no database or cache markers, and their absence is
 * deliberate. ST-08 asks for a `/health` endpoint reporting each service
 * explicitly; routes/api.php records why that is not this route, and until it
 * exists the only honest thing to show for a dependency is nothing. A green
 * badge the frontend invented would be worse than no badge — it would report
 * health nobody checked.
 */
import { computed, onMounted, ref } from 'vue';
import { apiGet } from '@/api';
import { useI18n } from 'vue-i18n';
import LoadingState from '@/components/states/LoadingState.vue';
import ErrorState from '@/components/states/ErrorState.vue';

interface Ping {
    service: string;
    time: string;
}

/** A machine token, not copy — it is never translated and never localised. */
const ENDPOINT = '/api/v1/ping';

const { t, locale } = useI18n();

const state = ref<'loading' | 'ok' | 'error'>('loading');
const ping = ref<Ping | null>(null);
const requestId = ref<string | null>(null);
const error = ref<string | null>(null);
const latency = ref<number | null>(null);

/**
 * Round trip as the browser sees it: request start to response parsed. It is
 * not server processing time and is not labelled as such — this includes the
 * network, nginx, php-fpm and JSON parsing.
 */
async function load(): Promise<void> {
    state.value = 'loading';
    latency.value = null;

    const started = performance.now();

    try {
        const result = await apiGet<Ping>('/ping');
        latency.value = performance.now() - started;
        ping.value = result.data;
        requestId.value = result.requestId;
        state.value = 'ok';
    } catch (e) {
        latency.value = performance.now() - started;
        error.value = e instanceof Error ? e.message : String(e);
        state.value = 'error';
    }
}

/** Intl rather than a hand-built string: §14.2 ships Arabic from day one. */
const serverTime = computed<string>(() => {
    const raw = ping.value?.time;

    if (raw === undefined) {
        return '';
    }

    const parsed = new Date(raw);

    if (Number.isNaN(parsed.getTime())) {
        // Unparseable is data, not an error state — show what arrived.
        return raw;
    }

    // DB-08: stored in UTC, displayed in the reader's zone.
    return new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'medium' }).format(parsed);
});

const roundTrip = computed<string>(() => {
    if (latency.value === null) {
        return '';
    }

    return new Intl.NumberFormat(locale.value, {
        style: 'unit',
        unit: 'millisecond',
        maximumFractionDigits: 0,
    }).format(latency.value);
});

const direction = computed<string>(() => (locale.value === 'ar' ? 'rtl' : 'ltr'));

const statusKey = computed<string>(() => {
    if (state.value === 'loading') {
        return 'ping.status.checking';
    }

    return state.value === 'ok' ? 'ping.status.operational' : 'ping.status.unreachable';
});

onMounted(load);
</script>

<template>
    <section class="mx-auto w-full max-w-3xl">
        <article
            class="overflow-hidden rounded-2xl border border-[var(--color-border)] bg-[var(--color-surface)] shadow-[var(--shadow-1)]"
            data-testid="diagnostics-card"
        >
            <!-- ── Header ────────────────────────────────────────────────── -->
            <header
                class="flex flex-wrap items-start gap-3 border-b border-[var(--color-border)] bg-[var(--color-surface-raised)] p-4 sm:p-5"
            >
                <span
                    class="grid size-10 shrink-0 place-items-center rounded-xl bg-[var(--color-surface-muted)] text-[var(--color-primary)]"
                    aria-hidden="true"
                >
                    <svg viewBox="0 0 20 20" class="size-5" fill="currentColor">
                        <path d="M10 1.6 3 4.4v5c0 4 3 7.5 7 9 4-1.5 7-5 7-9v-5zm-.9 11.6L5.9 10l1.4-1.4 1.8 1.8 4-4L14.5 7.8z" />
                    </svg>
                </span>

                <div class="min-w-0 flex-1">
                    <h2 class="text-card-title text-balance text-[var(--color-text)]">{{ t('ping.title') }}</h2>
                    <p class="mt-0.5 text-table text-[var(--color-text-muted)] text-pretty">{{ t('ping.subtitle') }}</p>
                </div>

                <!-- §9.5: a dot alone is colour; the label is what carries the
                     meaning, and the dot only reinforces it. -->
                <span
                    :class="[
                        'status-badge inline-flex min-h-8 shrink-0 items-center gap-2 rounded-full border px-3 text-table',
                        state === 'ok' ? 'status-badge--ok' : '',
                        state === 'error' ? 'status-badge--error' : '',
                        state === 'loading' ? 'status-badge--pending' : '',
                    ]"
                    role="status"
                    aria-live="polite"
                    data-testid="diagnostics-status"
                >
                    <span class="status-badge__dot size-2 shrink-0 rounded-full" aria-hidden="true" />
                    {{ t(statusKey) }}
                </span>
            </header>

            <!-- ── Body ──────────────────────────────────────────────────── -->
            <LoadingState v-if="state === 'loading'" />

            <ErrorState v-else-if="state === 'error'" @retry="load">
                <!-- Diagnostic, not copy: the raw message is data and sits beside
                     the translated explanation rather than replacing it. -->
                <template #detail>{{ error }}</template>
            </ErrorState>

            <div v-else-if="ping" class="p-4 sm:p-5">
                <dl class="grid gap-3 sm:grid-cols-2">
                    <div class="metric">
                        <dt class="metric__label">{{ t('ping.service') }}</dt>
                        <dd class="metric__value" translate="no">{{ ping.service }}</dd>
                    </div>

                    <div class="metric">
                        <dt class="metric__label">{{ t('ping.latency') }}</dt>
                        <dd class="metric__value" data-testid="diagnostics-latency">{{ roundTrip }}</dd>
                    </div>

                    <div class="metric">
                        <dt class="metric__label">{{ t('ping.time') }}</dt>
                        <dd class="metric__value">{{ serverTime }}</dd>
                    </div>

                    <div class="metric">
                        <dt class="metric__label">{{ t('ping.requestId') }}</dt>
                        <!-- §4.1 puts codes, IDs and audit metadata in the mono
                             face, and nothing else. -->
                        <dd class="metric__value font-mono break-words" translate="no">{{ requestId }}</dd>
                    </div>
                </dl>

                <!-- ── Environment ───────────────────────────────────────── -->
                <h3 class="mt-5 mb-2 text-table text-[var(--color-text-muted)] uppercase environment__heading">
                    {{ t('ping.environment') }}
                </h3>

                <ul class="flex flex-wrap gap-2">
                    <li class="tag">
                        <span class="tag__key">{{ t('ping.endpoint') }}</span>
                        <span class="font-mono" translate="no">{{ ENDPOINT }}</span>
                    </li>
                    <li class="tag">
                        <span class="tag__key">{{ t('ping.locale') }}</span>
                        <span translate="no">{{ locale }}</span>
                    </li>
                    <li class="tag">
                        <span class="tag__key">{{ t('ping.direction') }}</span>
                        <span translate="no">{{ direction }}</span>
                    </li>
                </ul>

                <div class="mt-5 flex justify-end border-t border-[var(--color-border)] pt-4">
                    <button
                        type="button"
                        class="action inline-flex min-h-11 min-w-11 items-center justify-center gap-2 rounded-lg border border-[var(--color-border-strong)] px-4 text-[var(--color-text)] hover:bg-[var(--color-surface-muted)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-focus-ring)]"
                        data-testid="diagnostics-refresh"
                        @click="load"
                    >
                        <svg viewBox="0 0 20 20" class="size-4" aria-hidden="true" fill="currentColor">
                            <path d="M10 3a7 7 0 1 0 6.3 4h-2.2A5 5 0 1 1 10 5v2.5L14 4.2 10 1z" />
                        </svg>
                        {{ t('ping.refresh') }}
                    </button>
                </div>
            </div>
        </article>
    </section>
</template>

<style scoped>
.metric {
    border: 1px solid var(--color-border);
    border-radius: 0.75rem;
    padding: 0.75rem 0.875rem;
    background-color: var(--color-surface-muted);
}

.metric__label {
    font-size: var(--text-table);
    color: var(--color-text-muted);
    text-align: start;
}

.metric__value {
    margin-block-start: 0.125rem;
    color: var(--color-text);
    text-align: start;
    /* Long ids and service names must not push the card wider than the page. */
    overflow-wrap: anywhere;
}

.environment__heading {
    letter-spacing: 0.08em;
}

.tag {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    border: 1px solid var(--color-border);
    border-radius: 9999px;
    padding-block: 0.25rem;
    padding-inline: 0.75rem;
    font-size: var(--text-table);
    color: var(--color-text);
}

.tag__key {
    color: var(--color-text-muted);
}

.status-badge {
    border-color: var(--color-border);
    color: var(--color-text-muted);
}

.status-badge__dot {
    background-color: currentColor;
}

.status-badge--ok {
    border-color: var(--color-success);
    color: var(--color-success);
}

.status-badge--error {
    border-color: var(--color-danger);
    color: var(--color-danger);
}

.status-badge--pending {
    border-color: var(--color-border-strong);
    color: var(--color-status-neutral);
}

.action {
    touch-action: manipulation;
    -webkit-tap-highlight-color: transparent;
    transition-property: background-color, color;
    transition-duration: 160ms;
    transition-timing-function: ease-out;
}

@media (prefers-reduced-motion: reduce) {
    .action {
        transition: none;
    }
}
</style>
