<script setup lang="ts">
import type { DashboardForm } from '~/types/monitoring';
import type { ProjectOverview } from '~/types/projects';

/** The account's dashboards: saved views of telemetry, incidents, monitors and SLOs across every project. */
definePageMeta({ layout: 'app', service: 'monitoring' });
type DashboardsPage = {
    overview: ProjectOverview;
    accountName: string;
    dashboards: Array<{ id: number; name: string; widgets: number; range: string; creator: string | null }>;
    limit: number | null;
    form: DashboardForm;
    canManage: boolean;
};
const { t, tc } = useT();
const route = useRoute();
const { data } = await useApi<DashboardsPage>(() => `/projects/${route.params.project}/monitoring/dashboards`);
const project = computed(() => data.value.overview.project);
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Dashboards')" :description="t('Saved views of telemetry, incidents, monitors and SLOs across every project in :account.', { account: data.accountName })">
            <template v-if="data.canManage" #actions>
                <span v-if="data.limit !== null" class="text-sm text-muted">{{ t(':used of :limit dashboards on your plan', { used: data.dashboards.length, limit: data.limit }) }}</span>
                <AcmeBtn variant="primary" :to="{ query: { dialog: 'add-dashboard' } }" icon="plus">{{ t('Add a dashboard') }}</AcmeBtn>
            </template>
        </ProjectHeader>
        <SectionNav section="metrics" :project-id="project.id" />

        <EmptyState v-if="data.dashboards.length === 0" icon="chart" :title="t('No dashboards yet')" :description="t('Bring telemetry, incidents, monitor health and SLOs together on one page your team can share.')" />
        <section v-else class="ui-card overflow-hidden">
            <ul class="divide-y divide-line" :aria-label="t('Dashboards')">
                <li v-for="dashboard in data.dashboards" :key="dashboard.id" class="px-5 py-4">
                    <NuxtLink :to="`/projects/${project.id}/monitoring/dashboards/${dashboard.id}`" class="font-semibold text-ink hover:underline">{{ dashboard.name }}</NuxtLink>
                    <p class="mt-0.5 text-xs text-muted">
                        {{ tc(':count widget|:count widgets', dashboard.widgets) }} · {{ dashboard.range }}<template v-if="dashboard.creator"> · {{ t('by :name', { name: dashboard.creator }) }}</template>
                    </p>
                </li>
            </ul>
        </section>

        <FormDialog
            v-if="data.canManage"
            id="add-dashboard"
            :title="t('Add a dashboard')"
            :description="t('Dashboards cover every project in the account.')"
            :action="`/api/app/projects/${project.id}/monitoring/dashboards`"
            :submit="t('Add dashboard')"
            size="wide"
        >
            <PlanLimitAlert billing-url="/account/billing" />
            <DashboardFields :form="data.form" prefix="new-dashboard" />
        </FormDialog>
    </div>
</template>
