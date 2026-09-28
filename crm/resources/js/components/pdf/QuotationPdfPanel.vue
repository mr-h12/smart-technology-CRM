<script setup lang="ts">
/**
 * Module 9 · 5.1 — a quotation's customer PDF: *Generate* under
 * `quotation.generate_pdf` (3.5), *Download* under `quotation.export_pdf`, and
 * the state between them, read from 4.2.
 *
 * Every refusal is the API's (§3.5, `SEC-09`); the permission checks here only
 * decide what to draw (Design System §9 rule 3). Each holder of `generate_pdf`
 * also holds `export_pdf`, so a caller without the second has no panel at all.
 * A scope the server cannot back — Procurement's `Asgn` (Q2), the Team
 * Leader's `Team` on *generate* (`D-a`) — is still a grant here, and its 403
 * is drawn as the refusal it is.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ApiError } from '@/api';
import ErrorState from '@/components/states/ErrorState.vue';
import LoadingState from '@/components/states/LoadingState.vue';
import PermissionDeniedState from '@/components/states/PermissionDeniedState.vue';
import { downloadFile } from '@/services/files';
import { readQuotationPdf, requestQuotationPdf, type PdfLocale, type QuotationPdfState } from '@/services/pdf';
import { useAuth } from '@/stores/auth';

const props = defineProps<{ quotationId: string; quotationCode: string }>();

/**
 * How often a queued render is asked about. ponytail: a worker that never
 * runs keeps an open panel asking; the `pdf_generations` row is where a stuck
 * job shows (§15.1's Queue Monitor is not installed).
 */
const POLL_MS = 3000;

const LANGUAGES: readonly { value: PdfLocale; labelKey: string }[] = [
    { value: 'ar', labelKey: 'language.arabic' },
    { value: 'en', labelKey: 'language.english' },
];

const { t, locale } = useI18n();
const { hasPermission } = useAuth();

const canView = hasPermission('quotation.export_pdf');
const canGenerate = hasPermission('quotation.generate_pdf');

const pdf = ref<QuotationPdfState | null>(null);
const loading = ref(true);
const denied = ref(false);
const failed = ref(false);

const requesting = ref(false);
const downloading = ref(false);
const errorKey = ref<string | null>(null);

// Q15: the screen's language, unless the person picks the other one.
const documentLocale = ref<PdfLocale>(locale.value === 'ar' ? 'ar' : 'en');

const status = computed(() => pdf.value?.generation?.status ?? null);
const statusId = `pdf-status-${props.quotationId}`;

const statusKey = computed(() => {
    switch (status.value) {
        case 'queued':
            return 'pdf.panel.queued';
        case 'completed':
            return 'pdf.panel.ready';
        case 'failed':
            return 'pdf.panel.failed';
        default:
            return 'pdf.panel.none';
    }
});

let timer: ReturnType<typeof setTimeout> | null = null;
let unmounted = false;

function refused(error: unknown): void {
    denied.value = error instanceof ApiError && error.status === 403;
    failed.value = !denied.value;
}

/** A read that keeps the panel on screen: the read-back after a 202, and each poll. */
async function refresh(): Promise<void> {
    try {
        pdf.value = await readQuotationPdf(props.quotationId);
    } catch (error) {
        refused(error);

        return;
    }

    pollWhileQueued();
}

/** Q12: ask again while the render is queued — until it is final, a read fails, or the panel goes. */
function pollWhileQueued(): void {
    if (status.value !== 'queued' || unmounted || timer !== null) {
        return;
    }

    timer = setTimeout(() => {
        timer = null;
        void refresh();
    }, POLL_MS);
}

async function load(): Promise<void> {
    loading.value = true;
    denied.value = false;
    failed.value = false;

    await refresh();

    loading.value = false;
}

async function generate(): Promise<void> {
    errorKey.value = null;
    requesting.value = true;

    try {
        await requestQuotationPdf(props.quotationId, documentLocale.value);
    } catch (error) {
        errorKey.value = error instanceof ApiError && error.status === 403 ? 'pdf.panel.generateForbidden' : 'pdf.panel.generateFailed';
        requesting.value = false;

        return;
    }

    // `OpenAPI §4.3`: the 202 is not the file, so the state is read back rather
    // than invented — with the button still held, so it cannot be pressed twice
    // before the queued state is on screen.
    await refresh();
    requesting.value = false;
}

async function download(fileId: string): Promise<void> {
    errorKey.value = null;
    downloading.value = true;

    try {
        await downloadFile(fileId, `${props.quotationCode}.pdf`);
    } catch {
        // `OpenAPI §8.3` answers every refusal with 404, so there is nothing finer to say.
        errorKey.value = 'pdf.panel.downloadFailed';
    } finally {
        downloading.value = false;
    }
}

onMounted(() => {
    if (canView) {
        void load();
    }
});

onBeforeUnmount(() => {
    unmounted = true;

    if (timer !== null) {
        clearTimeout(timer);
    }
});
</script>

<template>
    <section v-if="canView" class="pdf-block flex flex-col gap-3 rounded-xl p-4" data-testid="quotation-pdf-panel">
        <h2 class="text-card-title">{{ t('pdf.panel.title') }}</h2>

        <LoadingState v-if="loading" label-key="pdf.panel.loading" />
        <PermissionDeniedState v-else-if="denied" />
        <ErrorState v-else-if="failed" @retry="load" />

        <template v-else>
            <!-- Polite, so each answer the polling brings is announced (Design System §8);
                 an icon and the words together, never the colour alone (§6.4). -->
            <p :id="statusId" class="flex items-center gap-2" role="status" aria-live="polite" data-testid="pdf-status">
                <svg
                    v-if="status === 'queued'"
                    class="size-5 shrink-0 animate-spin text-[var(--color-warning)] motion-reduce:animate-none"
                    viewBox="0 0 20 20"
                    fill="none"
                    aria-hidden="true"
                >
                    <circle cx="10" cy="10" r="8" stroke="currentColor" stroke-opacity="0.25" stroke-width="2.5" />
                    <path d="M18 10a8 8 0 0 0-8-8" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
                </svg>
                <svg v-else-if="status === 'completed'" class="size-5 shrink-0 text-[var(--color-success)]" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path d="M10 2a8 8 0 1 0 0 16 8 8 0 0 0 0-16zm-1.2 11.4L5.3 9.9l1.4-1.4 2.1 2.1 4.6-4.6 1.4 1.4z" />
                </svg>
                <svg v-else-if="status === 'failed'" class="size-5 shrink-0 text-[var(--color-danger)]" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path d="M10 2a8 8 0 1 0 0 16 8 8 0 0 0 0-16zm-1 4h2v6H9zm0 8h2v2H9z" />
                </svg>
                <svg v-else class="size-5 shrink-0 text-[var(--color-status-neutral)]" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path d="M5 2a1 1 0 0 0-1 1v14a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V7l-5-5zm6 1.5V7h3.5z" />
                </svg>
                <span>{{ t(statusKey) }}</span>
            </p>

            <!-- The job's own words (Q3's failure record): data, not copy, in whatever
                 language it was written — `dir="auto"` takes its direction from the text,
                 or an English reason on an Arabic screen puts its full stop first. -->
            <p
                v-if="status === 'failed' && pdf?.generation?.failure_reason"
                class="form-alert rounded-lg p-3"
                dir="auto"
                data-testid="pdf-failure-reason"
            >{{ pdf.generation.failure_reason }}</p>

            <p v-if="errorKey !== null" class="form-alert rounded-lg p-3" role="alert" data-testid="pdf-error">{{ t(errorKey) }}</p>

            <div class="flex flex-wrap items-end gap-3">
                <fieldset v-if="canGenerate" class="flex flex-col gap-1.5" data-testid="pdf-language">
                    <legend class="mb-1.5">{{ t('pdf.panel.language') }}</legend>
                    <div class="flex flex-wrap gap-2">
                        <label
                            v-for="option in LANGUAGES"
                            :key="option.value"
                            class="choice inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-lg px-3"
                        >
                            <input
                                v-model="documentLocale"
                                type="radio"
                                class="size-4 accent-[var(--color-primary)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-focus-ring)]"
                                :name="`pdf-language-${quotationId}`"
                                :value="option.value"
                                :data-testid="`pdf-language-${option.value}`"
                            />
                            <span :lang="option.value">{{ t(option.labelKey) }}</span>
                        </label>
                    </div>
                </fieldset>

                <!-- Q12: one render at a time — the status line above says why the button waits. -->
                <button
                    v-if="canGenerate"
                    type="button"
                    class="primary-action inline-flex min-h-11 items-center justify-center rounded-lg px-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="requesting || status === 'queued'"
                    :aria-describedby="status === 'queued' ? statusId : undefined"
                    data-testid="pdf-generate"
                    @click="generate"
                >
                    {{ requesting ? t('pdf.panel.requesting') : t('pdf.panel.generate') }}
                </button>

                <button
                    v-if="pdf?.latest_file_id"
                    type="button"
                    class="row-action inline-flex min-h-11 items-center justify-center rounded-lg px-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="downloading"
                    data-testid="pdf-download"
                    @click="download(pdf.latest_file_id)"
                >
                    {{ downloading ? t('pdf.panel.downloading') : t('pdf.panel.download') }}
                </button>
            </div>
        </template>
    </section>
</template>

<style scoped>
.pdf-block {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border);
}

.form-alert {
    background-color: var(--color-surface-muted);
    border: 1px solid var(--color-border-strong);
}

.choice {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border-strong);
    color: var(--color-text);
}

.primary-action {
    background-color: var(--color-primary);
    color: var(--color-primary-text);
}

.primary-action:hover:not(:disabled) {
    background-color: var(--color-primary-hover);
}

.row-action {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border-strong);
    color: var(--color-text);
}

.row-action:hover:not(:disabled) {
    background-color: var(--color-surface-muted);
}
</style>
