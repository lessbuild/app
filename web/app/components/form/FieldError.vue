<script setup lang="ts">
/** A field's error from the form it's in, shown as Signal's inline alert. */
const props = defineProps<{ name: string; id?: string }>();
const form = useForm();
const message = computed(() => firstError(form.errors, props.name));
</script>

<template>
    <div v-if="message" :id="id" data-form-error class="my-2" aria-live="polite">
        <div class="ui-alert ui-alert--danger ui-alert-danger text-sm" role="alert">{{ message }}</div>
    </div>
</template>
