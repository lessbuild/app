<script setup lang="ts">
/** A Signal icon, drawn inline from the design system's paths. Decorative: give the control its own label. */
const props = defineProps<{ name: string }>();
const attrs = useAttrs();
const icon = computed(() => icons[props.name] ?? icons['information-circle']);
</script>

<template>
    <svg
        v-if="icon"
        :class="attrs.class ? undefined : 'h-5 w-5'"
        :viewBox="icon.viewBox"
        fill="none"
        :stroke="icon.stroke ? 'currentColor' : undefined"
        :stroke-width="icon.stroke ? 1.8 : undefined"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
        v-html="icon.body"
    />
</template>
