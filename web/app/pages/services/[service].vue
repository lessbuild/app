<script setup lang="ts">
import type { ServiceOption } from '~/types/projects';

/** A service across the account: the projects that use it (to open), and the others (to turn it on for). */
definePageMeta({ layout: 'app' });
const { t } = useT();
const route = useRoute();
type Row = { projectId: string; projectName: string; enabled: boolean; canManage: boolean };
const { data } = await useApi<{ account: { id: string; name: string }; service: ServiceOption; projects: Row[]; canCreateProject: boolean }>(() => `/services/${route.params.service}`);
const busy = ref<string | null>(null);

/** Turn the service on for a project and open it there. */
async function enable(row: Row) {
    busy.value = row.projectId;
    const result = await send<{ redirect: string; message: string }>('POST', `/projects/${row.projectId}/services/${data.value.service.key}`).catch(() => null);
    if (result) {
        flash(result.message);
        await navigateTo(local(result.redirect));
    }
    busy.value = null;
}
</script>

<template>
    <div class="space-y-6">
        <PageHeader :eyebrow="data.account.name" :title="data.service.name" :description="data.service.tagline" :icon="data.service.icon" />
        <EmptyState v-if="data.projects.length === 0" icon="layers" :title="t('No projects yet')" :description="t('Create a project, then turn on :service for it.', { service: data.service.name })">
            <template v-if="data.canCreateProject" #action>
                <UiButton variant="primary" :to="`/dashboard?dialog=new-project&services=${data.service.key}`">{{ t('Create a project') }}</UiButton>
            </template>
        </EmptyState>
        <section v-else aria-labelledby="service-projects" class="space-y-3">
            <h2 id="service-projects" class="text-lg font-extrabold text-ink">{{ t('Projects') }}</h2>
            <ul class="ui-card divide-y divide-line overflow-hidden">
                <li v-for="row in data.projects" :key="row.projectId" class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                    <span class="flex items-center gap-2 font-bold text-ink">{{ row.projectName }}<Badge v-if="row.enabled" tone="success">{{ t('On') }}</Badge></span>
                    <UiButton v-if="row.enabled" size="sm" :to="`/projects/${row.projectId}/services/${data.service.key}`">{{ t('Open') }}</UiButton>
                    <UiButton v-else-if="row.canManage" size="sm" variant="primary" :disabled="busy !== null" :aria-busy="busy === row.projectId || undefined" @click="enable(row)">{{ busy === row.projectId ? t('Working…') : t('Turn on') }}</UiButton>
                </li>
            </ul>
        </section>
    </div>
</template>
