<script setup lang="ts">
import type { SitePage } from '~/types/analytics';

/**
 * A site's goals (the Acme theme's goals page): pages and custom events that count as conversions, with each one's
 * conversions over 30 days, and how to send custom events. Adding and editing open dialogs.
 */
definePageMeta({ layout: 'app', service: 'analytics' });
type Goal = { id: number; name: string; kind: string; matchType: string; matchValue: string; active: boolean; conversions: number };
const { t, number } = useT();
const route = useRoute();
const { data } = await useApi<SitePage & { goals: Goal[] }>(() => `/projects/${route.params.project}/analytics/goals`, () => ({ site: typeof route.query.site === 'string' ? route.query.site : undefined }));
const project = computed(() => data.value.overview.project);
const base = computed(() => `/api/app/projects/${project.value.id}/analytics/sites/${data.value.site?.id}/goals`);
</script>

<template>
    <div>
        <ProjectHeader :overview="data.overview" :title="t('Web analytics')" :description="t('Pages and custom events that count as conversions. A new or changed goal counts from that moment on; earlier conversions keep the definition they were counted under.')">
            <template v-if="data.canManage && data.site" #actions>
                <AcmeBtn variant="primary" :to="{ query: { ...route.query, dialog: 'add-goal' } }" icon="plus">{{ t('Add a goal') }}</AcmeBtn>
            </template>
        </ProjectHeader>
        <EmptyState v-if="!data.site" icon="view-grid" :title="t('Add a site first')" :description="t('Goals belong to a site.')" />
        <div v-else class="space-y-6">
            <SitePicker :sites="data.sites" :site="data.site" class="w-56" />
            <AcmeCard :title="t('Goals for :site', { site: data.site.name })" :padded="false">
                <ul v-if="data.goals.length > 0" class="divide-y divide-line border-t border-line" :aria-label="t('Goals for :site', { site: data.site.name })">
                    <li v-for="goal in data.goals" :key="goal.id" class="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-4 sm:px-6">
                        <AcmeIconBubble :icon="goal.kind === 'event' ? 'zap' : 'page'" />
                        <span class="min-w-0 flex-1">
                            <b class="block font-medium text-ink">{{ goal.name }}</b>
                            <span class="text-xs text-muted">{{ goal.kind === 'event' ? t('Custom event') : t('Page') }} {{ goal.matchType === 'prefix' ? t('starts with') : t('exactly') }} <code class="font-mono">{{ goal.matchValue }}</code></span>
                        </span>
                        <span class="w-32 text-sm tabular-nums"><b class="text-ink">{{ number(goal.conversions) }}</b> <span class="text-xs text-muted">{{ t('in 30 days') }}</span></span>
                        <AcmeBadge :tone="goal.active ? 'green' : 'gray'" dot>{{ goal.active ? t('Counting') : t('Paused') }}</AcmeBadge>
                        <div v-if="data.canManage" class="flex gap-1">
                            <FormDialog :id="`edit-goal-${goal.id}`" :title="t('Edit :goal', { goal: goal.name })" :action="`${base}/${goal.id}`" method="PUT" :submit="t('Save goal')">
                                <template #trigger="{ open }"><AcmeBtn variant="ghost" size="sm" icon="edit" :label="t('Edit :goal', { goal: goal.name })" @click="open" /></template>
                                <GoalFields :id="`goal-${goal.id}`" :goal="goal" />
                            </FormDialog>
                            <DeleteDialog :id="`delete-goal-${goal.id}`" :title="t('Remove :goal?', { goal: goal.name })" :description="t('Its conversions disappear from reports.')" :action="`${base}/${goal.id}`" :submit-label="t('Remove')">
                                <template #trigger="{ open }"><AcmeBtn variant="ghost" size="sm" icon="trash" :label="t('Remove :goal?', { goal: goal.name })" @click="open" /></template>
                            </DeleteDialog>
                        </div>
                    </li>
                </ul>
                <div v-else class="p-5 sm:p-6"><AcmeEmptyState icon="target" :title="t('No goals yet')" :description="t('No goals yet. A thank-you page or a “signup” event makes a good first goal.')" /></div>
            </AcmeCard>
            <AcmeCard :title="t('Sending custom events')" :description="t('Call this from your page when something happens: a signup, a download, a purchase.')">
                <CodeBlock code="window.buildpusher.track('signup', { plan: 'pro' })" />
            </AcmeCard>
            <FormDialog v-if="data.canManage" id="add-goal" :title="t('Add a goal to :site', { site: data.site.name })" :description="t('Custom events are sent with window.buildpusher.track(\'name\').')" :action="base" :submit="t('Add goal')">
                <GoalFields id="new-goal" />
            </FormDialog>
        </div>
    </div>
</template>
