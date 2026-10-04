<script setup lang="ts">
import type { ObjectiveSummary } from '~/types/monitoring';
import type { ProjectOverview } from '~/types/projects';

/** The project's service level objectives and how much error budget each has left. */
definePageMeta({ layout: 'app', service: 'monitoring' });
const { t } = useT();
const route = useRoute();
const { data } = await useApi<{ overview: ProjectOverview; objectives: ObjectiveSummary[]; canManage: boolean }>(() => `/projects/${route.params.project}/monitoring/objectives`);
const project = computed(() => data.value.overview.project);
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Service level objectives')" :description="t('Availability or latency targets over a rolling window, and how much error budget is left.')">
            <template v-if="data.canManage" #actions>
                <UiButton variant="primary" :to="`/projects/${project.id}/monitoring/objectives/create`"><Icon name="plus" class="h-4 w-4" />{{ t('Add an objective') }}</UiButton>
            </template>
        </ProjectHeader>
        <EmptyState v-if="data.objectives.length === 0" icon="check-circle" :title="t('No objectives yet')" :description="t('For example: 99.9% of requests succeed over 30 days, or 95% finish within 300 ms.')" />
        <section v-else class="ui-card overflow-hidden">
            <ul class="divide-y divide-line" :aria-label="t('Objectives')">
                <li v-for="objective in data.objectives" :key="objective.id" class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                    <div class="min-w-0">
                        <NuxtLink :to="`/projects/${project.id}/monitoring/objectives/${objective.id}`" class="font-extrabold text-ink hover:underline">{{ objective.name }}</NuxtLink>
                        <p class="mt-0.5 text-xs text-muted">{{ objective.indicator }} · {{ t(':target% target', { target: objective.target }) }} · {{ objective.window }} · {{ objective.environment }}</p>
                    </div>
                    <span class="flex items-center gap-3 text-sm">
                        <span class="tabular-nums text-muted">{{ objective.compliance === null ? '—' : `${objective.compliance}%` }}</span>
                        <ObjectiveBadge :status="objective.status" :enabled="objective.enabled" />
                    </span>
                </li>
            </ul>
        </section>
    </div>
</template>
