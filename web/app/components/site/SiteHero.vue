<script setup lang="ts">
/**
 * The top of a public page (Acme's Stratus style): a dotted backdrop, a mono kicker, the page's heading and its
 * introduction. The default slot adds to the text column; the `aside` slot fills a second column.
 */
withDefaults(defineProps<{ kicker?: string; title: string; description?: string | null; centered?: boolean }>(), {
    kicker: undefined, description: undefined, centered: false,
});
</script>

<template>
    <section class="relative overflow-hidden border-b border-line">
        <div class="site-dots pointer-events-none absolute inset-0" aria-hidden="true" />
        <div :class="['site-frame relative grid gap-10 py-14 lg:py-20', $slots.aside ? 'items-center lg:grid-cols-[1.1fr_1fr]' : '', centered && 'text-center']">
            <div :class="centered && 'mx-auto max-w-3xl'">
                <p v-if="kicker" class="site-kicker">{{ kicker }}</p>
                <h1 class="site-h1 mt-3">{{ title }}</h1>
                <p v-if="description" :class="['mt-5 max-w-2xl text-lg leading-8 text-muted', centered && 'mx-auto']">{{ description }}</p>
                <slot />
            </div>
            <div v-if="$slots.aside" class="min-w-0"><slot name="aside" /></div>
        </div>
    </section>
</template>
