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
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Alert rules')" :description="t('Rules watch your telemetry, such as error rates, slow requests or a metric, and open an incident when a threshold is crossed.')">
            <template v-if="data.canManage" #actions>
                <AcmeBtn variant="primary" :to="`/projects/${project.id}/monitoring/rules/create`" icon="plus">{{ t('Add a rule') }}</AcmeBtn>
            </template>
        </ProjectHeader>
        <SectionNav section="alerts" :project-id="project.id" />
        <EmptyState v-if="data.rules.length === 0" icon="bell" :title="t('No alert rules yet')" :description="t('Add a rule to be told when errors rise, requests slow down or a metric crosses a line.')">
            <template #action><AcmeBtn v-if="data.canManage" variant="primary" :to="`/projects/${project.id}/monitoring/rules/create`">{{ t('Add a rule') }}</AcmeBtn></template>
        </EmptyState>
        <section v-else class="ui-card overflow-hidden">
            <ul class="divide-y divide-line" :aria-label="t('Alert rules')">
                <li v-for="rule in data.rules" :key="rule.id" class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                    <div class="min-w-0">
                        <NuxtLink :to="`/projects/${project.id}/monitoring/rules/${rule.id}`" class="font-semibold text-ink hover:underline">{{ rule.name }}</NuxtLink>
                        <p class="mt-0.5 text-xs text-muted">{{ rule.condition }} · {{ tc('over :count minute|over :count minutes', rule.windowMinutes) }} · {{ rule.environment }}</p>
                    </div>
                    <AcmeBadge :tone="acmeTone(tones[rule.state] ?? 'neutral')">{{ rule.stateLabel }}</AcmeBadge>
                </li>
            </ul>
        </section>
    </div>
</template>
