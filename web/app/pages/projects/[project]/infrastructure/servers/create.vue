<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';

/** Create a server (the Acme theme's create server page). The form loads the provider's regions and sizes itself. */
definePageMeta({ layout: 'app', service: 'infrastructure' });
const { t } = useT();
const route = useRoute();
const { data } = await useApi<{ overview: ProjectOverview }>(() => `/projects/${route.params.project}/infrastructure/servers/create`);
</script>

<template>
    <div>
        <ProjectHeader :overview="data.overview" :title="t('Create a server')" :description="t('The server provisions itself with the software its type needs. It takes about ten minutes.')" />
        <ServerCreateForm :project-id="data.overview.project.id" />
    </div>
</template>
