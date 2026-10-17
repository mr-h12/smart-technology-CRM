<script setup lang="ts">
/**
 * Module 13, slice 2 — "Received reports": the reports sent to the caller
 * (§3.10's *view received*; the owner, 2026-10-16), newest day first.
 *
 * The server decides what was sent to whom; this screen lists what it returns.
 */
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';
import { ApiError, type Pagination } from '@/api';
import EmptyState from '@/components/states/EmptyState.vue';
import ErrorState from '@/components/states/ErrorState.vue';
import LoadingState from '@/components/states/LoadingState.vue';
import PermissionDeniedState from '@/components/states/PermissionDeniedState.vue';
import { displayDateRange } from '@/domain/displayDate';
import ReportStatusChip from '@/pages/reports/ReportStatusChip.vue';
import { listReceivedReports, type ReceivedReportSummary } from '@/services/reports';

const { t, locale } = useI18n();

const reports = ref<ReceivedReportSummary[]>([]);
const pagination = ref<Pagination | null>(null);
const loading = ref(true);
const failed = ref(false);
const denied = ref(false);

async function load(page = 1): Promise<void> {
    loading.value = true;
    failed.value = false;
    denied.value = false;

    try {
        const result = await listReceivedReports(page);
        reports.value = result.items;
        pagination.value = result.pagination;
    } catch (error) {
        if (error instanceof ApiError && error.status === 403) {
            denied.value = true;
        } else {
            failed.value = true;
        }
    } finally {
        loading.value = false;
    }
}

onMounted(() => load());
</script>

<template>
    <section class="flex flex-col gap-4">
        <header class="flex flex-col gap-1">
            <h1 class="text-page-title">{{ t('reports.received_title') }}</h1>
            <p class="text-[var(--color-text-muted)] text-pretty">{{ t('reports.received_subtitle') }}</p>
        </header>

        <LoadingState v-if="loading && pagination === null" label-key="reports.loading" />
        <PermissionDeniedState v-else-if="denied" />
        <ErrorState v-else-if="failed" @retry="load()" />
        <EmptyState v-else-if="reports.length === 0" title-key="reports.received_empty.title" message-key="reports.received_empty.message" />

        <div v-else class="flex flex-col gap-3">
            <ul class="flex flex-col gap-2" :aria-busy="loading">
                <li
                    v-for="report in reports"
                    :key="report.id"
                    class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-3"
                    data-testid="received-report-row"
                >
                    <RouterLink :to="{ name: 'received-report-detail', params: { id: report.id } }" class="row-link tabular-nums">
                        <bdi dir="ltr">{{ report.code }}</bdi>
                    </RouterLink>
                    <span>{{ report.author_name }}</span>
                    <span>{{ t(`reports.type.${report.type}`) }}</span>
                    <span class="tabular-nums text-[var(--color-text-muted)]" data-testid="report-period">{{ displayDateRange(report.period_start, report.period_end, locale) }}</span>
                    <ReportStatusChip :status="report.status" />
                    <span v-if="report.submitted_late" class="rounded-full bg-[var(--color-surface-muted)] px-2.5 py-0.5 text-table text-[var(--color-warning)]" data-testid="report-late">{{ t('reports.late') }}</span>
                    <span v-if="report.not_reviewed" class="rounded-full bg-[var(--color-surface-muted)] px-2.5 py-0.5 text-[var(--color-warning)] text-table" data-testid="report-not-reviewed">{{ t('reports.not_reviewed') }}</span>
                </li>
            </ul>

            <nav
                v-if="pagination !== null && pagination.total_pages > 1"
                class="flex items-center justify-between gap-3"
                :aria-label="t('reports.pagination.label')"
            >
                <button
                    type="button"
                    class="row-action min-h-11 rounded-lg px-3 disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="!pagination.has_previous_page"
                    @click="load(pagination.page - 1)"
                >
                    {{ t('reports.pagination.previous') }}
                </button>
                <p class="tabular-nums text-[var(--color-text-muted)]">
                    {{ t('reports.pagination.position', { page: pagination.page, pages: pagination.total_pages }) }}
                </p>
                <button
                    type="button"
                    class="row-action min-h-11 rounded-lg px-3 disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="!pagination.has_next_page"
                    @click="load(pagination.page + 1)"
                >
                    {{ t('reports.pagination.next') }}
                </button>
            </nav>
        </div>
    </section>
</template>
