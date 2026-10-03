<script setup lang="ts">
import type { Tone } from '~/types/ui';

/** Signal's badge: a short status label. */
const props = withDefaults(defineProps<{ tone?: Tone }>(), { tone: 'neutral' });
const signal = computed(() => (props.tone === 'accent' ? 'primary' : props.tone === 'neutral' ? 'soft' : props.tone));
</script>

<template>
    <span :class="['ui-badge', `ui-badge--${tone}`, `ui-badge-${signal}`]"><slot /></span>
</template>
