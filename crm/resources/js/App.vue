<script setup lang="ts">
// Deliberately bare. The application shell — the three themes, the RTL/LTR
// layout and the navigation Design System §5 specifies — is point 4.2, not
// this one. Anything drawn here now would have to be discarded there.
//
// The one exception is the language switch: Module 0's acceptance criteria
// include "switching language flips direction", and that needs something to
// switch with. It moves into the shell at 4.2.
import { useI18n } from 'vue-i18n';
import { setLocale, SUPPORTED, type Locale } from '@/i18n';

const { t, locale } = useI18n();

function choose(next: Locale): void {
    // Direction, document language and the Accept-Language sent on the next API
    // call all change together — see setLocale.
    setLocale(next);
}
</script>

<template>
    <header>
        <nav :aria-label="t('language.switch')">
            <button
                v-for="option in SUPPORTED"
                :key="option"
                type="button"
                :aria-current="locale === option ? 'true' : undefined"
                :disabled="locale === option"
                :data-locale="option"
                @click="choose(option)"
            >
                {{ option === 'ar' ? t('language.arabic') : t('language.english') }}
            </button>
        </nav>
    </header>

    <main>
        <RouterView />
    </main>
</template>
