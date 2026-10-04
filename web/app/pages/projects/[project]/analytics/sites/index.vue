<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';
import type { SiteRow } from '~/types/analytics';

/** The project's Analytics sites, and adding one (in a dialog). */
definePageMeta({ layout: 'app', service: 'analytics' });
const { t } = useT();
const route = useRoute();
const { data } = await useApi<{ overview: ProjectOverview; sites: SiteRow[]; timezones: string[]; canManage: boolean }>(() => `/projects/${route.params.project}/analytics/sites`);
const project = computed(() => data.value.overview.project);
/** Say whether a site collects, and why not. */
const state = (site: SiteRow) => (!site.verified ? { tone: 'warning' as const, label: t('Not verified') } : site.collecting ? { tone: 'success' as const, label: t('Collecting') } : { tone: 'warning' as const, label: t('Paused') });
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Analytics sites')" :description="t('Each site is a website whose visits this project records.')">
            <template v-if="data.canManage" #actions>
                <UiButton variant="primary" :to="{ query: { dialog: 'add-site' } }"><Icon name="plus" class="h-4 w-4" />{{ t('Add a site') }}</UiButton>
            </template>
        </ProjectHeader>

        <EmptyState v-if="data.sites.length === 0" icon="view-grid" :title="t('Add your first site')" :description="t('You’ll get a one-line snippet to paste into your pages. No cookies, no personal data.')">
            <UiButton v-if="data.canManage" variant="primary" :to="{ query: { dialog: 'add-site' } }">{{ t('Add a site') }}</UiButton>
        </EmptyState>
        <section v-else class="ui-card overflow-hidden">
            <ul class="divide-y divide-line" :aria-label="t('Sites')">
                <li v-for="site in data.sites" :key="site.id" class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                    <div class="min-w-0">
                        <NuxtLink :to="`/projects/${project.id}/analytics/sites/${site.id}`" class="font-extrabold text-ink hover:underline">{{ site.name }}</NuxtLink>
                        <p class="mt-0.5 break-all text-xs text-muted">
                            {{ site.domains.join(', ') }} ·
                            <Rich v-if="site.lastEventAt" :text="t('Last visit :time')"><template #time><RelativeTime :at="site.lastEventAt" /></template></Rich>
                            <template v-else>{{ t('No visits yet') }}</template>
                        </p>
                    </div>
                    <span class="flex items-center gap-2">
                        <Badge :tone="state(site).tone">{{ state(site).label }}</Badge>
                        <UiButton variant="quiet" size="sm" :to="`/projects/${project.id}/analytics?site=${site.id}`">{{ t('Report') }}</UiButton>
                    </span>
                </li>
            </ul>
        </section>

        <AddSiteDialog v-if="data.canManage" :project-id="project.id" :timezones="data.timezones" />
    </div>
</template>
