<script setup lang="ts">
import type { Box } from '~/types/audit';

/**
 * A screenshot from an audit with the elements in question outlined. Boxes are in screen pixels, so they're placed as
 * percentages of the screen size and stay on their elements at any width.
 */
withDefaults(defineProps<{ src: string; alt: string; boxes?: Box[]; screen: { width: number; height: number } }>(), { boxes: () => [] });
</script>

<template>
    <figure class="relative overflow-hidden rounded-card border border-line bg-surface-muted">
        <img :src="src" :alt="alt" :width="screen.width" :height="screen.height" loading="lazy" class="block h-auto w-full">
        <span v-for="(box, index) in boxes" :key="index" aria-hidden="true" class="absolute rounded-[3px] bg-[var(--ui-danger)]/10 outline outline-[3px] outline-offset-2 outline-[var(--ui-danger)]" :style="boxStyle(box, screen)" />
    </figure>
</template>
