<script setup lang="ts">
/**
 * An empty state in the Acme theme's look: what goes here, and (in the `action` slot) how to add the first one. It takes
 * the app's icon names, so the pages that use it don't change.
 */
withDefaults(defineProps<{ title: string; description?: string; icon?: string }>(), { description: undefined, icon: 'information-circle' });
</script>

<template>
    <div class="rounded-2xl border border-dashed border-line bg-surface" data-ui-feedback="empty">
        <div class="mx-auto flex max-w-md flex-col items-center px-6 py-12 text-center">
            <div class="relative mb-5 grid size-28 place-items-center" aria-hidden="true">
                <span class="absolute inset-0 rounded-full bg-accent/[.03] dark:bg-white/[.02]" />
                <span class="absolute inset-4 rounded-full bg-accent/[.05] dark:bg-white/[.04]" />
                <span class="absolute left-3 top-6 size-2 rounded-full bg-series-1/40" />
                <span class="absolute bottom-7 right-2 size-1.5 rounded-full bg-series-2/50" />
                <span class="absolute right-6 top-3 size-1 rounded-full bg-series-3/60" />
                <span class="relative grid size-14 rotate-[-6deg] place-items-center rounded-2xl border border-line bg-surface text-muted shadow-lift"><Icon :name="icon" class="h-6 w-6" /></span>
            </div>
            <h2 class="font-semibold text-ink">{{ title }}</h2>
            <p v-if="description" class="mt-1 text-sm text-muted">{{ description }}</p>
            <div v-if="$slots.action" class="mt-5 flex flex-wrap justify-center gap-2"><slot name="action" /></div>
        </div>
    </div>
</template>
