<script setup lang="ts">
import type { AlertRuleSummary } from '~/types/monitoring';
import type { ProjectOverview } from '~/types/projects';

/** The project's alert rules: what each watches and whether it's breaching now. */
definePageMeta({ layout: 'app', service: 'monitoring' });
const { t, tc } = useT();
const route = useRoute();
const { data } = await useApi<{ overview: ProjectOverview; rules: AlertRuleSummary[]; canManage: boolean }>(() => `/projects/${route.params.project}/monitoring/rules`);
const project = computed(() => data.value.overview.project);
const tones: Record<string, 'danger' | 'success' | 'neutral' | 'warning'> = { breaching: 'danger', healthy: 'success', no_data: 'warning' };
</script>

<template>
    <div>
        <ProjectHeader :overview="data.overview" :title="t('Monitoring')" :description="t('Rules watch your telemetry, such as error rates, slow requests or a metric, and open an incident when a threshold is crossed.')">
            <template v-if="data.canManage" #actions>
                <AcmeBtn variant="primary" :to="`/projects/${project.id}/monitoring/rules/create`" icon="plus">{{ t('New rule') }}</AcmeBtn>
            </template>
        </ProjectHeader>
        <AlertsTabs :project-id="project.id" current="rules" class="mb-6" />
        <div class="space-y-6">
            <SectionNav section="alerts" :project-id="project.id" />
            <EmptyState v-if="data.rules.length === 0" icon="bell" :title="t('No alert rules yet')" :description="t('Add a rule to be told when errors rise, requests slow down or a metric crosses a line.')">
                <template #action><AcmeBtn v-if="data.canManage" variant="primary" :to="`/projects/${project.id}/monitoring/rules/create`">{{ t('New rule') }}</AcmeBtn></template>
            </EmptyState>
            <AcmeCard v-else :padded="false">
                <ul class="divide-y divide-line" :aria-label="t('Alert rules')">
                    <li v-for="rule in data.rules" :key="rule.id">
                        <NuxtLink :to="`/projects/${project.id}/monitoring/rules/${rule.id}`" class="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-4 hover:bg-black/[.02] sm:px-6 dark:hover:bg-white/[.03]">
                            <AcmeBadge :tone="acmeTone(tones[rule.state] ?? 'neutral')" dot>{{ rule.stateLabel }}</AcmeBadge>
                            <span class="min-w-0 flex-1">
                                <b class="block font-medium text-ink">{{ rule.name }}</b>
                                <span class="block text-xs text-muted">{{ rule.condition }} · {{ tc('over :count minute|over :count minutes', rule.windowMinutes) }} · {{ rule.environment }}</span>
                            </span>
                            <AcmeIcon name="chevronRight" :size="16" class="text-muted" />
                        </NuxtLink>
                    </li>
                </ul>
            </AcmeCard>
        </div>
    </div>
</template>
