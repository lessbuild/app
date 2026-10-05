<script setup lang="ts">
import type { AuditEntryView, ProjectOverview, ProjectSetup } from '~/types/projects';

/**
 * A project at a glance (the Acme theme's project overview): what's left to set up, its services, its environments and
 * what changed lately.
 */
definePageMeta({ layout: 'app' });
const { t } = useT();
const route = useRoute();
const { data } = await useApi<{ overview: ProjectOverview; setup: ProjectSetup | null; activity: AuditEntryView[]; canViewAuditLog: boolean }>(() => `/projects/${route.params.project}`);
const project = computed(() => data.value.overview.project);
const done = computed(() => data.value.setup?.steps.filter((step) => step.state === 'done').length ?? 0);
const next = computed(() => data.value.setup?.steps.find((step) => step.state !== 'done') ?? null);
const hiding = ref(false);

/** Hide the setup checklist from the overview (the guide stays a click away). */
async function hideChecklist() {
    hiding.value = true;
    await send('DELETE', `/projects/${project.value.id}/checklist`).catch(() => null);
    await refreshPage();
    hiding.value = false;
}
</script>

<template>
    <div>
        <ProjectHeader :overview="data.overview">
            <template v-if="data.overview.canManage" #actions>
                <AcmeBtn :to="`/projects/${project.id}/settings`" icon="config">{{ t('Settings') }}</AcmeBtn>
            </template>
        </ProjectHeader>
        <div class="space-y-6">
            <section v-if="data.setup && next" class="rounded-2xl border border-accent/30 bg-accent/[.04] p-5" aria-labelledby="checklist-title">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-semibold text-accent">{{ t(':done of :total done', { done, total: data.setup.steps.length }) }}</p>
                        <h2 id="checklist-title" class="mt-1 font-semibold text-ink">{{ t('Get :project going', { project: project.name }) }}</h2>
                        <p class="mt-1 text-sm text-muted">{{ t('Next: :step', { step: next.title }) }}<template v-if="next.detail"> — {{ next.detail }}</template></p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <AcmeBtn size="sm" variant="primary" :to="`/projects/${project.id}/setup`">{{ next.actionLabel ?? t('Open the setup guide') }}</AcmeBtn>
                        <AcmeBtn size="sm" variant="ghost" :loading="hiding" @click="hideChecklist">{{ t('Hide') }}</AcmeBtn>
                    </div>
                </div>
                <AcmeProgress :value="(done / data.setup.steps.length) * 100" :label="t('Setup progress')" class="mt-4" />
                <ol class="mt-4 flex flex-wrap gap-x-5 gap-y-2 text-sm">
                    <li v-for="(step, index) in data.setup.steps" :key="step.key" :class="['flex items-center gap-2', step.state === 'done' ? 'text-muted' : 'text-ink']">
                        <span :class="['grid size-5 place-items-center rounded-full text-[0.625rem] font-semibold', step.state === 'done' ? 'bg-emerald-500 text-white' : step.state === 'working' ? 'bg-accent text-accent-fg' : 'bg-black/[.06] dark:bg-white/10']"><AcmeIcon v-if="step.state === 'done'" name="check" :size="11" /><template v-else>{{ index + 1 }}</template></span>{{ step.title }}
                    </li>
                </ol>
            </section>

            <section aria-labelledby="services-title">
                <h2 id="services-title" class="section-label mb-3">{{ t('Services') }}</h2>
                <ul class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <li v-for="service in data.overview.services" :key="service.key"><ServiceTile :service="service" :project-id="project.id" /></li>
                </ul>
            </section>

            <div class="grid gap-6 xl:grid-cols-3">
                <AcmeCard :title="t('Environments')" :link="data.overview.canManage ? { label: t('Manage'), to: `/projects/${project.id}/settings#environments` } : undefined">
                    <ul class="divide-y divide-line">
                        <li v-for="environment in data.overview.environments" :key="environment.id" class="flex items-center justify-between py-2.5 text-sm first:pt-0 last:pb-0">
                            <span class="font-medium text-ink">{{ environment.name }}</span>
                            <AcmeBadge :tone="environment.kind === 'production' ? 'violet' : 'gray'">{{ environment.kindLabel }}</AcmeBadge>
                        </li>
                    </ul>
                </AcmeCard>
                <AcmeCard :title="t('Recent activity')" :link="data.canViewAuditLog ? { label: t('Full history'), to: `/account/audit-log?project=${project.id}` } : undefined" class="xl:col-span-2">
                    <ol v-if="data.activity.length > 0" class="divide-y divide-line">
                        <li v-for="entry in data.activity" :key="entry.id" class="flex items-start gap-3 py-2.5 text-sm first:pt-0 last:pb-0">
                            <AcmeAvatar :name="entry.actor" size="xs" class="mt-0.5" />
                            <span class="min-w-0 flex-1 text-ink"><span class="font-medium">{{ entry.actor }}</span> · {{ entry.description }}</span>
                            <RelativeTime class="shrink-0 text-xs text-muted" :at="entry.at" />
                        </li>
                    </ol>
                    <p v-else class="text-sm text-muted">{{ t('Nothing has happened here yet.') }}</p>
                </AcmeCard>
            </div>
        </div>
    </div>
</template>
