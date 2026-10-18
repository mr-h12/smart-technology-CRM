<script setup lang="ts">
/**
 * Module 13, slice 7 · 7.3 — a report's comments (§3.10 *comment*, `D-127`): one flat list, oldest
 * first as the server gives it, in the page's side column. Only a recipient who holds `report.comment`
 * is offered the box (`canComment`); the server is the real gate (`D-67`, `AP-07`). A comment is
 * never edited or removed, so a row has no controls.
 */
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { displayTimestamp } from '@/domain/displayDate';
import { addReportComment, type ReportComment } from '@/services/reports';

const props = defineProps<{ reportId: string; comments: ReportComment[]; canComment: boolean }>();
const emit = defineEmits<{ added: [comment: ReportComment] }>();

const { t, locale } = useI18n();

const body = ref('');
const busy = ref(false);
const failed = ref(false);

async function send(): Promise<void> {
    const text = body.value.trim();

    if (text === '' || busy.value) {
        return;
    }

    busy.value = true;
    failed.value = false;

    try {
        emit('added', await addReportComment(props.reportId, text));
        body.value = '';
    } catch {
        failed.value = true;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <section class="flex flex-col gap-2" aria-labelledby="report-comments-heading" data-testid="report-comments">
        <h2 id="report-comments-heading" class="text-card-title">{{ t('reports.comments_title') }}</h2>

        <p v-if="comments.length === 0" class="text-[var(--color-text-muted)]" data-testid="report-comments-empty">{{ t('reports.comments_empty') }}</p>
        <ul v-else class="flex flex-col divide-y divide-[var(--color-border)]">
            <li v-for="comment in comments" :key="comment.id" class="flex flex-col gap-1 py-3" data-testid="report-comment">
                <p class="flex flex-wrap items-baseline justify-between gap-x-3 text-table text-[var(--color-text-muted)]">
                    <span>{{ comment.author.name }}</span>
                    <time :datetime="comment.created_at" class="tabular-nums">{{ displayTimestamp(comment.created_at, locale) }}</time>
                </p>
                <p dir="auto" class="whitespace-pre-line text-pretty [overflow-wrap:anywhere]">{{ comment.body }}</p>
            </li>
        </ul>

        <div v-if="canComment" class="flex flex-col gap-2">
            <label class="flex flex-col gap-1">
                <span>{{ t('reports.comment_label') }}</span>
                <textarea
                    v-model="body"
                    rows="3"
                    maxlength="5000"
                    class="form-field rounded-lg p-3"
                    data-testid="report-comment-body"
                ></textarea>
            </label>
            <p v-if="failed" class="text-[var(--color-danger)]" role="alert" data-testid="report-comment-error">{{ t('reports.comments_failed') }}</p>
            <button
                type="button"
                class="create-action min-h-11 w-fit rounded-lg px-4 disabled:cursor-not-allowed disabled:opacity-60"
                :disabled="busy"
                data-testid="report-comment-add"
                @click="send"
            >
                {{ busy ? t('action.saving') : t('reports.comment_add') }}
            </button>
        </div>
    </section>
</template>
