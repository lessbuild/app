<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';

/** Versions the project's apps report, the latest deployments, and recording a deployment by hand. */
definePageMeta({ layout: 'app', service: 'monitoring' });
type ReleasesPage = {
    overview: ProjectOverview;
    releases: Array<{ id: number; version: string; service: string; lastSeenAt: string | null }>;
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
const environments = computed(() => data.value.overview.environments.map((item) => ({ value: item.id, label: item.name })));
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Releases')" :description="t('Versions your apps report (service.version), and the deployments that shipped them.')">
            <template v-if="data.canRecord" #actions>
                <UiButton variant="primary" :to="{ query: { ...route.query, dialog: 'record-deployment' } }"><Icon name="plus" class="h-4 w-4" />{{ t('Record a deployment') }}</UiButton>
            </template>
        </ProjectHeader>
        <form class="flex flex-wrap items-end gap-3" role="search" @submit.prevent="navigateTo({ query: { q: q || undefined } })">
            <div class="min-w-56 flex-1"><InputField v-model="q" name="q" type="search" :label="t('Search')" maxlength="128" /></div>
            <UiButton type="submit">{{ t('Search') }}</UiButton>
        </form>

        <EmptyState v-if="data.releases.length === 0" icon="tasks" :title="t('No releases yet')" :description="t('Send a service.version attribute with your telemetry, or record a deployment.')" />
        <template v-else>
            <section class="ui-card overflow-hidden">
                <ul class="divide-y divide-line" :aria-label="t('Releases')">
                    <li v-for="release in data.releases" :key="release.id" class="px-5 py-4">
                        <NuxtLink :to="`/projects/${project.id}/monitoring/releases/${release.id}`" class="font-extrabold text-ink hover:underline">{{ release.version }}</NuxtLink>
                        <p class="mt-0.5 text-xs text-muted">
                            {{ release.service }}<template v-if="release.lastSeenAt"> · <Rich :text="t('last seen :time')"><template #time><RelativeTime :at="release.lastSeenAt" /></template></Rich></template>
                        </p>
                    </li>
                </ul>
            </section>
            <Pager :page="data.page" :last-page="data.lastPage" />
        </template>

        <DataTable v-if="data.deployments.length > 0" :caption="t('Latest deployments')">
            <template #head>
                <tr><th scope="col">{{ t('When') }}</th><th scope="col">{{ t('Release') }}</th><th scope="col">{{ t('Environment') }}</th><th scope="col">{{ t('By') }}</th></tr>
            </template>
            <tr v-for="deployment in data.deployments" :key="deployment.id">
                <td class="whitespace-nowrap"><NuxtLink :to="`/projects/${project.id}/monitoring/deployments/${deployment.id}`" class="text-primary hover:underline">{{ dateTime(deployment.deployedAt) }}</NuxtLink></td>
                <td>{{ deployment.version }}</td>
                <td>{{ deployment.environment }}</td>
                <td>{{ deployment.actor ?? t('Pipeline') }}</td>
            </tr>
        </DataTable>

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
