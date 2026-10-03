<script setup lang="ts">
import type { ServiceOption } from '~/types/projects';

/**
 * The new-project wizard in a dialog over the page (`?dialog=new-project`), loading the service choices when it opens.
 * The `trigger` slot gets `open`.
 */
const { t } = useT();
const route = useRoute();
const services = ref<ServiceOption[] | null>(null);
const failed = ref(false);

/** Load the service choices the first time the dialog opens. */
async function load() {
    if (services.value === null) {
        services.value = await send<{ services: ServiceOption[] }>('GET', '/projects/new').then((data) => data.services).catch(() => {
            failed.value = true;
            return null;
        });
    }
}

watch(() => route.query.dialog, (dialog) => {
    if (import.meta.client && dialog === 'new-project') {
        load();
    }
}, { immediate: true });
</script>

<template>
    <UiDialog id="new-project" :title="t('New project')" size="large">
        <template #trigger="{ open }"><slot name="trigger" :open="open" /></template>
        <template #default>
            <NewProjectWizard v-if="services" :services="services" />
            <Alert v-else-if="failed" tone="danger" role="alert">{{ t('Something went wrong. Try again.') }}</Alert>
            <p v-else class="text-sm text-muted" role="status">{{ t('Loading…') }}</p>
        </template>
    </UiDialog>
</template>
