<script setup lang="ts">
import type { ServiceOption } from '~/types/projects';

/** The new-project wizard as a full page, for links from outside the app; inside it opens as a dialog. */
definePageMeta({ layout: 'app' });
const { t } = useT();
const { data } = await useApi<{ services: ServiceOption[] }>('/projects/new');
</script>

<template>
    <div>
        <PageHeader :title="t('New project')" :breadcrumbs="[{ label: t('Projects'), to: '/dashboard' }]" />
        <div class="ui-card max-w-3xl p-5 sm:p-6">
            <NewProjectWizard :services="data.services" />
        </div>
    </div>
</template>
