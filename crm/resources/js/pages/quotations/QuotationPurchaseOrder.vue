<script setup lang="ts">
/**
 * The purchase order on its quotation — Module 10 · 3.3 (§4.6, `D-53`).
 *
 * There is no PO page (owner, Q-B): the quotation's own page already shows
 * the deal and the totals the PO detail repeats, so the order is drawn here —
 * its number, the customer's reference and the date from `purchase_order`, and
 * its files from `GET /purchase-orders/{id}`'s `documents`, oldest first (2.3,
 * A1). That read is contained: a refusal or a fault stays in this block.
 *
 * The upload is `POST …/documents` under `quotation.record_customer_response`
 * (Q10, `D-38`), one file per call; the stored file is the answer and joins the
 * list last, which is where the server's order puts it. Its scan is shown as
 * itself (`SEC-15`), exactly as `DealDocumentsPanel` does, and so are its words.
 */
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ApiError } from '@/api';
import ErrorState from '@/components/states/ErrorState.vue';
import LoadingState from '@/components/states/LoadingState.vue';
import PermissionDeniedState from '@/components/states/PermissionDeniedState.vue';
import { displayDate } from '@/domain/displayDate';
import type { DealDocument } from '@/services/deals';
import { downloadFile, FILE_ACCEPT } from '@/services/files';
import { attachPurchaseOrderDocument, readPurchaseOrder, type PurchaseOrderReference } from '@/services/quotations';
import { useAuth } from '@/stores/auth';

const props = defineProps<{ order: PurchaseOrderReference }>();

const { t, locale } = useI18n();
const { hasPermission } = useAuth();

const canUpload = hasPermission('quotation.record_customer_response');

const documents = ref<DealDocument[]>([]);
const loading = ref(true);
const denied = ref(false);
const failed = ref(false);

const uploading = ref(false);
const errorKey = ref<string | null>(null);
const downloadingId = ref<string | null>(null);

async function load(): Promise<void> {
    loading.value = true;
    denied.value = false;
    failed.value = false;

    try {
        documents.value = (await readPurchaseOrder(props.order.id)).documents;
    } catch (error) {
        denied.value = error instanceof ApiError && error.status === 403;
        failed.value = !denied.value;
    } finally {
        loading.value = false;
    }
}

async function onFileChosen(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (file === undefined) {
        return;
    }

    errorKey.value = null;
    uploading.value = true;

    try {
        documents.value = [...documents.value, await attachPurchaseOrderDocument(props.order.id, file)];
    } catch (error) {
        errorKey.value = error instanceof ApiError && error.status === 403 ? 'purchaseOrders.block.forbidden' : 'deals.documents.rejected';
    } finally {
        uploading.value = false;
        // So the same file can be chosen again after a failure.
        input.value = '';
    }
}

async function download(document: DealDocument): Promise<void> {
    errorKey.value = null;
    downloadingId.value = document.id;

    try {
        await downloadFile(document.id, document.original_name);
    } catch (error) {
        errorKey.value = error instanceof ApiError && error.status === 403 ? 'deals.documents.downloadForbidden' : 'deals.documents.downloadRejected';
    } finally {
        downloadingId.value = null;
    }
}

onMounted(load);
</script>

<template>
    <section class="po-block flex flex-col gap-3 rounded-xl p-4" data-testid="quotation-purchase-order">
        <h2 class="text-card-title">{{ t('purchaseOrders.block.title') }}</h2>

        <dl class="flex flex-wrap gap-x-8 gap-y-2">
            <div class="flex flex-col gap-1">
                <dt class="text-[var(--color-text-muted)]">{{ t('purchaseOrders.column.poNumber') }}</dt>
                <dd class="tabular-nums" data-testid="purchase-order-number">{{ order.po_number }}</dd>
            </div>
            <div class="flex flex-col gap-1">
                <dt class="text-[var(--color-text-muted)]">{{ t('quotations.detail.poReference') }}</dt>
                <dd data-testid="purchase-order-reference">{{ order.customer_po_reference }}</dd>
            </div>
            <div class="flex flex-col gap-1">
                <dt class="text-[var(--color-text-muted)]">{{ t('quotations.detail.poDate') }}</dt>
                <dd class="tabular-nums" data-testid="purchase-order-date">{{ displayDate(order.po_date, locale) }}</dd>
            </div>
        </dl>

        <p v-if="errorKey !== null" class="form-alert rounded-lg p-3" role="alert" data-testid="purchase-order-error">
            {{ t(errorKey) }}
        </p>

        <!-- A-24: a button in the page's language over a focusable input; the types and the limit are said beside it. -->
        <div v-if="canUpload" class="flex flex-col gap-1.5">
            <span id="purchase-order-file-title">{{ t('purchaseOrders.block.choose') }}</span>
            <label class="file-pick row-action inline-flex min-h-11 items-center self-start rounded-lg px-4" for="purchase-order-file">
                {{ t('files.choose') }}
                <input
                    id="purchase-order-file"
                    type="file"
                    :accept="FILE_ACCEPT"
                    :disabled="uploading"
                    class="sr-only"
                    aria-describedby="purchase-order-file-title purchase-order-file-name purchase-order-file-hint"
                    data-testid="purchase-order-file"
                    @change="onFileChosen"
                />
            </label>
            <p id="purchase-order-file-name" class="text-[var(--color-text-muted)]">{{ t('files.noFile') }}</p>
            <p id="purchase-order-file-hint" class="text-[var(--color-text-muted)]">{{ t('files.hint') }}</p>
        </div>
        <!-- A-31: present from load and empty; a live region announces what is set into it. -->
        <p role="status" data-testid="purchase-order-status">{{ uploading ? t('deals.documents.uploading') : '' }}</p>

        <LoadingState v-if="loading" label-key="purchaseOrders.loading" />
        <PermissionDeniedState v-else-if="denied" />
        <ErrorState v-else-if="failed" @retry="load" />

        <p v-else-if="documents.length === 0" data-testid="purchase-order-no-documents">
            {{ t('purchaseOrders.block.noDocuments') }}
        </p>

        <ul v-else class="flex flex-col gap-2">
            <li
                v-for="document in documents"
                :key="document.id"
                class="document-row flex flex-wrap items-center justify-between gap-2 rounded-lg p-3"
                data-testid="purchase-order-document"
            >
                <span>{{ document.original_name }}</span>

                <!-- `SEC-15` gates the download on the scan: its state is shown, not a broken button. -->
                <span v-if="document.scan_status === 'pending'">{{ t('deals.documents.scanPending') }}</span>
                <span v-else-if="document.scan_status === 'infected'" class="text-[var(--color-danger)]">
                    {{ t('deals.documents.scanInfected') }}
                </span>
                <button
                    v-else
                    type="button"
                    class="row-action min-h-11 rounded-lg px-3"
                    :disabled="downloadingId === document.id"
                    data-testid="purchase-order-download"
                    @click="download(document)"
                >
                    {{ t('deals.documents.download') }}
                </button>
            </li>
        </ul>
    </section>
</template>

<style scoped>
.po-block,
.document-row {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border);
}

.form-alert {
    background-color: var(--color-surface-muted);
    border: 1px solid var(--color-border-strong);
}
</style>
