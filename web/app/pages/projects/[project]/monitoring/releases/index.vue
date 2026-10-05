<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';

/**
 * Versions the project's apps report (the Acme theme's releases page): when each went live, the issues, error rate and
 * response time that came with it and whether it made things worse than the one before; the latest deployments, and
 * recording a deployment by hand.
 */
definePageMeta({ layout: 'app', service: 'monitoring' });
type ReleasesPage = {
    overview: ProjectOverview;
    releases: Array<{ id: number; version: string; service: string; lastSeenAt: string | null; wentLiveAt: string | null; by: string | null; issues: number; errorRate: number | null; averageMs: number | null }>;
    page: number;
    lastPage: number;
    deployments: Array<{ id: number; version: string; environment: string; actor: string | null; deployedAt: string }>;
    filters: { q?: string };
    deploymentId: string;
    canRecord: boolean;
};
const { t, dateTime } = useT();
const route = useRoute();
const text = (value: unknown) => (typeof value === 'string' && value !== '' ? value : undefined);
const { data } = await useApi<ReleasesPage>(() => `/projects/${route.params.project}/monitoring/releases`, () => ({ q: text(route.query.q), page: text(route.query.page) }));
const project = computed(() => data.value.overview.project);
const q = ref(data.value.filters.q ?? '');
const environment = ref<string | null>(data.value.overview.environments[0]?.id ?? null);
type Release = ReleasesPage['releases'][number];
/**
 * Whether a release made errors worse than the release of the same service before it (the list is newest first): its
 * error rate is over 0.5% and at least half again the earlier one's.
 *
 * @param release The release.
 * @param index Its place in the list.
 */
function worse(release: Release, index: number): boolean {
    const before = data.value.releases.slice(index + 1).find((item) => item.service === release.service);
    return release.errorRate !== null && release.errorRate > 0.5 && (before?.errorRate == null || release.errorRate >= before.errorRate * 1.5);
}
const environments = computed(() => data.value.overview.environments.map((item) => ({ value: item.id, label: item.name })));
</script>

<template>
    <div>
        <ProjectHeader :overview="data.overview" :title="t('Monitoring')" :description="t('Every version that went live, with the errors and response times that came with it.')">
            <template v-if="data.canRecord" #actions>
                <AcmeBtn variant="primary" :to="{ query: { ...route.query, dialog: 'record-deployment' } }" icon="plus">{{ t('Record a deployment') }}</AcmeBtn>
            </template>
        </ProjectHeader>
        <div class="space-y-6">
        <AcmeCard :padded="false">
            <form class="flex flex-wrap items-center justify-between gap-3 px-5 pt-5 sm:px-6" role="search" @submit.prevent="navigateTo({ query: { q: q || undefined } })">
                <h2 class="font-semibold text-ink">{{ t('Releases') }}</h2>
                <AcmeSearchInput v-model="q" :label="t('Search')" :placeholder="t('Version or service')" class="w-full sm:w-64" />
            </form>
            <div v-if="data.releases.length === 0" class="p-5 sm:p-6">
                <AcmeEmptyState icon="tag" :title="t('No releases yet')" :description="t('Send a service.version attribute with your telemetry, or record a deployment.')" />
            </div>
            <div v-else class="mt-4 border-t border-line">
                <DataTable :caption="t('Releases')" :framed="false">
                    <template #head>
                        <tr>
                            <th scope="col">{{ t('Version') }}</th><th scope="col">{{ t('Went live') }}</th><th scope="col" class="text-right">{{ t('Issues') }}</th>
                            <th scope="col" class="text-right">{{ t('Error rate') }}</th><th scope="col" class="text-right">{{ t('Average') }}</th><th scope="col">{{ t('Verdict') }}</th>
                        </tr>
                    </template>
                    <tr v-for="(release, index) in data.releases" :key="release.id">
                        <td>
                            <NuxtLink :to="`/projects/${project.id}/monitoring/releases/${release.id}`" class="font-mono font-medium text-ink hover:underline">{{ release.version }}</NuxtLink>
                            <span class="block text-xs text-muted">{{ release.service }}</span>
                        </td>
                        <td class="text-muted">
                            <RelativeTime v-if="release.wentLiveAt" :at="release.wentLiveAt" /><template v-else>—</template>
                            <span v-if="release.by" class="block text-xs">{{ t('by :name', { name: release.by }) }}</span>
                        </td>
                        <td class="text-right tabular-nums">{{ release.issues }}</td>
                        <td :class="['text-right tabular-nums', worse(release, index) && 'font-medium text-rose-600 dark:text-rose-400']">{{ release.errorRate === null ? '—' : `${release.errorRate}%` }}</td>
                        <td class="text-right tabular-nums">{{ release.averageMs === null ? '—' : `${release.averageMs} ms` }}</td>
                        <td>
                            <AcmeBadge v-if="release.errorRate === null" dot>{{ t('No requests yet') }}</AcmeBadge>
                            <AcmeBadge v-else :tone="worse(release, index) ? 'red' : 'green'" dot>{{ worse(release, index) ? t('Made things worse') : t('Looks fine') }}</AcmeBadge>
                        </td>
                    </tr>
                </DataTable>
            </div>
        </AcmeCard>
        <Pager :page="data.page" :last-page="data.lastPage" />

        <AcmeCard v-if="data.deployments.length > 0" :title="t('Latest deployments')" :padded="false">
            <DataTable :caption="t('Latest deployments')" :framed="false">
                <template #head>
                    <tr><th scope="col">{{ t('When') }}</th><th scope="col">{{ t('Release') }}</th><th scope="col">{{ t('Environment') }}</th><th scope="col">{{ t('By') }}</th></tr>
                </template>
                <tr v-for="deployment in data.deployments" :key="deployment.id">
                    <td class="whitespace-nowrap"><NuxtLink :to="`/projects/${project.id}/monitoring/deployments/${deployment.id}`" class="font-medium text-ink hover:underline">{{ dateTime(deployment.deployedAt) }}</NuxtLink></td>
                    <td class="font-mono text-xs">{{ deployment.version }}</td>
                    <td class="text-muted">{{ deployment.environment }}</td>
                    <td class="text-muted">{{ deployment.actor ?? t('Pipeline') }}</td>
                </tr>
            </DataTable>
        </AcmeCard>
        </div>

        <FormDialog
            v-if="data.canRecord"
            id="record-deployment"
            :title="t('Record a deployment')"
            :description="t('Marks when a version went live so you can compare errors and latency before and after. Pipelines can call POST /api/v1/deployments instead.')"
            :action="`/api/app/projects/${project.id}/monitoring/deployments`"
            :submit="t('Record deployment')"
        >
            <input type="hidden" name="deployment_id" :value="data.deploymentId">
            <div class="grid gap-4">
                <SelectField id="deployment-environment" v-model="environment" name="environment_id" :label="t('Environment')" :options="environments" required />
                <InputField id="deployment-version" name="version" :label="t('Version')" maxlength="128" placeholder="2.4.0" required />
                <InputField id="deployment-service" name="service" :label="t('Service (optional)')" maxlength="100" />
                <InputField id="deployment-commit" name="commit_sha" :label="t('Commit (optional)')" maxlength="64" />
                <TextareaField id="deployment-note" name="note" :label="t('Note (optional)')" rows="2" maxlength="1000" />
            </div>
        </FormDialog>
    </div>
</template>
