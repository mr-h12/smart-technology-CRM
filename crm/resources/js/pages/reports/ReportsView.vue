<script setup lang="ts">
/**
 * Module 13, first slice — "My reports": the author's own reports, newest day
 * first, and the button that starts today's daily report (RPT-DY).
 *
 * The server decides the day, the number and who may write one (`report.create`,
 * §3.10); this screen lists what it returns and opens what it creates.
 */
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRouter } from 'vue-router';
import { ApiError, type Pagination } from '@/api';
import EmptyState from '@/components/states/EmptyState.vue';
import ErrorState from '@/components/states/ErrorState.vue';
import LoadingState from '@/components/states/LoadingState.vue';
import PermissionDeniedState from '@/components/states/PermissionDeniedState.vue';
import { displayDate } from '@/domain/displayDate';
import { createDailyReport, listMyReports, type ReportSummary } from '@/services/reports';

const { t, locale } = useI18n();
const router = useRouter();

const reports = ref<ReportSummary[]>([]);
const pagination = ref<Pagination | null>(null);
const loading = ref(true);
const failed = ref(false);
const denied = ref(false);
const creating = ref(false);
const createFailed = ref(false);

/** `OpenAPI §9.1`: one key per press, so a retried request is still one report. */
let idempotencyKey = crypto.randomUUID();

async function load(page = 1): Promise<void> {
    loading.value = true;
    failed.value = false;
    denied.value = false;

    try {
        const result = await listMyReports(page);
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

async function startDaily(): Promise<void> {
    creating.value = true;
    createFailed.value = false;

    try {
        const created = await createDailyReport(idempotencyKey);
        idempotencyKey = crypto.randomUUID();
        await router.push({ name: 'report-detail', params: { id: created.id } });
    } catch {
        createFailed.value = true;
    } finally {
        creating.value = false;
    }
}

onMounted(() => load());
</script>

<template>
    <section class="flex flex-col gap-4">
        <header class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-col gap-1">
                <h1 class="text-page-title">{{ t('reports.title') }}</h1>
                <p class="text-[var(--color-text-muted)] text-pretty">{{ t('reports.subtitle') }}</p>
            </div>

            <button
                type="button"
                class="create-action min-h-11 rounded-lg px-4 disabled:cursor-not-allowed disabled:opacity-60"
                :disabled="creating"
                data-testid="reports-new"
                @click="startDaily"
            >
                {{ creating ? t('reports.creating') : t('reports.new_daily') }}
            </button>
        </header>

        <p v-if="createFailed" class="text-[var(--color-danger)]" role="alert">{{ t('reports.error.create') }}</p>

        <LoadingState v-if="loading && pagination === null" label-key="reports.loading" />
        <PermissionDeniedState v-else-if="denied" />
        <ErrorState v-else-if="failed" @retry="load()" />
        <EmptyState v-else-if="reports.length === 0" title-key="reports.empty.title" message-key="reports.empty.message" />

        <div v-else class="flex flex-col gap-3">
            <ul class="flex flex-col gap-2" :aria-busy="loading">
                <li
                    v-for="report in reports"
                    :key="report.id"
                    class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-3"
                    data-testid="report-row"
                >
                    <RouterLink :to="{ name: 'report-detail', params: { id: report.id } }" class="row-link tabular-nums">
                        <bdi dir="ltr">{{ report.code }}</bdi>
                    </RouterLink>
                    <span>{{ t(`reports.type.${report.type}`) }}</span>
                    <span class="tabular-nums text-[var(--color-text-muted)]">{{ displayDate(report.period_start, locale) }}</span>
                    <span class="rounded-full bg-[var(--color-surface-muted)] px-2.5 py-0.5 text-table" :class="{ 'text-[var(--color-warning)]': report.status === 'missed' }">{{ t(`reports.status.${report.status}`) }}</span>
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
