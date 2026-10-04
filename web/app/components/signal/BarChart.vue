<script setup lang="ts">
/**
 * Signal's small bar chart: one series over time, with each bar's value and time as its tooltip and a hidden table
 * for screen readers. Values are 0–max (100 by default, for percentages).
 */
const props = withDefaults(defineProps<{ label: string; points: Array<{ label: string; value: number }>; unit?: string; max?: number }>(), { unit: '', max: 100 });
const width = 600;
const height = 96;
const bar = computed(() => (props.points.length === 0 ? 0 : width / props.points.length));
const top = computed(() => Math.max(props.max, ...props.points.map((point) => point.value)));
</script>

<template>
    <figure class="grid gap-1">
        <svg :viewBox="`0 0 ${width} ${height}`" class="h-24 w-full" preserveAspectRatio="none" role="img" :aria-label="label">
            <line x1="0" :x2="width" :y1="height - 0.5" :y2="height - 0.5" class="stroke-line" stroke-width="1" />
            <rect
                v-for="(point, index) in points"
                :key="index"
                :x="index * bar + 1"
                :y="height - Math.max(2, (point.value / top) * (height - 4))"
                :width="Math.max(1, bar - 2)"
                :height="Math.max(2, (point.value / top) * (height - 4))"
                rx="2"
                class="fill-primary/70 hover:fill-primary"
            >
                <title>{{ point.label }}: {{ point.value }}{{ unit }}</title>
            </rect>
        </svg>
        <figcaption class="text-xs text-muted">{{ label }}</figcaption>
        <table class="sr-only">
            <caption>{{ label }}</caption>
            <tbody><tr v-for="(point, index) in points" :key="index"><th scope="row">{{ point.label }}</th><td>{{ point.value }}{{ unit }}</td></tr></tbody>
        </table>
    </figure>
</template>
