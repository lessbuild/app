<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';

/** What inspecting a server found; importing it provisions it over SSH. Nothing has changed on it yet. */
definePageMeta({ layout: 'app', service: 'infrastructure' });
const { t, number } = useT();
const route = useRoute();
type ImportReview = {
    overview: ProjectOverview;
    assessment: {
        id: number;
        ip: string | null;
        hostname: string | null;
        osVersion: string | null;
        architecture: string | null;
        memoryMb: number;
        diskFreeMb: number;
        fingerprint: string | null;
        services: string[];
        warnings: string[];
        expiresAt: string;
    };
    usable: boolean;
};
const { data } = await useApi<ImportReview>(() => `/projects/${route.params.project}/infrastructure/imports/${route.params.assessment}`);
const assessment = computed(() => data.value.assessment);
const project = computed(() => data.value.overview.project);
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Review the import')" :description="t('What we found on :ip. Nothing has been changed yet.', { ip: assessment.ip ?? '—' })" />
        <section class="ui-card grid gap-4 p-5">
            <dl class="grid gap-4 text-sm sm:grid-cols-3">
                <div><dt class="text-xs text-muted">{{ t('Hostname') }}</dt><dd class="mt-1">{{ assessment.hostname ?? '—' }}</dd></div>
                <div><dt class="text-xs text-muted">{{ t('System') }}</dt><dd class="mt-1">Ubuntu {{ assessment.osVersion ?? '?' }} · {{ assessment.architecture ?? '?' }}</dd></div>
                <div><dt class="text-xs text-muted">{{ t('Memory · free disk') }}</dt><dd class="mt-1">{{ number(assessment.memoryMb) }} MB · {{ number(assessment.diskFreeMb) }} MB</dd></div>
                <div class="sm:col-span-3"><dt class="text-xs text-muted">{{ t('Host key') }}</dt><dd class="mt-1 break-all font-mono text-xs">{{ assessment.fingerprint ?? '—' }}</dd></div>
                <div class="sm:col-span-3"><dt class="text-xs text-muted">{{ t('Services found') }}</dt><dd class="mt-1">{{ assessment.services.length === 0 ? t('None') : assessment.services.join(', ') }}</dd></div>
            </dl>
            <Alert v-for="(warning, index) in assessment.warnings" :key="index" tone="warning">{{ warning }}</Alert>
        </section>
        <template v-if="data.usable">
            <ApiForm :action="`/api/app/projects/${project.id}/infrastructure/imports/${assessment.id}/confirm`" class="flex flex-wrap gap-3">
                <SubmitButton>{{ t('Import and provision') }}</SubmitButton>
                <UiButton :to="`/projects/${project.id}/infrastructure/servers`" variant="quiet">{{ t('Cancel') }}</UiButton>
            </ApiForm>
            <p class="text-xs text-muted"><Rich :text="t('This review expires :time.')"><template #time><RelativeTime :at="assessment.expiresAt" /></template></Rich></p>
        </template>
        <Alert v-else tone="info">{{ t('This review expired or was already used. Inspect the server again.') }}</Alert>
    </div>
</template>
