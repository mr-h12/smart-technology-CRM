<script setup lang="ts">
/**
 * §4.4's manual moves, at the screen (Module 5, Point 6.5; F-49 · 1.3, `D-119`).
 *
 * ── By hand, only the three ────────────────────────────────────────────────
 *
 * `D-119`: every other status is reached on its own — creation, a quotation,
 * the customer's answer, a recorded rejection — and by hand only `purchasing`,
 * `delivery` and `delivery_complete` are chosen. Each has exactly one way in,
 * so the control is one button naming the next status, drawn only where one
 * is left. The server still decides: the narrowed list (422), the edge (409,
 * for a page gone stale) and `delivery_complete`'s `D-14` permission (403),
 * which this control does not guess at — it draws on `change_status` alone.
 *
 * ── One click, then the confirm (F-42 · P7) ────────────────────────────────
 *
 * A status change is audited and §4.4 has no way back, so the button opens
 * F-42's `ConfirmDialog`, and only its confirm calls the server. A refusal is
 * drawn inside the dialog, so it can be read before cancelling.
 */
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ApiError } from '@/api';
import ConfirmDialog from '@/components/users/ConfirmDialog.vue';
import { changeDealStatus, type Deal } from '@/services/deals';
import { useAuth } from '@/stores/auth';

const props = defineProps<{ deal: Deal }>();
const emit = defineEmits<{ changed: [Deal] }>();

const { t } = useI18n();
const auth = useAuth();

// ponytail: §4.4's last three edges copied from `DealStatusTransition`; an
// `allowed_transitions` field on the deal payload (debt register) replaces it.
const NEXT: Record<string, string> = { won: 'purchasing', purchasing: 'delivery', delivery: 'delivery_complete' };

const next = computed(() => NEXT[props.deal.status]);
const confirming = ref(false);
const busy = ref(false);
const errorKey = ref<string | null>(null);

const canChange = computed(() => auth.hasPermission('deal.change_status'));

function refusalKey(error: unknown): string {
    if (!(error instanceof ApiError)) {
        return 'deals.status.unreachable';
    }

    if (error.status === 403) {
        return 'deals.status.forbidden';
    }

    // The server's own graph refused this edge — the deal moved meanwhile.
    return error.status === 409 ? 'deals.status.notAllowed' : 'deals.status.rejectedByServer';
}

/** Cancel, Escape and a success close the confirm; the dialog hands focus back to the button. */
function reset(): void {
    confirming.value = false;
    errorKey.value = null;
}

async function submit(): Promise<void> {
    if (next.value === undefined) {
        return;
    }

    errorKey.value = null;
    busy.value = true;

    try {
        const changed = await changeDealStatus(props.deal.id, next.value);

        reset();
        emit('changed', changed);
    } catch (error) {
        errorKey.value = refusalKey(error);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div v-if="canChange && next !== undefined" data-testid="deal-status-control">
        <button
            type="button"
            :disabled="busy"
            class="row-action inline-flex min-h-11 items-center rounded-lg px-3"
            data-testid="deal-status-next"
            @click="confirming = true"
        >
            {{ t('deals.status.moveTo', { name: t(`deals.status.${next}`) }) }}
        </button>

        <ConfirmDialog
            :open="confirming"
            title-key="deals.status.changeAction"
            message-key="deals.status.confirmMessage"
            confirm-key="deals.status.changeAction"
            :subject="t(`deals.status.${next}`)"
            :busy="busy"
            @confirm="submit()"
            @cancel="reset()"
        >
            <p v-if="errorKey !== null" class="form-alert rounded-lg p-3" role="alert" data-testid="deal-status-error">
                {{ t(errorKey) }}
            </p>
        </ConfirmDialog>
    </div>
</template>

<style scoped>
.form-alert {
    background-color: var(--color-surface-muted);
    border: 1px solid var(--color-border-strong);
}
</style>
