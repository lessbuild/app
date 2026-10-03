<script setup lang="ts">
/**
 * A translated sentence whose :placeholders are elements, such as links: pass the translated sentence as `text`
 * and a slot for each placeholder (`#terms` for `:terms`).
 */
const props = defineProps<{ text: string }>();
const slots = useSlots();
const pieces = computed(() => {
    const names = Object.keys(slots).sort((a, b) => b.length - a.length);
    return names.length ? props.text.split(new RegExp(`:(${names.join('|')})`, 'g')) : [props.text];
});
</script>

<template>
    <template v-for="(piece, index) in pieces" :key="index">
        <slot v-if="index % 2 === 1" :name="piece" />
        <template v-else>{{ piece }}</template>
    </template>
</template>
