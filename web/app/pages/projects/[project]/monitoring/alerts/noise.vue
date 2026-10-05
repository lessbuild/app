<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';

/** The alerts that fired most in the last 30 days, how often they flapped, and how often anyone acted on them. */
definePageMeta({ layout: 'app', service: 'monitoring', tab: 'monitoring/rules' });
type NoiseRow = { kind: string; id: number; name: string; fired: number; flapped: number; unacknowledged: number; unacknowledged_share: number; notifications: number; median_minutes: number | null; suggestion: string | null };
const { t, number } = useT();
const labels = useDeployLabels();
const route = useRoute();
const { data } = await useApi<{ overview: ProjectOverview; rows: NoiseRow[]; flapMinutes: number }>(() => `/projects/${route.params.project}/monitoring/alerts/noise`);
const project = computed(() => data.value.overview.project);
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Alert noise')" :description="t('The alerts that fired most in the last 30 days, how often they flapped, and how often anyone acted on them.')" />
        <AlertsTabs :project-id="project.id" current="noise" />
        <SectionNav section="alerts" :project-id="project.id" />
        <EmptyState v-if="data.rows.length === 0" icon="clock" :title="t('No alerts in the last 30 days')" :description="t('Quiet is good. Rules and monitors that open incidents will be ranked here.')" />
        <DataTable v-else :caption="t('Alert noise in the last 30 days')">
            <template #head>
                <tr>
                    <th scope="col">{{ t('Alert') }}</th>
                    <th scope="col" class="text-right">{{ t('Fired') }}</th>
                    <th scope="col" class="text-right"><abbr :title="t('Recovered within :minutes minutes without anyone acknowledging it', { minutes: data.flapMinutes })">{{ t('Flapped') }}</abbr></th>
                    <th scope="col" class="text-right">{{ t('Nobody acknowledged') }}</th>
                    <th scope="col" class="text-right">{{ t('Notifications') }}</th>
                    <th scope="col" class="text-right">{{ t('Median time open') }}</th>
                    <th scope="col">{{ t('Suggestion') }}</th>
                </tr>
            </template>
            <tr v-for="row in data.rows" :key="`${row.kind}-${row.id}`">
                <td>
                    <NuxtLink :to="`/projects/${project.id}/monitoring/${row.kind === 'rule' ? 'rules' : 'monitors'}/${row.id}`" class="font-bold text-primary hover:underline">{{ row.name }}</NuxtLink>
                    <span class="block text-xs text-muted">{{ row.kind === 'rule' ? t('Alert rule') : t('Monitor') }}</span>
                </td>
                <td class="text-right tabular-nums">{{ number(row.fired) }}</td>
                <td class="text-right tabular-nums">{{ number(row.flapped) }}</td>
                <td class="text-right tabular-nums">{{ number(row.unacknowledged) }} <span class="text-muted">({{ row.unacknowledged_share }}%)</span></td>
                <td class="text-right tabular-nums">{{ number(row.notifications) }}</td>
                <td class="text-right tabular-nums">{{ row.median_minutes === null ? '—' : labels.duration(row.median_minutes * 60) }}</td>
                <td class="text-sm">{{ row.suggestion ?? '—' }}</td>
            </tr>
        </DataTable>
    </div>
</template>
