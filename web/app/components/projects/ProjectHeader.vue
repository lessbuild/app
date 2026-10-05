<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';

/**
 * The top of every project page (the Acme theme's Platform page header): the page's title and its line, the project
 * switcher, the page's actions (the `actions` slot), the section tabs, and the notice on the sample project.
 */
const props = defineProps<{ overview: ProjectOverview; title?: string; description?: string | null }>();
const { t } = useT();
const project = computed(() => props.overview.project);
</script>

<template>
    <div>
        <PlatformHeader :title="title ?? project.name" :subtitle="title ? description : project.description">
            <template v-if="$slots.actions" #actions><slot name="actions" /></template>
        </PlatformHeader>
        <AcmeAlert v-if="project.isSample" tone="info" class="mb-6">
            {{ t('This is a sample project: its visits, requests and errors are made up. Nothing here reaches the outside world.') }}
            <NuxtLink v-if="overview.canManage" :to="`/projects/${project.id}/settings`" class="font-medium underline">{{ t('Delete it') }}</NuxtLink>
            {{ t('when you’re done, or create your own project.') }}
        </AcmeAlert>
    </div>
</template>
