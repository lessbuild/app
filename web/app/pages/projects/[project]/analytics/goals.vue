<script setup lang="ts">
import type { SitePage } from '~/types/analytics';

/** A site's goals: pages and custom events that count as conversions. Adding and editing open dialogs. */
definePageMeta({ layout: 'app', service: 'analytics' });
type Goal = { id: number; name: string; kind: string; matchType: string; matchValue: string; active: boolean };
const { t } = useT();
const route = useRoute();
const { data } = await useApi<SitePage & { goals: Goal[] }>(() => `/projects/${route.params.project}/analytics/goals`, () => ({ site: typeof route.query.site === 'string' ? route.query.site : undefined }));
const project = computed(() => data.value.overview.project);
const base = computed(() => `/api/app/projects/${project.value.id}/analytics/sites/${data.value.site?.id}/goals`);
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Goals')" :description="t('Pages and custom events that count as conversions. A new or changed goal counts from that moment on; earlier conversions keep the definition they were counted under.')">
            <template v-if="data.canManage && data.site" #actions>
                <UiButton variant="primary" :to="{ query: { ...route.query, dialog: 'add-goal' } }"><Icon name="plus" class="h-4 w-4" />{{ t('Add a goal') }}</UiButton>
            </template>
        </ProjectHeader>
        <EmptyState v-if="!data.site" icon="view-grid" :title="t('Add a site first')" :description="t('Goals belong to a site.')" />
        <template v-else>
            <SitePicker :sites="data.sites" :site="data.site" />
            <EmptyState v-if="data.goals.length === 0" icon="chart" :title="t('No goals yet')" :description="t('No goals yet. A thank-you page or a “signup” event makes a good first goal.')" />
            <section v-else class="ui-card overflow-hidden">
                <ul class="divide-y divide-line" :aria-label="t('Goals for :site', { site: data.site.name })">
                    <li v-for="goal in data.goals" :key="goal.id" class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                        <div class="min-w-0">
                            <p class="flex flex-wrap items-center gap-2 font-extrabold text-ink">{{ goal.name }} <Badge :tone="goal.active ? 'success' : 'neutral'">{{ goal.active ? t('Counting') : t('Paused') }}</Badge></p>
                            <p class="mt-0.5 text-xs text-muted">
                                {{ goal.kind === 'event' ? t('Custom event') : t('Page') }} · {{ goal.matchType === 'prefix' ? t('starts with') : t('exactly') }}
                                <code class="rounded bg-surface-muted px-1 text-ink">{{ goal.matchValue }}</code>
                            </p>
                        </div>
                        <div v-if="data.canManage" class="flex gap-1">
                            <FormDialog :id="`edit-goal-${goal.id}`" :title="t('Edit :goal', { goal: goal.name })" :action="`${base}/${goal.id}`" method="PUT" :submit="t('Save goal')">
                                <template #trigger="{ open }"><UiButton variant="quiet" size="sm" @click="open">{{ t('Edit') }}</UiButton></template>
                                <GoalFields :id="`goal-${goal.id}`" :goal="goal" />
                            </FormDialog>
                            <DeleteDialog :id="`delete-goal-${goal.id}`" :title="t('Remove :goal?', { goal: goal.name })" :description="t('Its conversions disappear from reports.')" :action="`${base}/${goal.id}`" :submit-label="t('Remove')">
                                <template #trigger="{ open }"><UiButton variant="quiet" size="sm" @click="open">{{ t('Remove') }}</UiButton></template>
                            </DeleteDialog>
                        </div>
                    </li>
                </ul>
            </section>
            <FormDialog v-if="data.canManage" id="add-goal" :title="t('Add a goal to :site', { site: data.site.name })" :description="t('Custom events are sent with window.buildpusher.track(\'name\').')" :action="base" :submit="t('Add goal')">
                <GoalFields id="new-goal" />
            </FormDialog>
        </template>
    </div>
</template>
