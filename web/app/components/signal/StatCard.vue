<script setup lang="ts">
/** Signal's stat: a label, a value and a line about it, in a card. */
withDefaults(defineProps<{ label: string; value: string; description?: string | null }>(), { description: undefined });
</script>

<template>
    <div class="ui-card ui-stat p-5">
        <p class="ui-stat__label">{{ label }}</p>
        <p class="ui-stat__value">{{ value }}</p>
        <p v-if="description" class="ui-stat__description">{{ description }}</p>
    </div>
</template>
