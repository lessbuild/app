<script setup lang="ts">
import type { FunnelStep, SitePage } from '~/types/analytics';

/** A site's funnels: the steps visitors take in order and where they drop off. Adding one is a wizard. */
definePageMeta({ layout: 'app', service: 'analytics' });
type Funnel = { id: number; name: string; steps: FunnelStep[]; report: Array<{ label: string; visitors: number; ofStart: number; ofPrevious: number | null }> };
const { t, number } = useT();
const route = useRoute();
const { data } = await useApi<SitePage & { funnels: Funnel[] }>(() => `/projects/${route.params.project}/analytics/funnels`, () => ({ site: typeof route.query.site === 'string' ? route.query.site : undefined }));
const project = computed(() => data.value.overview.project);
const base = computed(() => `/api/app/projects/${project.value.id}/analytics/sites/${data.value.site?.id}/funnels`);
/** Each funnel's steps, editable in its dialog. */
const editing = reactive<Record<number, FunnelStep[]>>({});
watchEffect(() => {
    for (const funnel of data.value.funnels) {
        editing[funnel.id] = funnel.steps.map((step) => ({ ...step }));
    }
});
/**
 * The step where a funnel lost the most visitors, or null with fewer than two steps.
 *
 * @param funnel The funnel.
 */
function drop(funnel: Funnel): { from: string; to: string; lost: number } | null {
    let worst: { from: string; to: string; lost: number } | null = null;
    for (let index = 1; index < funnel.report.length; index++) {
        const [before, step] = [funnel.report[index - 1]!, funnel.report[index]!];
        if (worst === null || before.visitors - step.visitors > worst.lost) {
            worst = { from: before.label, to: step.label, lost: before.visitors - step.visitors };
        }
    }
    return worst;
}
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Web analytics')" :description="t('Steps visitors take in order, such as pricing, sign-up and first project, and where they drop off. Worked out from the last 30 days of events.')">
            <template v-if="data.canManage && data.site" #actions>
                <AcmeBtn variant="primary" :to="{ query: { ...route.query, dialog: 'new-funnel' } }" icon="plus">{{ t('Add a funnel') }}</AcmeBtn>
            </template>
        </ProjectHeader>
        <EmptyState v-if="!data.site" icon="view-grid" :title="t('Add a site first')" :description="t('Funnels belong to a site.')" />
        <template v-else>
            <SitePicker :sites="data.sites" :site="data.site" />
            <EmptyState v-if="data.funnels.length === 0" icon="filter" :title="t('No funnels yet')" :description="t('Add the steps you want visitors to take, and see how many make it through each one.')" />
            <section v-for="funnel in data.funnels" :key="funnel.id" class="grid gap-5 rounded-2xl border border-line bg-surface p-5 shadow-card sm:p-6" :aria-labelledby="`funnel-${funnel.id}`">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 :id="`funnel-${funnel.id}`" class="font-semibold text-ink">{{ funnel.name }}</h2>
                    <div v-if="data.canManage" class="flex gap-1">
                        <FormDialog :id="`edit-funnel-${funnel.id}`" :title="t('Edit funnel')" :action="`${base}/${funnel.id}`" method="PUT" :submit="t('Save')" size="wide">
                            <template #trigger="{ open }"><AcmeBtn variant="ghost" size="sm" @click="open">{{ t('Edit') }}</AcmeBtn></template>
                            <InputField :id="`funnel-${funnel.id}-name`" name="name" :label="t('Name')" :model-value="funnel.name" maxlength="120" required />
                            <FunnelSteps v-if="editing[funnel.id]" :id="`funnel-${funnel.id}`" v-model="editing[funnel.id]!" />
                        </FormDialog>
                        <DeleteDialog :id="`delete-funnel-${funnel.id}`" :title="t('Remove :funnel?', { funnel: funnel.name })" :action="`${base}/${funnel.id}`" :submit-label="t('Remove')">
                            <template #trigger="{ open }"><AcmeBtn variant="ghost" size="sm" @click="open">{{ t('Remove') }}</AcmeBtn></template>
                        </DeleteDialog>
                    </div>
                </div>
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-xl border border-line p-4"><p class="text-xs text-muted">{{ t('Started') }}</p><p class="mt-1 text-2xl font-semibold tabular-nums text-ink">{{ number(funnel.report[0]?.visitors ?? 0) }}</p></div>
                    <div class="rounded-xl border border-line p-4"><p class="text-xs text-muted">{{ t('Made it through') }}</p><p class="mt-1 text-2xl font-semibold tabular-nums text-emerald-600 dark:text-emerald-400">{{ funnel.report.at(-1)?.ofStart ?? 0 }}%</p></div>
                    <div v-if="drop(funnel)" class="rounded-xl border border-line p-4"><p class="text-xs text-muted">{{ t('Lost between :from and :to', { from: drop(funnel)!.from, to: drop(funnel)!.to }) }}</p><p class="mt-1 text-2xl font-semibold tabular-nums text-rose-600 dark:text-rose-400">{{ number(drop(funnel)!.lost) }}</p></div>
                </div>
                <ol :class="['grid gap-4', funnel.report.length > 3 ? 'sm:grid-cols-2 lg:grid-cols-4' : 'sm:grid-cols-3']">
                    <li v-for="(step, index) in funnel.report" :key="index" class="flex flex-col">
                        <p class="text-xs font-medium text-muted">{{ t('Step :number', { number: index + 1 }) }}</p>
                        <p class="truncate font-medium text-ink">{{ step.label }}</p>
                        <div class="relative mt-3 h-36 overflow-hidden rounded-xl bg-black/[.03] dark:bg-white/[.04]" role="meter" :aria-label="t(':step: :count visitors', { step: step.label, count: step.visitors })" :aria-valuenow="step.ofStart" aria-valuemin="0" aria-valuemax="100">
                            <div class="hatch absolute inset-x-0 bottom-0" :style="{ height: `${index === 0 ? 100 : funnel.report[index - 1]!.ofStart}%` }" />
                            <div class="absolute inset-x-0 bottom-0 rounded-t-lg bg-sky-500" :style="{ height: `${step.ofStart}%` }" />
                        </div>
                        <p class="mt-2 text-lg font-semibold tabular-nums text-ink">{{ number(step.visitors) }} <span class="text-sm font-normal text-muted">{{ t('visitors') }}</span></p>
                        <p v-if="step.ofPrevious !== null" :class="['text-xs', step.ofPrevious < 40 ? 'text-rose-600 dark:text-rose-400' : 'text-muted']">{{ t(':rate% carried on', { rate: step.ofPrevious }) }} · {{ t(':count left', { count: number(funnel.report[index - 1]!.visitors - step.visitors) }) }}</p>
                        <p v-else class="text-xs text-muted">{{ t('Everyone starts here') }}</p>
                    </li>
                </ol>
                <p class="text-xs text-muted">{{ t('A visitor counts at a step once they’ve done every step before it, in order. The hatched area is who dropped off.') }}</p>
            </section>
            <UiDialog v-if="data.canManage" id="new-funnel" :title="t('Add a funnel')" size="wide">
                <template #default="{ close }"><NewFunnelWizard :action="`/projects/${project.id}/analytics/sites/${data.site.id}/funnels`" @done="close" /></template>
            </UiDialog>
        </template>
    </div>
</template>
