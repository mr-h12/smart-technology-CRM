<script setup lang="ts">
/**
 * F-10 · 1.8 (`D-86`) — suppliers chosen by searching the server, many at once.
 *
 * `CustomerPicker`'s search and keyboard (WAI-ARIA APG combobox, listbox
 * popup, `aria-activedescendant`), many-valued: a choice toggles in the
 * listbox and shows as a removable chip. Nothing is asked until the input
 * opens; opening asks for the first 20 by name, typing asks with `q` after
 * 300 ms (`OpenAPI §6.2` — `q` passes through `SearchService`). Deactivated
 * suppliers are offered — ruling A2 lets one be linked — and say so, with the
 * contact and phone, so two suppliers with one name can be told apart.
 *
 * The model is `{id, name}` pairs, so the chips need no second request: the
 * caller already holds the names (the catalog item's read carries them).
 *
 * The list sits in the flow, not floated: the form it lives in is a dialog
 * that scrolls, and an absolute list was clipped by it (`rtl-ui-verifier`,
 * 2026-09-22). In the flow it pushes the dialog longer instead.
 *
 * ponytail: the search/keyboard half is a copy of `CustomerPicker`'s — a
 * shared combobox is registered debt, not taken inside a catalog point.
 */
import { computed, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import { ApiError } from '@/api';
import { listSuppliers, type Supplier } from '@/services/suppliers';

defineOptions({ inheritAttrs: false });

type Picked = Pick<Supplier, 'id' | 'name'>;

defineProps<{ testId: string; disabled?: boolean }>();

const model = defineModel<Picked[]>({ required: true });

const { t } = useI18n();

const PAGE = 20;
const PAUSE_MS = 300;

const listboxId = useId();
const optionId = (index: number): string => `${listboxId}-option-${index}`;

const open = ref(false);
const text = ref('');
const searched = ref('');
const items = ref<Supplier[]>([]);
const total = ref(0);
const status = ref<'idle' | 'loading' | 'ready' | 'forbidden' | 'failed'>('idle');
const active = ref(-1);

const chosen = computed(() => new Set(model.value.map((picked) => picked.id)));

/** Contact and phone when present, then the inactive word — what tells two same-named suppliers apart. */
function detail(supplier: Supplier): string {
    return [supplier.contact_person, supplier.phone, supplier.is_active ? null : t('suppliers.status.inactive')]
        .filter((part): part is string => typeof part === 'string' && part !== '')
        .join(t('suppliers.picker.detailSeparator'));
}

let sequence = 0;
let pause: ReturnType<typeof setTimeout> | undefined;

async function search(): Promise<void> {
    const mine = ++sequence;
    status.value = 'loading';

    try {
        const result = await listSuppliers({ perPage: PAGE, q: searched.value === '' ? null : searched.value });
        if (mine !== sequence) return;
        items.value = result.items;
        total.value = result.pagination.total;
        status.value = 'ready';
    } catch (error) {
        if (mine !== sequence) return;
        items.value = [];
        status.value = error instanceof ApiError && error.status === 403 ? 'forbidden' : 'failed';
    }

    active.value = -1;
}

function show(): void {
    open.value = true;

    if (status.value === 'idle') {
        void search();
    }
}

function typed(): void {
    open.value = true;
    active.value = -1;
    clearTimeout(pause);
    pause = setTimeout(() => {
        searched.value = text.value.trim();
        void search();
    }, PAUSE_MS);
}

function toggle(supplier: Picked): void {
    model.value = chosen.value.has(supplier.id)
        ? model.value.filter((picked) => picked.id !== supplier.id)
        : [...model.value, { id: supplier.id, name: supplier.name }];
}

const root = ref<HTMLElement | null>(null);

function left(event: FocusEvent): void {
    if (!(event.relatedTarget instanceof Node && root.value?.contains(event.relatedTarget))) {
        close();
    }
}

function close(): void {
    open.value = false;
    active.value = -1;
}

function keydown(event: KeyboardEvent): void {
    const count = status.value === 'ready' ? items.value.length : 0;

    if (event.key === 'ArrowDown') {
        event.preventDefault();
        show();
        active.value = count === 0 ? -1 : Math.min(active.value + 1, count - 1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        active.value = count === 0 ? -1 : Math.max(active.value - 1, 0);
    } else if (event.key === 'Enter' && open.value && items.value[active.value] !== undefined) {
        event.preventDefault();
        toggle(items.value[active.value]!);
    } else if (event.key === 'Escape' && open.value) {
        // The list's Escape, not the dialog's: a closed list lets the next one through.
        event.preventDefault();
        event.stopPropagation();
        close();
    }
}
</script>

<template>
    <div ref="root" class="flex flex-col gap-2" @focusout="left">
        <ul v-if="model.length > 0" class="flex flex-wrap gap-2">
            <li
                v-for="picked in model"
                :key="picked.id"
                class="flex min-h-11 items-center gap-1 rounded-full border border-[var(--color-border)] bg-[var(--color-surface-muted)] ps-3"
                :data-testid="`${testId}-chip`"
            >
                <span>{{ picked.name }}</span>
                <button
                    type="button"
                    class="min-h-11 min-w-11 rounded-full focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    :aria-label="t('suppliers.picker.remove', { name: picked.name })"
                    :disabled="disabled"
                    :data-testid="`${testId}-chip-remove`"
                    @click="toggle(picked)"
                >
                    <span aria-hidden="true">×</span>
                </button>
            </li>
        </ul>

        <input
            v-bind="$attrs"
            v-model="text"
            :disabled="disabled"
            type="text"
            role="combobox"
            autocomplete="off"
            aria-autocomplete="list"
            :aria-expanded="open ? 'true' : 'false'"
            :aria-controls="listboxId"
            :aria-activedescendant="active >= 0 ? optionId(active) : undefined"
            :placeholder="t('suppliers.picker.placeholder')"
            class="form-field min-h-11 w-full rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
            :data-testid="testId"
            @focus="show"
            @input="typed"
            @keydown="keydown"
        />

        <div
            v-show="open"
            class="mt-1 max-h-80 overflow-y-auto rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-raised)] shadow-lg"
        >
            <ul :id="listboxId" role="listbox" aria-multiselectable="true" :aria-busy="status === 'loading' ? 'true' : 'false'">
                <template v-if="status === 'ready'">
                    <li
                        v-for="(supplier, index) in items"
                        :id="optionId(index)"
                        :key="supplier.id"
                        role="option"
                        :aria-selected="chosen.has(supplier.id) ? 'true' : 'false'"
                        class="flex cursor-pointer items-start gap-2 px-3 py-2"
                        :class="{ 'bg-[var(--color-surface-muted)]': index === active }"
                        :data-testid="`${testId}-option`"
                        @mousedown.prevent="toggle(supplier)"
                    >
                        <span aria-hidden="true" class="w-4 shrink-0">{{ chosen.has(supplier.id) ? '✓' : '' }}</span>
                        <span>
                            <span class="block">{{ supplier.name }}</span>
                            <span
                                v-if="detail(supplier) !== ''"
                                class="block text-sm text-[var(--color-text-muted)]"
                                :data-testid="`${testId}-option-detail`"
                            >{{ detail(supplier) }}</span>
                        </span>
                    </li>
                </template>
            </ul>

            <p
                v-if="status === 'ready' && total > items.length"
                class="px-3 py-2 text-sm text-[var(--color-text-muted)]"
                :data-testid="`${testId}-more`"
            >{{ t('suppliers.picker.more') }}</p>

            <p
                v-if="status === 'forbidden' || status === 'failed' || (status === 'ready' && items.length === 0)"
                class="px-3 py-2 text-sm text-[var(--color-text-muted)]"
                role="status"
                :data-testid="`${testId}-state`"
            >
                <template v-if="status === 'forbidden'">{{ t('suppliers.picker.forbidden') }}</template>
                <template v-else-if="status === 'failed'">{{ t('suppliers.picker.failed') }}</template>
                <template v-else-if="searched === ''">{{ t('suppliers.picker.empty') }}</template>
                <template v-else>{{ t('suppliers.picker.noMatch', { query: searched }) }}</template>
            </p>

            <button
                v-if="status === 'failed'"
                type="button"
                class="mx-3 mb-2 min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                :data-testid="`${testId}-retry`"
                @click="search"
            >{{ t('state.retry') }}</button>
        </div>
    </div>
</template>

<style scoped>
/* `CustomerPicker`'s copy of the form-field rule, for the same reason:
   a parent's scoped `.form-field` stops at this component's root. */
.form-field {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border-strong);
    color: var(--color-text);
}
</style>
