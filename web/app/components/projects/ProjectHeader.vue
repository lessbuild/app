<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';

/**
 * The top of every project page: the project's name (as the eyebrow on its sub-pages), the page's title and actions
 * (the `actions` slot), and the notice on the sample project.
 */
const props = defineProps<{ overview: ProjectOverview; title?: string; description?: string | null }>();
const { t } = useT();
const project = computed(() => props.overview.project);
</script>

<template>
    <div class="space-y-6">
        <PageHeader
            :eyebrow="title ? project.name : project.accountName"
            :title="title ?? project.name"
            :description="title ? description : project.description"
            :breadcrumbs="title ? [{ label: t('Projects'), to: '/dashboard' }, { label: project.name, to: `/projects/${project.id}` }] : [{ label: t('Projects'), to: '/dashboard' }]"
        >
            <template v-if="$slots.actions" #actions><slot name="actions" /></template>
        </PageHeader>
        <Alert v-if="project.isSample" tone="info">
            {{ t('This is a sample project: its visits, requests and errors are made up. Nothing here reaches the outside world.') }}
            <NuxtLink v-if="overview.canManage" :to="`/projects/${project.id}/settings`" class="font-semibold underline">{{ t('Delete it') }}</NuxtLink>
            {{ t('when you’re done, or create your own project.') }}
        </Alert>
    </div>
</template>
