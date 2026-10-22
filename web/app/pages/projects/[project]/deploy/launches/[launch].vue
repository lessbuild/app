<script setup lang="ts">
import type { LaunchPage } from '~/types/deploy';

/**
 * One launch, followed live: its steps (the server, websites, environments, repository, deploys), and per app a
 * checklist (DNS pointing at the server, the first deploy live, answering over HTTPS) with links to what it made.
 * A launch that stopped says why and can carry on from there.
 */
definePageMeta({ layout: 'app', service: 'deploy' });
const { t } = useT();
const route = useRoute();
const { data } = await useApi<LaunchPage>(() => `/projects/${route.params.project}/deploy/launches/${route.params.launch}`);
const launch = computed(() => data.value.launch);
const base = computed(() => `/api/app/projects/${data.value.overview.project.id}/deploy/launches/${launch.value.id}`);
const stepTone = (state: string) => acmeTone(state === 'done' ? 'success' : state === 'failed' ? 'danger' : state === 'current' ? 'info' : 'neutral');
const stepLabel = (state: string) => state === 'done' ? t('Done') : state === 'failed' ? t('Stopped') : state === 'current' ? t('Working on it') : t('Next');
const checklistDone = computed(() => launch.value.apps.every((app) => app.checklist.every((item) => item.done)));
let timer: number | undefined;

// While it runs, and until DNS and HTTPS are confirmed, the page keeps itself current.
watch(() => launch.value.active || (launch.value.status === 'done' && !checklistDone.value), (following) => {
    window.clearInterval(timer);
    timer = following ? window.setInterval(() => refreshNuxtData(), 8000) : undefined;
}, { immediate: import.meta.client });
onBeforeUnmount(() => window.clearInterval(timer));
</script>

<template>
    <div>
        <ProjectHeader :overview="data.overview" :title="t('Launching :repository', { repository: launch.repository })" :description="t('Branch :branch. You can leave this page; the launch carries on.', { branch: launch.branch })">
            <template v-if="data.canRetry" #actions>
                <ApiForm :action="`${base}/retry`"><SubmitButton>{{ t('Try again') }}</SubmitButton></ApiForm>
            </template>
        </ProjectHeader>
        <div class="grid gap-6">
            <AcmeAlert v-if="launch.error" tone="danger" role="alert">{{ launch.error }}</AcmeAlert>
            <AcmeAlert v-else-if="launch.status === 'done'" tone="success">{{ checklistDone ? t('Live. Everything on the checklist is done.') : t('Set up and deployed. Finish the checklist below to go live.') }}</AcmeAlert>

            <AcmeCard :title="t('Steps')" :padded="false">
                <ol class="divide-y divide-line">
                    <li v-for="step in launch.steps" :key="step.key" class="flex items-center justify-between gap-3 px-5 py-3 text-sm sm:px-6">
                        <span class="font-medium text-ink">{{ step.label }}</span>
                        <AcmeBadge :tone="stepTone(step.state)" dot>{{ stepLabel(step.state) }}</AcmeBadge>
                    </li>
                </ol>
                <p v-if="launch.server" class="border-t border-line px-5 py-3 text-sm text-muted sm:px-6">
                    {{ t('Server') }}: <NuxtLink :to="launch.server.url" class="font-medium text-ink underline">{{ launch.server.label }}</NuxtLink><template v-if="launch.server.ip"> · {{ launch.server.ip }}</template>
                </p>
            </AcmeCard>

            <AcmeCard v-for="app in launch.apps" :key="app.domain" :title="`${app.name} · ${app.label}`" :description="app.domain">
                <ul class="grid gap-2">
                    <li v-for="item in app.checklist" :key="item.key" class="flex items-center gap-2 text-sm">
                        <AcmeIcon :name="item.done ? 'check' : 'clock'" :size="16" :class="item.done ? 'text-success' : 'text-muted'" />
                        <span :class="item.done ? 'text-ink' : 'text-muted'">{{ item.label }}</span>
                    </li>
                </ul>
                <div class="mt-4 flex flex-wrap gap-2">
                    <UiButton v-if="app.website" :to="app.website.url" size="sm">{{ t('Website') }}</UiButton>
                    <UiButton v-if="app.environment" :to="app.environment" size="sm">{{ t('Environment') }}</UiButton>
                    <UiButton v-if="app.repository" :to="app.repository" size="sm">{{ t('Repository') }}</UiButton>
                    <UiButton v-if="app.build" :to="app.build.url" size="sm">{{ t('Deploy') }}</UiButton>
                </div>
            </AcmeCard>
        </div>
    </div>
</template>
