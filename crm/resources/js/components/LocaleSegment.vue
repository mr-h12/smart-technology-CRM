<script setup lang="ts">
/**
 * The language switch, used by the context bar and the login card (A-46).
 *
 * The visible token is the locale code; the accessible name is the language.
 * Neither button is disabled — a disabled control drops out of the tab order,
 * so the current language became unreachable by keyboard and unannounceable.
 * `aria-pressed` says the same thing without removing it.
 */
import { useI18n } from 'vue-i18n';
import { setLocale, SUPPORTED, type Locale } from '@/i18n';

const { t, locale } = useI18n();

function localeLabel(option: Locale): string {
    return option === 'ar' ? t('language.arabic') : t('language.english');
}
</script>

<template>
    <div
        class="segmented flex items-center gap-0.5 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-muted)] p-1"
        role="group"
        :aria-label="t('language.switch')"
        data-testid="locale-segment"
    >
        <!-- Direction, document language and the Accept-Language of the next
             API call all move together — see i18n.ts. -->
        <button
            v-for="option in SUPPORTED"
            :key="option"
            type="button"
            class="segmented__option grid min-h-11 min-w-11 place-items-center rounded-lg px-1 text-table text-[var(--color-text-muted)] hover:text-[var(--color-text)]"
            :class="locale === option ? 'segmented__option--on' : ''"
            :aria-pressed="locale === option"
            :title="localeLabel(option)"
            :data-locale="option"
            @click="setLocale(option)"
        >
            <!-- A machine token, never translated. -->
            <span aria-hidden="true" translate="no">{{ option.toUpperCase() }}</span>
            <span class="sr-only">{{ localeLabel(option) }}</span>
        </button>
    </div>
</template>
