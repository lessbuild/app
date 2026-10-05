<script setup lang="ts">
/** A stat (the Acme theme's plain stat tile): a label, a value and a line about it. */
withDefaults(defineProps<{ label: string; value: string; description?: string | null }>(), { description: undefined });
</script>

<template>
    <div class="rounded-2xl border border-line bg-surface p-5 shadow-card">
        <p class="text-xs text-muted">{{ label }}</p>
        <p class="mt-2 text-xl font-semibold tabular-nums tracking-tight text-ink">{{ value }}</p>
        <p v-if="description" class="mt-1 text-xs text-muted">{{ description }}</p>
        <slot />
    </div>
</template>
