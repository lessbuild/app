<script setup lang="ts">
import type { StatusPageForm } from '~/types/monitoring';
import type { ProjectOverview } from '~/types/projects';

/** Add a status page (the full-page version of the dialog on the status pages list). */
definePageMeta({ layout: 'app', service: 'monitoring' });
const { t } = useT();
const route = useRoute();
const { data } = await useApi<{ overview: ProjectOverview; form: StatusPageForm }>(() => `/projects/${route.params.project}/monitoring/status-pages/create`);
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Add a status page')" :description="t('Choose which monitors to show, publish the page, and post updates when something goes wrong.')" />
        <section class="ui-card px-5 pb-5 sm:px-6 sm:pb-6">
            <ApiForm :action="`/api/app/projects/${data.overview.project.id}/monitoring/status-pages`" class="grid gap-6">
                <PlanLimitAlert billing-url="/account/billing" />
                <StatusPageFields :form="data.form" prefix="page" />
                <div class="flex justify-end"><SubmitButton>{{ t('Add status page') }}</SubmitButton></div>
            </ApiForm>
        </section>
    </div>
</template>
