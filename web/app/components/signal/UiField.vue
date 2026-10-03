<script setup lang="ts">
/** A labelled field for a control the page manages itself (outside an ApiForm), with its help text and error. */
defineProps<{ id: string; label: string; description?: string; error?: string }>();
</script>

<template>
    <div class="grid gap-1.5">
        <label :for="id" class="ui-label">{{ label }}</label>
        <p v-if="description" :id="`${id}-help`" class="ui-help">{{ description }}</p>
        <slot />
        <p v-if="error" :id="`${id}-error`" class="ui-error" role="alert">{{ error }}</p>
    </div>
</template>
