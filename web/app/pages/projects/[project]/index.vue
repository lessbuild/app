<script setup lang="ts">
import type { AuditEntryView, ProjectOverview, ProjectSetup } from '~/types/projects';

/** A project at a glance: what's left to set up, its services, its environments and what changed lately. */
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
    <div class="space-y-8">
        <ProjectHeader :overview="data.overview">
            <template v-if="data.overview.canManage" #actions>
                <UiButton :to="`/projects/${project.id}/settings`"><Icon name="settings" class="h-4 w-4" />{{ t('Settings') }}</UiButton>
            </template>
        </ProjectHeader>

        <section v-if="data.setup && next" class="ui-panel space-y-4 p-5 sm:p-6" aria-labelledby="checklist-heading">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="ui-eyebrow">{{ t(':done of :total done', { done, total: data.setup.steps.length }) }}</p>
                    <h2 id="checklist-heading" class="mt-1 text-lg font-extrabold text-ink">{{ t('Get :project going', { project: project.name }) }}</h2>
                    <p class="mt-1 text-sm text-muted">{{ t('Next: :step', { step: next.title }) }}<template v-if="next.detail"> — {{ next.detail }}</template></p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <UiButton :to="`/projects/${project.id}/setup`" variant="primary" size="sm">{{ t('Open the setup guide') }}</UiButton>
                    <UiButton variant="quiet" size="sm" :disabled="hiding" @click="hideChecklist">{{ t('Hide this') }}</UiButton>
                </div>
            </div>
            <ProgressBar :value="done" :max="data.setup.steps.length" :label="t('Setup progress')" />
        </section>

        <section aria-labelledby="services-heading" class="space-y-4">
            <h2 id="services-heading" class="text-lg font-extrabold text-ink">{{ t('Services') }}</h2>
            <ul class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <li v-for="service in data.overview.services" :key="service.key"><ServiceTile :service="service" :project-id="project.id" /></li>
            </ul>
        </section>

        <div class="grid items-start gap-8 lg:grid-cols-2">
            <section aria-labelledby="environments-heading" class="space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 id="environments-heading" class="text-lg font-extrabold text-ink">{{ t('Environments') }}</h2>
                    <UiButton v-if="data.overview.canManage" :to="`/projects/${project.id}/settings#environments`" variant="quiet" size="sm">{{ t('Manage environments') }}</UiButton>
                </div>
                <div class="ui-card overflow-hidden">
                    <ul class="divide-y divide-line">
                        <li v-for="environment in data.overview.environments" :key="environment.id" class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                            <span class="font-bold text-ink">{{ environment.name }}</span>
                            <Badge :tone="environment.kind === 'production' ? 'accent' : 'neutral'">{{ environment.kindLabel }}</Badge>
                        </li>
                    </ul>
                </div>
            </section>

            <section aria-labelledby="activity-heading" class="space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 id="activity-heading" class="text-lg font-extrabold text-ink">{{ t('Recent activity') }}</h2>
                    <UiButton v-if="data.canViewAuditLog" :to="`/account/audit-log?project=${project.id}`" variant="quiet" size="sm">{{ t('Full history') }}</UiButton>
                </div>
                <div class="ui-card overflow-hidden">
                    <p v-if="data.activity.length === 0" class="px-5 py-4 text-sm text-muted">{{ t('Nothing has happened here yet.') }}</p>
                    <ol v-else class="divide-y divide-line">
                        <li v-for="entry in data.activity" :key="entry.id" class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 px-5 py-3 text-sm">
                            <span class="min-w-0"><span class="font-bold text-ink">{{ entry.actor }}</span> <span class="text-muted">{{ entry.description.charAt(0).toLowerCase() + entry.description.slice(1) }}</span></span>
                            <RelativeTime class="shrink-0 text-xs text-muted" :at="entry.at" />
                        </li>
                    </ol>
                </div>
            </section>
        </div>
    </div>
</template>
