<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';

/** What inspecting a server found (the Acme theme's import page, step two); importing it provisions it over SSH. */
definePageMeta({ layout: 'app', service: 'infrastructure', tab: 'infrastructure/servers' });
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
const found = computed(() => [
    { label: t('Hostname'), value: assessment.value.hostname ?? '—', mono: false },
    { label: t('System'), value: `Ubuntu ${assessment.value.osVersion ?? '?'} · ${assessment.value.architecture ?? '?'}`, mono: false },
    { label: t('Memory · free disk'), value: `${number(assessment.value.memoryMb)} MB · ${number(assessment.value.diskFreeMb)} MB`, mono: false },
    { label: t('Host key'), value: assessment.value.fingerprint ?? '—', mono: true },
]);
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Import a server')" :description="t('We connect over SSH, look around without changing anything, and show what we found before you confirm.')" />
        <AcmeStepper :steps="[t('Connect'), t('Review and import')]" :current="1" :label="t('Import progress')" class="max-w-md" />
        <AcmeCard :title="t('What we found')" :description="t('What we found on :ip. Nothing has been changed yet.', { ip: assessment.ip ?? '—' })">
            <dl class="grid gap-3 sm:grid-cols-2">
                <div v-for="item in found" :key="item.label" class="rounded-xl border border-line p-3">
                    <dt class="text-xs text-muted">{{ item.label }}</dt>
                    <dd :class="['mt-1 break-all font-medium text-ink', item.mono ? 'font-mono text-xs' : 'text-sm']">{{ item.value }}</dd>
                </div>
            </dl>
            <h3 class="section-label mt-6">{{ t('Services found') }}</h3>
            <ul v-if="assessment.services.length > 0" class="mt-2 flex flex-wrap gap-2"><li v-for="service in assessment.services" :key="service"><AcmeBadge>{{ service }}</AcmeBadge></li></ul>
            <p v-else class="mt-2 text-sm text-muted">{{ t('None') }}</p>
            <AcmeAlert v-for="(warning, index) in assessment.warnings" :key="index" tone="warning" class="mt-4">{{ warning }}</AcmeAlert>
            <template v-if="data.usable">
                <p class="mt-5 text-xs text-muted"><Rich :text="t('This review expires :time.')"><template #time><RelativeTime :at="assessment.expiresAt" /></template></Rich></p>
                <ApiForm :action="`/api/app/projects/${project.id}/infrastructure/imports/${assessment.id}/confirm`" class="mt-4 flex flex-wrap gap-2">
                    <SubmitButton>{{ t('Import and provision') }}</SubmitButton>
                    <AcmeBtn :to="`/projects/${project.id}/infrastructure/imports/create`">{{ t('Back') }}</AcmeBtn>
                </ApiForm>
            </template>
            <AcmeAlert v-else tone="info" class="mt-5">{{ t('This review expired or was already used. Inspect the server again.') }}</AcmeAlert>
        </AcmeCard>
    </div>
</template>
