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
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Funnels')" :description="t('Steps visitors take in order, such as pricing, sign-up and first project, and where they drop off. Worked out from the last 30 days of events.')">
            <template v-if="data.canManage && data.site" #actions>
                <UiButton variant="primary" :to="{ query: { ...route.query, dialog: 'new-funnel' } }"><Icon name="plus" class="h-4 w-4" />{{ t('Add a funnel') }}</UiButton>
            </template>
        </ProjectHeader>
        <EmptyState v-if="!data.site" icon="view-grid" :title="t('Add a site first')" :description="t('Funnels belong to a site.')" />
        <template v-else>
            <SitePicker :sites="data.sites" :site="data.site" />
            <EmptyState v-if="data.funnels.length === 0" icon="filter" :title="t('No funnels yet')" :description="t('Add the steps you want visitors to take, and see how many make it through each one.')" />
            <section v-for="funnel in data.funnels" :key="funnel.id" class="ui-card grid gap-4 p-5" :aria-labelledby="`funnel-${funnel.id}`">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 :id="`funnel-${funnel.id}`" class="text-lg font-extrabold text-ink">{{ funnel.name }}</h2>
                    <div v-if="data.canManage" class="flex gap-1">
                        <FormDialog :id="`edit-funnel-${funnel.id}`" :title="t('Edit funnel')" :action="`${base}/${funnel.id}`" method="PUT" :submit="t('Save')" size="wide">
                            <template #trigger="{ open }"><UiButton variant="quiet" size="sm" @click="open">{{ t('Edit') }}</UiButton></template>
                            <InputField :id="`funnel-${funnel.id}-name`" name="name" :label="t('Name')" :model-value="funnel.name" maxlength="120" required />
                            <FunnelSteps v-if="editing[funnel.id]" :id="`funnel-${funnel.id}`" v-model="editing[funnel.id]!" />
                        </FormDialog>
                        <DeleteDialog :id="`delete-funnel-${funnel.id}`" :title="t('Remove :funnel?', { funnel: funnel.name })" :action="`${base}/${funnel.id}`" :submit-label="t('Remove')">
                            <template #trigger="{ open }"><UiButton variant="quiet" size="sm" @click="open">{{ t('Remove') }}</UiButton></template>
                        </DeleteDialog>
                    </div>
                </div>
                <ol class="grid gap-3">
                    <li v-for="(step, index) in funnel.report" :key="index" class="grid items-center gap-x-3 gap-y-1 text-sm sm:grid-cols-[14rem_1fr_9rem]">
                        <span class="font-semibold text-ink">{{ index + 1 }}. {{ step.label }}</span>
                        <ProgressBar :value="step.visitors" :max="Math.max(1, funnel.report[0]?.visitors ?? 0)" role="meter" :label="t(':step: :count visitors', { step: step.label, count: step.visitors })" />
                        <span class="tabular-nums text-muted sm:text-right">
                            <span class="font-bold text-ink">{{ number(step.visitors) }}</span> · {{ step.ofStart }}%
                            <span v-if="step.ofPrevious !== null" class="block text-xs">{{ t(':rate% carried on', { rate: step.ofPrevious }) }}</span>
                        </span>
                    </li>
                </ol>
            </section>
            <UiDialog v-if="data.canManage" id="new-funnel" :title="t('Add a funnel')" size="wide">
                <template #default="{ close }"><NewFunnelWizard :action="`/projects/${project.id}/analytics/sites/${data.site.id}/funnels`" @done="close" /></template>
            </UiDialog>
        </template>
    </div>
</template>
