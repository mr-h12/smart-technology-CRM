<script setup lang="ts">
/**
 * §17's upload and §3.4's assign, on the deal's own page (Module 5, Point 6.7).
 *
 * ── ⚠️ This panel can only show what this session uploaded ─────────────────
 *
 * There is **no `GET /deals/{id}/documents`**. Point 4.1 built the upload,
 * Module 0 built a generic `GET /files/{id}/download`, and no per-parent list
 * endpoint exists anywhere — `DealPayload` carries no documents array either.
 * So a reload empties this list while the files remain perfectly safe on the
 * server. Module 6 Point 6.5 hit the identical wall and said so on screen
 * rather than pretending; this does the same, and the endpoint is on the debt
 * register.
 *
 * ── The form field is `document`, and must stay that name ──────────────────
 *
 * `ApiExceptionRenderer` hard-codes `'field' => 'document'` when mapping the
 * server's refusal back onto a control. A rename 422s every upload **and**
 * points the error at a field that does not exist — Module 6 found that by
 * probe with no test asserting the key, so `deals.spec.ts` asserts it.
 *
 * ── `scan_status` is shown as itself ───────────────────────────────────────
 *
 * `SEC-15` gates the download on the scan, not on ownership, and a scan can be
 * `pending` when the scanner was unavailable. A file that is not yet clean is
 * drawn as what it is rather than as a broken download.
 *
 * ── Assign is its own permission, and its Team half is unreachable ─────────
 *
 * §3.4 gives `assign_owner` its own row — Manager (`All`) and Team Leader
 * (`Team`) — where `edit` reaches five roles. ⚠️ `Team` resolves to no rows
 * (Point 2.1), so half of this control's permission row is unreachable today,
 * exactly as `routes/api.php` already records against the route.
 */
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ApiError } from '@/api';
import { assignDeal, attachDealDocument, type Deal, type DealDocument } from '@/services/deals';
import { downloadFile } from '@/services/files';
import { useAuth } from '@/stores/auth';

const props = defineProps<{ deal: Deal }>();
const emit = defineEmits<{ assigned: [Deal] }>();

const { t } = useI18n();
const auth = useAuth();

/** §3.4 seeds no "attach document" row, so the route carries `deal.edit`. */
const canUpload = computed(() => auth.hasPermission('deal.edit'));
const canAssign = computed(() => auth.hasPermission('deal.assign_owner'));

const uploaded = ref<DealDocument[]>([]);
const uploading = ref(false);
const uploadErrorKey = ref<string | null>(null);
const downloadingId = ref<string | null>(null);

const assigning = ref(false);
const ownerId = ref('');
const assignErrorKey = ref<string | null>(null);
const assignFieldError = ref<string | null>(null);

async function onFileChosen(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (file === undefined) {
        return;
    }

    uploadErrorKey.value = null;
    uploading.value = true;

    try {
        uploaded.value = [await attachDealDocument(props.deal.id, file), ...uploaded.value];
    } catch (error) {
        uploadErrorKey.value = error instanceof ApiError && error.status === 403
            ? 'deals.documents.forbidden'
            : 'deals.documents.rejected';
    } finally {
        uploading.value = false;
        // So the same file can be chosen again after a failure.
        input.value = '';
    }
}

async function download(document: DealDocument): Promise<void> {
    uploadErrorKey.value = null;
    downloadingId.value = document.id;

    try {
        await downloadFile(document.id, document.original_name);
    } catch (error) {
        uploadErrorKey.value = error instanceof ApiError && error.status === 403
            ? 'deals.documents.downloadForbidden'
            : 'deals.documents.downloadRejected';
    } finally {
        downloadingId.value = null;
    }
}

async function submitAssign(): Promise<void> {
    assignErrorKey.value = null;
    assignFieldError.value = null;

    if (ownerId.value.trim() === '') {
        assignFieldError.value = t('deals.assign.ownerRequired');

        return;
    }

    assigning.value = true;

    try {
        const assigned = await assignDeal(props.deal.id, ownerId.value.trim());

        ownerId.value = '';
        emit('assigned', assigned);
    } catch (error) {
        const sentence = error instanceof ApiError ? error.messageFor('owner_id') : null;

        if (sentence !== null) {
            assignFieldError.value = sentence;
        } else {
            assignErrorKey.value = error instanceof ApiError && error.status === 403
                ? 'deals.assign.forbidden'
                : 'deals.assign.rejected';
        }
    } finally {
        assigning.value = false;
    }
}
</script>

<template>
    <div class="flex flex-col gap-4" data-testid="deal-documents">
        <section class="flex flex-col gap-2">
            <h2 class="text-card-title">{{ t('deals.documents.title') }}</h2>

            <!-- ⚠️ Said on the screen, not only in a docblock: the files are
                 safe, this list is not a record of them. -->
            <p class="text-[var(--color-text-muted)]" data-testid="deal-documents-ceiling">
                {{ t('deals.documents.ceiling') }}
            </p>

            <p v-if="uploadErrorKey !== null" class="form-alert rounded-lg p-3" role="alert" data-testid="deal-documents-error">
                {{ t(uploadErrorKey) }}
            </p>

            <label v-if="canUpload" class="flex flex-col gap-1.5" for="deal-document-file">
                <span>{{ t('deals.documents.choose') }}</span>
                <input
                    id="deal-document-file"
                    type="file"
                    :disabled="uploading"
                    class="form-field rounded-lg px-3 py-2"
                    data-testid="deal-documents-file"
                    @change="onFileChosen"
                />
            </label>

            <p v-if="uploading" data-testid="deal-documents-uploading">{{ t('deals.documents.uploading') }}</p>

            <ul v-if="uploaded.length > 0" class="flex flex-col gap-2">
                <li
                    v-for="document in uploaded"
                    :key="document.id"
                    class="document-row flex flex-wrap items-center justify-between gap-2 rounded-lg p-3"
                    data-testid="deal-documents-row"
                >
                    <span>{{ document.original_name }}</span>

                    <!-- SEC-15 gates on the scan, so its state is a fact worth
                         showing rather than a silently broken button. -->
                    <span v-if="document.scan_status === 'pending'" data-testid="deal-documents-pending">
                        {{ t('deals.documents.scanPending') }}
                    </span>
                    <span
                        v-else-if="document.scan_status === 'infected'"
                        class="text-[var(--color-danger)]"
                        data-testid="deal-documents-infected"
                    >
                        {{ t('deals.documents.scanInfected') }}
                    </span>
                    <button
                        v-else
                        type="button"
                        class="row-action min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                        :disabled="downloadingId === document.id"
                        data-testid="deal-documents-download"
                        @click="download(document)"
                    >
                        {{ t('deals.documents.download') }}
                    </button>
                </li>
            </ul>
        </section>

        <section v-if="canAssign" class="flex flex-col gap-2" data-testid="deal-assign">
            <h2 class="text-card-title">{{ t('deals.assign.title') }}</h2>

            <p v-if="assignErrorKey !== null" class="form-alert rounded-lg p-3" role="alert" data-testid="deal-assign-error">
                {{ t(assignErrorKey) }}
            </p>

            <form class="flex flex-wrap items-end gap-2" data-testid="deal-assign-form" @submit.prevent="submitAssign()">
                <label class="flex flex-col gap-1.5" for="deal-assign-owner">
                    <span>
                        {{ t('deals.column.owner') }}
                        <span class="text-[var(--color-danger)]">{{ t('deals.form.required') }}</span>
                    </span>
                    <input
                        id="deal-assign-owner"
                        v-model="ownerId"
                        type="text"
                        :disabled="assigning"
                        :placeholder="t('deals.form.ownerPlaceholder')"
                        :aria-invalid="assignFieldError !== null"
                        class="form-field min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                        data-testid="deal-assign-owner"
                    />
                    <span v-if="assignFieldError !== null" class="text-[var(--color-danger)]" data-testid="deal-assign-owner-error">
                        {{ assignFieldError }}
                    </span>
                </label>

                <button
                    type="submit"
                    class="create-action min-h-11 rounded-lg px-4 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="assigning"
                    data-testid="deal-assign-submit"
                >
                    {{ t('deals.assign.action') }}
                </button>
            </form>
        </section>
    </div>
</template>

<style scoped>
.document-row {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border);
}

.form-field {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border-strong);
    color: var(--color-text);
}

.form-alert {
    background-color: var(--color-surface-muted);
    border: 1px solid var(--color-border-strong);
}

.row-action {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border-strong);
    color: var(--color-text);
}

.create-action {
    background-color: var(--color-primary);
    color: var(--color-primary-text);
}
</style>
