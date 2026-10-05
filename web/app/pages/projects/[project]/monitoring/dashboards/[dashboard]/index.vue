<script setup lang="ts">
import type { DashboardForm, DashboardWidgetData } from '~/types/monitoring';
import type { ProjectOverview } from '~/types/projects';

/** One dashboard: its widgets, refreshed every minute. */
definePageMeta({ layout: 'app', service: 'monitoring' });
type DashboardPage = {
    overview: ProjectOverview;
    dashboard: { id: number; name: string; description: string | null; range: string };
    widgets: Array<{ type: string; label: string; data: DashboardWidgetData }>;
    form: DashboardForm;
    canManage: boolean;
};
const { t } = useT();
const route = useRoute();
const { data } = await useApi<DashboardPage>(() => `/projects/${route.params.project}/monitoring/dashboards/${route.params.dashboard}`);
const project = computed(() => data.value.overview.project);
const dashboard = computed(() => data.value.dashboard);
const base = computed(() => `/api/app/projects/${project.value.id}/monitoring/dashboards/${dashboard.value.id}`);
let timer: number | undefined;

onMounted(() => (timer = window.setInterval(() => refreshNuxtData(), 60000)));
onBeforeUnmount(() => window.clearInterval(timer));
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="dashboard.name" :description="dashboard.description ? `${dashboard.description} · ${dashboard.range}` : dashboard.range">
            <template v-if="data.canManage" #actions>
                <AcmeBtn :to="`/projects/${project.id}/monitoring/dashboards/${dashboard.id}/edit`" size="sm">{{ t('Edit') }}</AcmeBtn>
                <DeleteDialog id="delete-dashboard" :title="t('Delete :dashboard?', { dashboard: dashboard.name })" :description="t('Only the dashboard goes; the data it shows stays.')" :action="base" :submit-label="t('Delete dashboard')">
                    <template #trigger="{ open }"><AcmeBtn variant="ghost" size="sm" @click="open">{{ t('Delete') }}</AcmeBtn></template>
                </DeleteDialog>
            </template>
        </ProjectHeader>
        <SectionNav section="metrics" :project-id="project.id" />
        <div class="grid gap-6 xl:grid-cols-2">
            <DashboardWidget v-for="(widget, index) in data.widgets" :key="`${widget.type}-${index}`" :type="widget.type" :label="widget.label" :data="widget.data" />
        </div>
    </div>
</template>
