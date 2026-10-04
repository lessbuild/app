<script setup lang="ts">
import type { DashboardForm } from '~/types/monitoring';
import type { ProjectOverview } from '~/types/projects';

/** Change a dashboard's name, range and widgets. */
definePageMeta({ layout: 'app', service: 'monitoring' });
const { t } = useT();
const route = useRoute();
const { data } = await useApi<{ overview: ProjectOverview; form: DashboardForm }>(() => `/projects/${route.params.project}/monitoring/dashboards/${route.params.dashboard}/edit`);
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Edit :dashboard', { dashboard: data.form.dashboard?.name ?? '' })" :description="t('Dashboards cover every project in the account.')" />
        <section class="ui-card p-4 sm:p-6">
            <ApiForm :action="`/api/app/projects/${data.overview.project.id}/monitoring/dashboards/${route.params.dashboard}`" method="PUT" class="grid gap-6">
                <DashboardFields :form="data.form" prefix="dashboard" />
                <div class="flex justify-end"><SubmitButton>{{ t('Save dashboard') }}</SubmitButton></div>
            </ApiForm>
        </section>
    </div>
</template>
