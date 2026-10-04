<script setup lang="ts">
import type { AuditDetail, Goal, RunSummary } from '~/types/audit';

/** One audit: its runs, what it checks, its competitors and schedule; edit it (the wizard), run it now, or delete it. */
definePageMeta({ layout: 'app', service: 'audit' });
const { t, dateTime } = useT();
const route = useRoute();
const { data } = await useApi<{ audit: AuditDetail; goals: Goal[] }>(() => `/projects/${route.params.project}/audit/${route.params.siteAudit}`);
const projectId = computed(() => String(route.params.project));
const base = computed(() => `/projects/${projectId.value}/audit`);
const audit = computed(() => data.value.audit);
const schedules = computed<Record<string, string>>(() => ({ none: t('Only when I run it'), monthly: t('Every month'), weekly: t('Every week') }));
const starting = ref(false);
const runError = ref<string | null>(null);

/** Run the audit now and go to the run, which shows its progress. */
async function run() {
    starting.value = true;
    runError.value = null;
    try {
        const result = await send<{ run: RunSummary }>('POST', `${base.value}/${audit.value.id}/runs`);
        await navigateTo(`${base.value}/runs/${result.run.id}`);
    } catch (problem) {
        runError.value = problem instanceof ValidationError ? (problem.first('audit') ?? problem.message) : t('The audit couldn’t start. Try again.');
        starting.value = false;
    }
}
useHead({ title: () => audit.value.name });
</script>

<template>
    <div class="space-y-6">
        <PageHeader icon="search" :title="audit.name" :description="audit.url" :breadcrumbs="[{ label: t('Audits'), to: base }]">
            <template v-if="audit.plan.canManage" #actions>
                <UiButton :to="{ query: { dialog: 'edit-audit' } }">{{ t('Edit') }}</UiButton>
                <UiButton variant="primary" :disabled="starting" :aria-busy="starting || undefined" @click="run"><Icon name="refresh" class="h-4 w-4" />{{ starting ? t('Starting…') : t('Run audit') }}</UiButton>
            </template>
        </PageHeader>
        <Alert v-if="runError" tone="danger" role="alert">{{ runError }}</Alert>

        <div class="grid gap-6 lg:grid-cols-[1fr_20rem]">
            <section aria-labelledby="runs-heading" class="grid content-start gap-3">
                <h2 id="runs-heading" class="text-lg font-extrabold text-ink">{{ t('Reports') }}</h2>
                <EmptyState v-if="audit.runs.length === 0" icon="clock" :title="t('Not run yet')" :description="t('Run the audit to get its first report.')" />
                <ul v-else class="grid gap-2">
                    <li v-for="item in audit.runs" :key="item.id">
                        <NuxtLink :to="`${base}/runs/${item.id}`" class="ui-card ui-card--interactive flex flex-wrap items-center justify-between gap-3 p-4">
                            <span class="grid">
                                <span class="text-sm font-bold text-ink">{{ dateTime(item.createdAt) }}</span>
                                <span class="text-xs text-muted">{{ item.trigger === 'scheduled' ? t('Scheduled') : t('Run by hand') }}<template v-if="item.error"> · {{ item.error }}</template></span>
                            </span>
                            <RunBadge :run="item" />
                        </NuxtLink>
                    </li>
                </ul>
            </section>
            <aside class="grid content-start gap-4">
                <section class="ui-card grid gap-3 p-5">
                    <h2 class="text-sm font-extrabold text-ink">{{ t('Tasks') }}</h2>
                    <ul class="grid gap-1.5 text-sm text-muted"><li v-for="journey in audit.journeys" :key="journey.key + journey.goal">{{ journey.label }}</li></ul>
                </section>
                <section class="ui-card grid gap-3 p-5">
                    <h2 class="text-sm font-extrabold text-ink">{{ t('Competitors') }}</h2>
                    <p v-if="audit.competitors.length === 0" class="text-sm text-muted">{{ t('None yet.') }}</p>
                    <ul v-else class="grid gap-2 text-sm">
                        <li v-for="competitor in audit.competitors" :key="competitor.url" class="min-w-0">
                            <p class="truncate font-semibold text-ink">{{ competitor.name }}</p>
                            <p class="truncate text-xs text-muted">{{ competitor.url }}</p>
                        </li>
                    </ul>
                </section>
                <section class="ui-card grid gap-2 p-5 text-sm">
                    <h2 class="text-sm font-extrabold text-ink">{{ t('Schedule') }}</h2>
                    <p class="text-muted">{{ schedules[audit.schedule] ?? audit.schedule }}</p>
                    <p v-if="audit.nextRunAt" class="text-xs text-muted">{{ t('Next run :date', { date: dateTime(audit.nextRunAt) }) }}</p>
                </section>
                <DeleteDialog v-if="audit.plan.canManage" id="delete-audit" :title="t('Delete :name and all its reports?', { name: audit.name })" :action="`/api/app${base}/${audit.id}`" :submit-label="t('Delete audit')">
                    <template #trigger="{ open }"><div><UiButton variant="danger" size="sm" @click="open">{{ t('Delete audit') }}</UiButton></div></template>
                </DeleteDialog>
            </aside>
        </div>

        <UiDialog v-if="audit.plan.canManage" id="edit-audit" :title="t('Edit audit')" size="wide">
            <template #default="{ close }"><AuditWizard :project-id="projectId" :plan="audit.plan" :goals="data.goals" :audit="audit" @done="close" /></template>
        </UiDialog>
    </div>
</template>
