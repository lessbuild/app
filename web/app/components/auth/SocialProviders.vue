<script setup lang="ts">
/** "Or continue with" and a button per social provider set up here. Links go to Laravel, which runs the round trip. */
defineProps<{ providers: Array<{ key: string; label: string; url: string }> }>();
const { t } = useT();
</script>

<template>
    <div v-if="providers.length > 0" class="grid gap-3">
        <div class="flex items-center gap-3 text-xs font-bold uppercase tracking-wide text-muted" aria-hidden="true">
            <span class="h-px flex-1 bg-line" />
            {{ t('Or continue with') }}
            <span class="h-px flex-1 bg-line" />
        </div>
        <div :class="['grid gap-2', providers.length === 2 && 'sm:grid-cols-2', providers.length >= 3 && 'sm:grid-cols-3']">
            <a v-for="provider in providers" :key="provider.key" :href="provider.url" class="ui-btn ui-btn-secondary w-full justify-center">{{ provider.label }}</a>
        </div>
    </div>
</template>
