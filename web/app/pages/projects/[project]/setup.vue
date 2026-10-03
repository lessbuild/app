<script setup lang="ts">
import type { ProjectOverview, ProjectSetup } from '~/types/projects';

/**
 * The setup guide: one step at a time from an empty project to a deployed, monitored and measured site. While a step
 * finishes by itself (a server being set up, a deploy, the first visit), the guide checks again every 15 seconds.
 */
definePageMeta({ layout: 'app' });
const { t } = useT();
const route = useRoute();
const { data, refresh } = await useApi<{ overview: ProjectOverview; setup: ProjectSetup; canChange: boolean }>(() => `/projects/${route.params.project}/setup`);
const project = computed(() => data.value.overview.project);
const steps = computed(() => data.value.setup.steps);
const done = computed(() => steps.value.filter((step) => step.state === 'done').length);
const current = computed(() => steps.value.find((step) => step.state !== 'done') ?? null);
const position = computed(() => (current.value ? steps.value.indexOf(current.value) + 1 : steps.value.length));
const guides: Record<string, string> = { provider: 'connect-a-provider', server: 'create-a-server', website: 'add-a-website', environment: 'environments-and-variables', deploy: 'deploy-from-git', monitor: 'monitor-uptime', analytics: 'add-analytics' };
const labels = computed(() => ({ done: t('Done'), working: t('In progress'), todo: t('To do') }));
const tones = { done: 'success', working: 'info', todo: 'neutral' } as const;

let timer: number | undefined;
onMounted(() => {
    timer = window.setInterval(() => {
        if (current.value?.state === 'working') {
            refresh();
        }
    }, 15000);
});
onBeforeUnmount(() => window.clearInterval(timer));
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Setup guide')" :description="t('From an empty project to a deployed, monitored and measured site, one step at a time.')" />

        <nav :aria-label="t('Setup progress')">
            <SetupProgress v-if="steps.length > 1" :steps="steps" />
            <p class="mt-3 text-sm font-semibold text-muted">{{ t(':done of :total done', { done, total: steps.length }) }}</p>
        </nav>

        <section v-if="current === null" class="ui-panel grid gap-4 p-6 text-center sm:p-10" aria-labelledby="setup-done-heading">
            <span class="mx-auto grid size-14 place-items-center rounded-full bg-success text-white" aria-hidden="true"><Icon name="check" class="size-7" /></span>
            <h2 id="setup-done-heading" class="text-2xl font-extrabold text-ink">{{ t(':project is set up.', { project: project.name }) }}</h2>
            <p class="mx-auto max-w-xl text-muted">{{ t('It’s deployed, monitored and measuring visits. Every push can deploy from here on, and you’ll hear about it if anything goes down.') }}</p>
            <div class="flex flex-wrap justify-center gap-3">
                <UiButton :to="`/projects/${project.id}`" variant="primary">{{ t('Go to the project') }}</UiButton>
            </div>
        </section>

        <section v-else class="ui-panel grid gap-6 p-6 sm:p-8" aria-labelledby="setup-step-heading">
            <div class="flex items-start gap-4">
                <span class="grid size-12 shrink-0 place-items-center rounded-2xl bg-primary-soft text-primary" aria-hidden="true"><Icon :name="current.icon" class="size-6" /></span>
                <div class="min-w-0">
                    <p class="ui-eyebrow">{{ t('Step :number of :total', { number: position, total: steps.length }) }}</p>
                    <h2 id="setup-step-heading" class="mt-1 text-2xl font-extrabold tracking-tight text-ink">{{ current.title }}</h2>
                    <p class="mt-2 max-w-2xl leading-7 text-muted">{{ current.description }}</p>
                </div>
            </div>
            <p v-if="current.detail" :class="['flex items-center gap-2 rounded-control border px-4 py-3 text-sm font-semibold', current.state === 'working' ? 'border-primary/30 bg-primary-soft text-primary' : 'border-line bg-surface-muted text-ink']" role="status">
                <span v-if="current.state === 'working'" class="size-2 animate-pulse rounded-full bg-primary" aria-hidden="true" />
                {{ current.detail }}
            </p>
            <div class="flex flex-wrap items-center gap-3">
                <UiButton v-if="data.canChange && current.actionUrl && current.actionLabel" :to="local(current.actionUrl)" variant="primary" size="lg">
                    {{ current.actionLabel }} <Icon name="arrow-right" class="size-4" />
                </UiButton>
                <p v-else-if="!data.canChange" class="text-sm text-muted">{{ t('Someone who can change this project needs to do this step.') }}</p>
                <TextLink v-if="guides[current.key]" :to="`/help/${guides[current.key]}`" variant="muted">{{ t('How to do this') }}</TextLink>
            </div>
        </section>

        <Disclosure :title="t('See all steps')">
            <ol class="divide-y divide-line">
                <li v-for="(step, index) in steps" :key="step.key" class="flex flex-wrap items-start justify-between gap-3 py-3 first:pt-0 last:pb-0">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="mt-0.5 text-xs font-extrabold text-muted">{{ index + 1 }}</span>
                        <div class="min-w-0">
                            <p class="text-sm font-bold text-ink">{{ step.title }}</p>
                            <p v-if="step.detail" class="mt-0.5 text-xs text-muted">{{ step.detail }}</p>
                        </div>
                    </div>
                    <Badge :tone="tones[step.state]">{{ labels[step.state] }}</Badge>
                </li>
            </ol>
        </Disclosure>
    </div>
</template>
