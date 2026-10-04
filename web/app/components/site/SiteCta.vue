<script setup lang="ts">
/**
 * The dark call to action that closes a public page (Acme's Stratus "night" panel): a kicker, a heading, a line of
 * text and the `actions` slot, with an optional `aside` column.
 */
defineProps<{ kicker: string; title: string; text: string }>();
</script>

<template>
    <SiteSection pad="md">
        <div :class="['site-night grid items-center gap-8 p-8 sm:p-12', $slots.aside && 'lg:grid-cols-[1.3fr_1fr]']">
            <div>
                <p class="font-mono text-[0.6875rem] uppercase tracking-wider text-white/50">{{ kicker }}</p>
                <h2 class="mt-3 text-[clamp(1.75rem,3vw,2.5rem)] font-semibold leading-tight tracking-tight">{{ title }}</h2>
                <p class="mt-3 max-w-md text-white/65">{{ text }}</p>
                <div class="mt-8 flex flex-wrap gap-2"><slot name="actions" /></div>
            </div>
            <div v-if="$slots.aside"><slot name="aside" /></div>
        </div>
    </SiteSection>
</template>
