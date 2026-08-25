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
 */
import PermissionDeniedState from '@/components/states/PermissionDeniedState.vue';
</script>

<template>
    <PermissionDeniedState />
</template>
