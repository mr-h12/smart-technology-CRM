<script setup lang="ts">
/**
 * Module 13, slice 4 — the stored PDF of one submitted report version (`D-132`, spec §4.4).
 *
 * The screen draws what the server answers (`D-67`, `AP-07`): a read that is refused or
 * fails draws nothing. While the render is queued it asks again every 3 s,
 * the way `QuotationPdfPanel` does (ponytail: that polling block is copied, not shared —
 * a shared helper is outside this slice).
 */
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useAuth } from '@/stores/auth';
import { downloadReportPdf, readReportPdf, retryReportPdf, type ReportPdfState } from '@/services/reports';

const props = defineProps<{ reportId: string; reportCode: string; version: number }>();

const POLL_MS = 3000;

const { t } = useI18n();
// Read once, like `QuotationPdfPanel`'s `canView`: the server's 403 is the real gate (spec §4.3), this only avoids asking.
const canExport = useAuth().hasPermission('report.export');

const pdf = ref<ReportPdfState | null>(null);
const downloading = ref(false);
const retrying = ref(false);

let timer: ReturnType<typeof setTimeout> | null = null;
let unmounted = false;

async function refresh(): Promise<void> {
    try {
        pdf.value = await readReportPdf(props.reportId, props.version);
    } catch {
        pdf.value = null;

        return;
    }

    if (pdf.value.status !== 'queued' || unmounted || timer !== null) {
        return;
    }

    timer = setTimeout(() => {
        timer = null;
        void refresh();
    }, POLL_MS);
}

async function download(): Promise<void> {
    downloading.value = true;

    try {
        await downloadReportPdf(props.reportId, props.version, `${props.reportCode}-v${props.version}.pdf`);
    } catch {
        // OpenAPI §8.3 answers every refusal with 404: nothing finer to say; the button stays for a retry.
    } finally {
        downloading.value = false;
    }
}

/** 7.2: asks the server to queue the render again, then polls as for any queued version; a refusal re-reads the real state. */
async function retry(): Promise<void> {
    retrying.value = true;

    try {
        pdf.value = await retryReportPdf(props.reportId, props.version);
        timer = setTimeout(() => {
            timer = null;
            void refresh();
        }, POLL_MS);
    } catch {
        await refresh();
    } finally {
        retrying.value = false;
    }
}

onMounted(() => {
    if (canExport) {
        void refresh();
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
    <div v-if="pdf?.status === 'failed'" class="flex flex-wrap items-center gap-3 rounded-lg border border-[var(--color-border-strong)] bg-[var(--color-surface-muted)] p-3">
        <p role="alert" data-testid="report-pdf-error">{{ t('reports.pdf.failed') }}</p>
        <button
            type="button"
            class="create-action inline-flex min-h-11 items-center justify-center rounded-lg px-4 disabled:cursor-not-allowed disabled:opacity-60"
            :disabled="retrying"
            data-testid="report-pdf-retry"
            @click="retry"
        >
            {{ t('reports.pdf.retry') }}
        </button>
    </div>
    <button
        v-else-if="pdf !== null"
        type="button"
        class="create-action inline-flex min-h-11 items-center justify-center rounded-lg px-4 disabled:cursor-not-allowed disabled:opacity-60"
        :disabled="pdf.status === 'queued' || downloading"
        :aria-busy="pdf.status === 'queued'"
        data-testid="report-pdf-button"
        @click="pdf.file_id !== null && download()"
    >
        {{ t(pdf.status === 'queued' ? 'reports.pdf.preparing' : 'reports.pdf.download', { version }) }}
    </button>
</template>
