<script setup lang="ts">
/** Signal's settings section: the title and description beside a card on wide screens, above it on phones. */
defineProps<{ title: string; description: string }>();
</script>

<template>
    <div class="grid scroll-mt-6 gap-6 lg:grid-cols-[minmax(0,.85fr)_minmax(0,1.5fr)]">
        <div class="hidden lg:block">
            <h2 class="text-lg font-bold leading-tight text-ink">{{ title }}</h2>
            <p class="mt-1 text-sm text-muted">{{ description }}</p>
        </div>
        <div class="ui-card overflow-hidden">
            <div class="border-b border-line px-4 py-4 lg:hidden">
                <h2 class="font-bold text-ink">{{ title }}</h2>
                <p class="mt-1 text-sm text-muted">{{ description }}</p>
            </div>
            <div><slot /></div>
            <div v-if="$slots.footer" class="border-t border-line"><slot name="footer" /></div>
        </div>
    </div>
</template>
