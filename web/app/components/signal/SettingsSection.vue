<script setup lang="ts">
/**
 * A settings row (the Acme theme's settings row): the title and description on the left, the card with the controls on
 * the right on wide screens, one above the other on phones.
 */
defineProps<{ title: string; description: string }>();
</script>

<template>
    <div class="grid scroll-mt-6 gap-4 border-b border-line pb-7 last:border-b-0 md:grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)] md:gap-10">
        <div>
            <h2 class="text-[1.125rem] font-semibold text-ink">{{ title }}</h2>
            <p class="mt-1 text-[0.9375rem] text-muted">{{ description }}</p>
        </div>
        <div class="min-w-0 self-start overflow-hidden rounded-2xl border border-line bg-surface shadow-card">
            <div><slot /></div>
            <div v-if="$slots.footer" class="border-t border-line"><slot name="footer" /></div>
        </div>
    </div>
</template>
