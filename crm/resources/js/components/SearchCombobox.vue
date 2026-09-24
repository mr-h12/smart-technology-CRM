<script setup lang="ts" generic="T extends { id: string; name: string }">
/**
 * F-18 · 1.1 — the one server-searched combobox every picker is built on.
 *
 * `CustomerPicker` (`D-84`) and `SupplierPicker` (`D-86`) were one search and
 * one keyboard written twice; this is that half, once. Nothing is asked until
 * the input opens; opening asks for the first 20 by name, and typing asks the
 * server with `q` after 300 ms of quiet (`OpenAPI §6.2` — `q` "always passes
 * through `SearchService`", which folds the Arabic letter variants). A 403 is
 * a refusal, not an outage, so the screen around a picker keeps working.
 *
 * The keyboard follows the WAI-ARIA APG combobox with a listbox popup: focus
 * stays on the input and `aria-activedescendant` names the active option. An
 * open list takes its own Escape — Design System §6.1, a menu closes and not
 * the dialog around it (the owner's ruling, 2026-09-24); a closed list lets
 * the next Escape through.
 *
 * What differs stays with the caller: which list `search` reads, the lang
 * `keys` its states speak, which rows are `selected`, `multiple` (a pick
 * toggles and the list stays open), `floating` (the list over the page rather
 * than in its flow — a dialog that scrolls clips a floating list), `leading`
 * (a first option that picks nothing, a filter's «كل العملاء»), and `display`
 * (what a single picker's box shows while nobody types). Attributes such as
 * `id`, `disabled`, `placeholder` and `aria-invalid` fall through to the input.
 */
import { computed, ref, shallowRef, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { ApiError, type Page } from '@/api';

defineOptions({ inheritAttrs: false });

const props = defineProps<{
    testId: string;
    search: (query: { perPage: number; q: string | null }) => Promise<Page<T>>;
    keys: { more: string; forbidden: string; failed: string; empty: string; noMatch: string; retry: string };
    selected: (item: T) => boolean;
    multiple?: boolean;
    floating?: boolean;
    leading?: { label: string; selected: boolean } | undefined;
    display?: string;
}>();

const emit = defineEmits<{ pick: [item: T | null] }>();

const { t } = useI18n();

/** 20 is small enough to scan and large enough that two letters usually find the row. */
const PAGE = 20;
const PAUSE_MS = 300;

const listboxId = useId();
const optionId = (index: number): string => `${listboxId}-option-${index}`;

const open = ref(false);
const text = ref('');
const searched = ref('');
const items = shallowRef<T[]>([]);
const total = ref(0);
const status = ref<'idle' | 'loading' | 'ready' | 'forbidden' | 'failed'>('idle');
const active = ref(-1);

/** `null` is `leading`'s pick-nothing, always first; the rows only once they came back. */
const options = computed<(T | null)[]>(() => [
    ...(props.leading === undefined ? [] : [null]),
    ...(status.value === 'ready' ? items.value : []),
]);

let sequence = 0;
let pause: ReturnType<typeof setTimeout> | undefined;

async function search(): Promise<void> {
    const mine = ++sequence;
    status.value = 'loading';

    try {
        const result = await props.search({ perPage: PAGE, q: searched.value === '' ? null : searched.value });
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

function pick(item: T | null): void {
    emit('pick', item);

    if (!props.multiple) {
        text.value = item === null ? '' : item.name;
        open.value = false;
        active.value = -1;
    }
}

const root = ref<HTMLElement | null>(null);

/** Closes only when focus leaves the whole picker, so Tab can reach Clear, a chip and Retry. */
function left(event: FocusEvent): void {
    if (!(event.relatedTarget instanceof Node && root.value?.contains(event.relatedTarget))) {
        close();
    }
}

function close(): void {
    open.value = false;
    active.value = -1;

    if (props.display !== undefined) {
        text.value = props.display;
    }
}

function keydown(event: KeyboardEvent): void {
    const count = options.value.length;
    const option = options.value[active.value];

    if (event.key === 'ArrowDown') {
        event.preventDefault();
        show();
        active.value = count === 0 ? -1 : Math.min(active.value + 1, count - 1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        active.value = count === 0 ? -1 : Math.max(active.value - 1, 0);
    } else if (event.key === 'Enter' && open.value && option !== undefined) {
        event.preventDefault();
        pick(option);
    } else if (event.key === 'Escape' && open.value) {
        event.preventDefault();
        event.stopPropagation();
        close();
    }
}

/** A value emptied from outside empties the box; nothing else outside moves it. */
watch(
    () => props.display,
    (value) => {
        if (value === '') {
            text.value = '';
        }
    },
);
</script>

<template>
    <div ref="root" class="flex flex-col gap-2" :class="{ relative: floating }" @focusout="left">
        <slot name="before" />

        <div class="flex items-center gap-1">
            <input
                v-bind="$attrs"
                v-model="text"
                type="text"
                role="combobox"
                autocomplete="off"
                aria-autocomplete="list"
                :aria-expanded="open ? 'true' : 'false'"
                :aria-controls="listboxId"
                :aria-activedescendant="active >= 0 ? optionId(active) : undefined"
                class="form-field min-h-11 w-full rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                :data-testid="testId"
                @focus="show"
                @input="typed"
                @keydown="keydown"
            />
            <slot name="inputEnd" :pick="pick" />
        </div>

        <div
            v-show="open"
            class="mt-1 max-h-80 overflow-y-auto rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-raised)] shadow-lg"
            :class="{ 'absolute inset-x-0 top-full z-20': floating }"
        >
            <ul
                :id="listboxId"
                role="listbox"
                :aria-multiselectable="multiple ? 'true' : undefined"
                :aria-busy="status === 'loading' ? 'true' : 'false'"
            >
                <li
                    v-for="(option, index) in options"
                    :id="optionId(index)"
                    :key="option === null ? '' : option.id"
                    role="option"
                    :aria-selected="(option === null ? leading?.selected : selected(option)) ? 'true' : 'false'"
                    class="cursor-pointer px-3 py-2"
                    :class="{ 'flex items-start gap-2': multiple, 'bg-[var(--color-surface-muted)]': index === active }"
                    :data-testid="option === null ? `${testId}-all` : `${testId}-option`"
                    @mousedown.prevent="pick(option)"
                >
                    <template v-if="option === null">{{ leading?.label }}</template>
                    <template v-else>
                        <span v-if="multiple" aria-hidden="true" class="w-4 shrink-0">{{ selected(option) ? '✓' : '' }}</span>
                        <slot name="option" :item="option" />
                    </template>
                </li>
            </ul>

            <p
                v-if="status === 'ready' && total > items.length"
                class="px-3 py-2 text-sm text-[var(--color-text-muted)]"
                :data-testid="`${testId}-more`"
            >
                {{ t(keys.more) }}
            </p>

            <p
                v-if="status === 'forbidden' || status === 'failed' || (status === 'ready' && items.length === 0)"
                class="px-3 py-2 text-sm text-[var(--color-text-muted)]"
                role="status"
                :data-testid="`${testId}-state`"
            >
                <template v-if="status === 'forbidden'">{{ t(keys.forbidden) }}</template>
                <template v-else-if="status === 'failed'">{{ t(keys.failed) }}</template>
                <template v-else-if="searched === ''">{{ t(keys.empty) }}</template>
                <template v-else>{{ t(keys.noMatch, { query: searched }) }}</template>
            </p>

            <button
                v-if="status === 'failed'"
                type="button"
                class="mx-3 mb-2 min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                :data-testid="`${testId}-retry`"
                @click="search"
            >
                {{ t(keys.retry) }}
            </button>
        </div>
    </div>
</template>

<style scoped>
/* A parent's scoped `.form-field` stops at this component's root, so the
   input carries its own copy of the rule — the same three lines every form
   declares (a shared rule is registered as debt, not taken here). */
.form-field {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border-strong);
    color: var(--color-text);
}
</style>
