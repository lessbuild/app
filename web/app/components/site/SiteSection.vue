<script setup lang="ts">
/**
 * A full-width band of the public site with the page's framed column inside it (Acme's Stratus layout): a rule below,
 * rules either side of the column, and an optional tint.
 */
withDefaults(defineProps<{ tint?: boolean; pad?: 'lg' | 'md' | 'sm' | 'none'; labelledby?: string; frameClass?: string }>(), {
    tint: false, pad: 'lg', labelledby: undefined, frameClass: undefined,
});
</script>

<template>
    <section :class="['border-b border-line', tint && 'bg-surface-muted']" :aria-labelledby="labelledby">
        <div :class="['site-frame', { lg: 'py-16 sm:py-20', md: 'py-12 sm:py-16', sm: 'py-5', none: '' }[pad], frameClass]"><slot /></div>
    </section>
</template>
