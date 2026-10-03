<script setup lang="ts">
/** Signal's button: a <button>, or with `to` a link (inside the app it navigates without a full load). */
withDefaults(defineProps<{
    variant?: 'primary' | 'secondary' | 'quiet' | 'danger' | 'outline' | 'soft';
    size?: 'sm' | 'default' | 'lg';
    type?: 'button' | 'submit';
    /** Make it a link to this address (or, on this page, to these query parameters, such as a dialog). */
    to?: string | { query: Record<string, string | null | undefined> };
}>(), { variant: 'secondary', size: 'default', type: 'button', to: undefined });
</script>

<template>
    <NuxtLink v-if="to" :to="to" :class="['ui-btn', `ui-btn-${variant}`, { 'ui-btn-sm': size === 'sm', 'ui-btn-lg': size === 'lg' }]"><slot /></NuxtLink>
    <button v-else :type="type" :class="['ui-btn', `ui-btn-${variant}`, { 'ui-btn-sm': size === 'sm', 'ui-btn-lg': size === 'lg' }]"><slot /></button>
</template>
