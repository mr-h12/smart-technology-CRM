<script setup lang="ts">
/**
 * F-47 (D-116): an attached PDF or image opens here instead of downloading.
 *
 * The bytes come through the same permission-checked route as a download
 * (D-38, §17); the credential is a header (D-74), so the file is fetched as a
 * blob and shown through an object URL — no file becomes URL-addressable.
 * Which types open here is `VIEWABLE_TYPES`; the caller decides from `mime_type`.
 */
import { ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import ErrorState from '@/components/states/ErrorState.vue';
import LoadingState from '@/components/states/LoadingState.vue';
import { useModalDialog } from '@/components/useModalDialog';
import { downloadFile, fetchFileUrl } from '@/services/files';

const props = defineProps<{
    /** null keeps the viewer closed. */
    file: { id: string; name: string; mimeType: string } | null;
}>();

const emit = defineEmits<{ close: [] }>();

const { t } = useI18n();
const dialog = ref<HTMLDialogElement | null>(null);
const titleId = useId();
const url = ref<string | null>(null);
const failed = ref(false);

useModalDialog(dialog);

watch(() => props.file, async (file) => {
    if (url.value !== null) {
        URL.revokeObjectURL(url.value);
        url.value = null;
    }
    failed.value = false;

    if (file === null) {
        return;
    }

    try {
        const fetched = await fetchFileUrl(file.id);

        // Closed or switched while the bytes were on the way: this URL has no viewer.
        if (props.file !== file) {
            URL.revokeObjectURL(fetched);

            return;
        }
        url.value = fetched;
    } catch {
        if (props.file === file) {
            failed.value = true;
        }
    }
}, { immediate: true });

function download(): void {
    if (props.file !== null) {
        downloadFile(props.file.id, props.file.name).catch(() => {
            failed.value = true;
        });
    }
}
</script>

<template>
    <!-- A-04: Escape arrives as the dialog's own `cancel`; prevented, so `file` stays the parent's to clear. -->
    <dialog
        v-if="file !== null"
        ref="dialog"
        class="fixed inset-0 z-50 flex items-center justify-center sm:p-4"
        :aria-labelledby="titleId"
        data-testid="file-viewer"
        @cancel.prevent="emit('close')"
    >
        <div class="dialog-scrim absolute inset-0" @click="emit('close')" />

        <!-- ponytail: iOS Safari may draw only a PDF's first page in an iframe; «تنزيل» is the way out. -->
        <div class="dialog-panel relative flex h-dvh w-full flex-col sm:h-[90dvh] sm:max-w-5xl sm:rounded-2xl">
            <div class="flex items-center gap-2 border-b border-[var(--color-border)] p-3">
                <h2 :id="titleId" class="text-card-title min-w-0 flex-1 truncate" dir="auto" :title="file.name">{{ file.name }}</h2>
                <button
                    type="button"
                    class="row-action min-h-11 rounded-lg px-4 py-2"
                    data-testid="file-viewer-download"
                    @click="download"
                >
                    {{ t('files.download') }}
                </button>
                <button
                    type="button"
                    class="row-action min-h-11 rounded-lg px-4 py-2"
                    data-testid="file-viewer-close"
                    @click="emit('close')"
                >
                    {{ t('action.close') }}
                </button>
            </div>

            <div class="flex min-h-0 flex-1 items-center justify-center overflow-auto">
                <ErrorState v-if="failed" message-key="files.viewFailed" :retryable="false" />
                <LoadingState v-else-if="url === null" />
                <img
                    v-else-if="file.mimeType.startsWith('image/')"
                    :src="url"
                    :alt="file.name"
                    class="max-h-full max-w-full object-contain"
                />
                <iframe v-else :src="url" :title="file.name" class="h-full w-full border-0" />
            </div>
        </div>
    </dialog>
</template>
