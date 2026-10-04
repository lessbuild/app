<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';

/** Create a server, as a page of its own (the Servers page has it in a dialog). */
definePageMeta({ layout: 'app', service: 'infrastructure' });
const { t } = useT();
const route = useRoute();
const { data } = await useApi<{ overview: ProjectOverview }>(() => `/projects/${route.params.project}/infrastructure/servers/create`);
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Create a server')" :description="t('The server provisions itself with the software its type needs. It takes about ten minutes.')" />
        <section class="ui-card p-5 sm:p-6"><ServerCreateForm :project-id="data.overview.project.id" /></section>
    </div>
</template>
