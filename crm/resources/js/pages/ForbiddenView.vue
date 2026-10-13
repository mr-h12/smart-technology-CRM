<script setup lang="ts">
/**
 * The route a guard sends someone to when their role does not hold the
 * permission a screen declares (`SEC-09`, §9.3).
 *
 * A route rather than an inline branch inside each page, so that "refused"
 * has one address the guard can name and Back does not land on the screen that
 * was just refused. The component itself adds nothing to
 * {@see PermissionDeniedState} — deliberately: that component takes no props
 * and names nothing about what was denied, because a denial screen that
 * identifies the resource turns an access boundary into an enumeration oracle.
 * The way onward is here, not in that component (A-43): Home names nothing.
 */
import { useI18n } from 'vue-i18n';
import PermissionDeniedState from '@/components/states/PermissionDeniedState.vue';

const { t } = useI18n();
</script>

<template>
    <div class="flex flex-col items-center gap-2">
        <!-- A-28: the page's one h1; the state below keeps its h2 because 18 pages draw it under their own h1. -->
        <h1 class="sr-only">{{ t('state.denied.title') }}</h1>
        <PermissionDeniedState />
        <RouterLink to="/" class="row-action inline-flex min-h-11 items-center rounded-lg px-4" data-testid="forbidden-home">
            {{ t('state.denied.home') }}
        </RouterLink>
    </div>
</template>
