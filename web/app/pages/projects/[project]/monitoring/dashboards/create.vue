<script setup lang="ts">
import type { DashboardForm } from '~/types/monitoring';
import type { ProjectOverview } from '~/types/projects';

/** Add a dashboard (the full-page version of the dialog on the dashboards list). */
definePageMeta({ layout: 'app', service: 'monitoring', tab: 'monitoring/metrics' });
const { t } = useT();
const route = useRoute();
const { data } = await useApi<{ overview: ProjectOverview; form: DashboardForm }>(() => `/projects/${route.params.project}/monitoring/dashboards/create`);
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Add a dashboard')" :description="t('Dashboards cover every project in the account.')" />
        <section class="ui-card px-5 pb-5 sm:px-6 sm:pb-6">
            <ApiForm :action="`/api/app/projects/${data.overview.project.id}/monitoring/dashboards`" class="grid gap-6">
                <PlanLimitAlert billing-url="/account/billing" />
                <DashboardFields :form="data.form" prefix="dashboard" />
                <div class="flex justify-end"><SubmitButton>{{ t('Add dashboard') }}</SubmitButton></div>
            </ApiForm>
        </section>
    </div>
</template>
