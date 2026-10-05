<script setup lang="ts">
import type { ProjectOverview, ProjectSetup } from '~/types/projects';

/**
 * The setup guide (the Acme theme's): one step at a time from an empty project to a deployed, monitored and measured site. While a step
 * finishes by itself (a server being set up, a deploy, the first visit), the guide checks again every 15 seconds. A
 * step done in a form (a provider, a website, a variable, an Analytics site) opens it in a dialog over the guide, and
 * saving it comes back here.
 */
definePageMeta({ layout: 'app', dialogs: ['add-provider'] });
const { t } = useT();
const route = useRoute();
const { data, refresh } = await useApi<{ overview: ProjectOverview; setup: ProjectSetup; canChange: boolean }>(() => `/projects/${route.params.project}/setup`);
const project = computed(() => data.value.overview.project);
const steps = computed(() => data.value.setup.steps);
const done = computed(() => steps.value.filter((step) => step.state === 'done').length);
const current = computed(() => steps.value.find((step) => step.state !== 'done') ?? null);
const here = computed(() => `/projects/${project.value.id}/setup`);
const position = computed(() => (current.value ? steps.value.indexOf(current.value) + 1 : steps.value.length));
const guides: Record<string, string> = { provider: 'connect-a-provider', server: 'create-a-server', website: 'add-a-website', environment: 'environments-and-variables', deploy: 'deploy-from-git', monitor: 'monitor-uptime', analytics: 'add-analytics' };
const labels = computed(() => ({ done: t('Done'), working: t('In progress'), todo: t('To do') }));
const tones = { done: 'green', working: 'blue', todo: 'gray' } as const;
const currentIndex = computed(() => (current.value ? steps.value.indexOf(current.value) : -1));

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
    <div>
        <ProjectHeader :overview="data.overview" :title="t('Setup guide')" :description="t('From an empty project to a deployed, monitored and measured site, one step at a time.')" />
        <div class="space-y-6">
            <nav :aria-label="t('Setup progress')" class="overflow-x-auto overflow-y-hidden overscroll-x-contain">
                <ol class="flex min-w-max items-center gap-2">
                    <li v-for="(step, index) in steps" :key="step.key" class="flex items-center gap-2" :aria-current="index === currentIndex ? 'step' : undefined">
                        <span :class="['flex items-center gap-2 rounded-full border px-3 py-1.5 text-sm', step.state === 'done' ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' : index === currentIndex ? 'border-accent bg-accent/10 font-medium text-ink' : 'border-line text-muted']">
                            <AcmeIcon v-if="step.state === 'done'" name="check" :size="14" /><span v-else class="font-mono text-xs">{{ index + 1 }}</span>{{ step.title }}
                        </span>
                        <span v-if="index < steps.length - 1" class="h-px w-6 bg-line" aria-hidden="true" />
                    </li>
                </ol>
                <p class="mt-3 text-sm text-muted">{{ t(':done of :total done', { done, total: steps.length }) }}</p>
            </nav>

            <section v-if="current === null" class="rounded-2xl border border-line bg-surface p-10 text-center shadow-card" aria-labelledby="setup-done-heading">
                <span class="mx-auto grid size-14 place-items-center rounded-full bg-emerald-500 text-white" aria-hidden="true"><AcmeIcon name="check" :size="26" /></span>
                <h2 id="setup-done-heading" class="mt-5 text-xl font-semibold text-ink">{{ t(':project is set up.', { project: project.name }) }}</h2>
                <p class="mx-auto mt-2 max-w-md text-muted">{{ t('It’s deployed, monitored and measuring visits. Every push can deploy from here on, and you’ll hear about it if anything goes down.') }}</p>
                <AcmeBtn variant="primary" class="mt-6" :to="`/projects/${project.id}`">{{ t('Go to the project') }}</AcmeBtn>
            </section>

            <section v-else class="grid overflow-hidden rounded-2xl border border-line bg-surface shadow-card md:grid-cols-[1.4fr_1fr]" aria-labelledby="setup-step-heading">
                <div class="p-6 sm:p-8">
                    <p class="text-xs font-semibold text-muted">{{ t('Step :number of :total', { number: position, total: steps.length }) }}</p>
                    <h2 id="setup-step-heading" class="mt-2 text-2xl font-semibold tracking-tight text-ink">{{ current.title }}</h2>
                    <p class="mt-2 text-muted">{{ current.description }}</p>
                    <p v-if="current.detail" class="mt-4 flex items-start gap-2 rounded-xl bg-black/[.03] p-3 text-sm text-ink dark:bg-white/[.04]" role="status">
                        <span v-if="current.state === 'working'" class="mt-1.5 size-2 shrink-0 animate-pulse rounded-full bg-sky-500" aria-hidden="true" />{{ current.detail }}
                    </p>
                    <div class="mt-6 flex flex-wrap items-center gap-3">
                        <AcmeBtn v-if="data.canChange && current.actionUrl && current.actionLabel" variant="primary" :to="current.dialog ? { query: { ...route.query, dialog: current.dialog } } : local(current.actionUrl)">{{ current.actionLabel }}</AcmeBtn>
                        <p v-else-if="!data.canChange" class="text-sm text-muted">{{ t('Someone who can change this project needs to do this step.') }}</p>
                        <NuxtLink v-if="guides[current.key]" :to="`/help/${guides[current.key]}`" class="text-sm text-muted underline underline-offset-2 hover:text-ink">{{ t('How to do this') }}</NuxtLink>
                    </div>
                </div>
                <div class="grid place-items-center border-t border-line bg-black/[.02] p-8 md:border-l md:border-t-0 dark:bg-white/[.03]" aria-hidden="true">
                    <span class="grid size-24 place-items-center rounded-3xl bg-surface text-ink shadow-lift"><Icon :name="current.icon" class="size-10" /></span>
                </div>
            </section>

            <template v-if="data.canChange && current?.dialog">
                <AddProviderDialog v-if="current.dialog === 'add-provider'" :back="here" />
                <template v-else-if="current.dialog === 'create-website'">
                    <CreateWebsiteDialog :project-id="project.id" :back="here" />
                    <CreateServerDialog :project-id="project.id" />
                </template>
                <AddVariableDialog v-else-if="current.dialog === 'add-variable' && current.dialogFor" :project-id="project.id" :environment-id="current.dialogFor" :back="here" />
                <AddSiteDialog v-else-if="current.dialog === 'add-site'" :project-id="project.id" :back="here" />
            </template>

            <AcmeCard :title="t('All steps')" :padded="false">
                <ol class="divide-y divide-line">
                    <li v-for="(step, index) in steps" :key="step.key" class="flex flex-wrap items-center gap-3 px-5 py-4 sm:px-6">
                        <span :class="['grid size-7 shrink-0 place-items-center rounded-full text-xs font-semibold', step.state === 'done' ? 'bg-emerald-500 text-white' : 'bg-black/[.05] text-ink dark:bg-white/10']"><AcmeIcon v-if="step.state === 'done'" name="check" :size="13" /><template v-else>{{ index + 1 }}</template></span>
                        <span class="min-w-0 flex-1"><span class="block text-sm font-medium text-ink">{{ step.title }}</span><span class="text-sm text-muted">{{ step.detail ?? step.description }}</span></span>
                        <AcmeBadge :tone="tones[step.state]">{{ labels[step.state] }}</AcmeBadge>
                    </li>
                </ol>
            </AcmeCard>
        </div>
    </div>
</template>
