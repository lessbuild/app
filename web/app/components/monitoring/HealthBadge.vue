<script setup lang="ts">
import type { Tone } from '~/types/ui';

/** A monitor's health as a badge: `health` is the English state (Up, Down, Paused…) and `label` its translation. */
const props = defineProps<{ health: string; label: string }>();
const tone = computed<Tone>(() => ({ Up: 'success', Down: 'danger', Paused: 'neutral', Archived: 'neutral' } as Record<string, Tone>)[props.health] ?? 'warning');
</script>

<template>
    <AcmeBadge :tone="acmeTone(tone)" dot>{{ label }}</AcmeBadge>
</template>
