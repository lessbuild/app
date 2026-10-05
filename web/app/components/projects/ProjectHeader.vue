<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';

/**
 * The top of every project page (the Acme theme's Platform page header): the page's title and its line, the project
 * switcher, the page's actions (the `actions` slot), the section tabs, the notice on an archived project, and the notice on the sample project (left out in
 * the drawer a create or edit page opens in).
 */
const props = defineProps<{ overview: ProjectOverview; title?: string; description?: string | null }>();
const { t } = useT();
const project = computed(() => props.overview.project);
const panel = usePagePanel();
</script>

<template>
    <div>
        <PlatformHeader :title="title ?? project.name" :subtitle="title ? description : project.description">
            <template v-if="$slots.actions" #actions><slot name="actions" /></template>
        </PlatformHeader>
        <AcmeAlert v-if="project.archivedAt && !panel" tone="warning" class="mb-6">
            {{ t('This project is archived: it’s off the projects list, and everything in it is kept.') }}
            <NuxtLink v-if="overview.canManage" :to="`/projects/${project.id}/settings`" class="font-medium underline">{{ t('Restore it in Settings') }}</NuxtLink>
        </AcmeAlert>
        <AcmeAlert v-if="project.isSample && !panel" tone="info" class="mb-6">
            {{ t('This is a sample project: its visits, requests and errors are made up. Nothing here reaches the outside world.') }}
            <NuxtLink v-if="overview.canManage" :to="`/projects/${project.id}/settings`" class="font-medium underline">{{ t('Delete it') }}</NuxtLink>
            {{ t('when you’re done, or create your own project.') }}
        </AcmeAlert>
    </div>
</template>
