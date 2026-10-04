<script setup lang="ts">
import type { StatusPageForm } from '~/types/monitoring';
import type { ProjectOverview } from '~/types/projects';

/** Change a status page's name, address, publishing and components. */
definePageMeta({ layout: 'app', service: 'monitoring' });
const { t } = useT();
const route = useRoute();
const { data } = await useApi<{ overview: ProjectOverview; form: StatusPageForm }>(() => `/projects/${route.params.project}/monitoring/status-pages/${route.params.page}/edit`);
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Edit :page', { page: data.form.page?.name ?? '' })" />
        <section class="ui-card p-4 sm:p-6">
            <ApiForm :action="`/api/app/projects/${data.overview.project.id}/monitoring/status-pages/${route.params.page}`" method="PUT" class="grid gap-6">
                <StatusPageFields :form="data.form" prefix="page" />
                <div class="flex justify-end"><SubmitButton>{{ t('Save') }}</SubmitButton></div>
            </ApiForm>
        </section>
    </div>
</template>
