<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';
import type { SiteRow } from '~/types/analytics';

/**
 * The project's Analytics sites (the Acme theme's sites page): a card each with its hostnames, whether it collects, its
 * visitors over 30 days and its last visit, and adding one (in a dialog).
 */
definePageMeta({ layout: 'app', service: 'analytics' });
const { t, number } = useT();
const route = useRoute();
const { data } = await useApi<{ overview: ProjectOverview; sites: Array<SiteRow & { visitors: number }>; timezones: string[]; canManage: boolean }>(() => `/projects/${route.params.project}/analytics/sites`);
const project = computed(() => data.value.overview.project);
/** Say whether a site collects, and why not. */
const state = (site: SiteRow) => (!site.verified ? { tone: 'amber' as const, label: t('Not verified') } : site.collecting ? { tone: 'green' as const, label: t('Collecting') } : { tone: 'gray' as const, label: t('Paused') });
</script>

<template>
    <div>
        <ProjectHeader :overview="data.overview" :title="t('Web analytics')" :description="t('Each site is a website whose visits this project records. One line of script, no cookies, no personal data.')">
            <template v-if="data.canManage" #actions>
                <AcmeBtn variant="primary" icon="plus" :to="{ query: { dialog: 'add-site' } }">{{ t('Add a site') }}</AcmeBtn>
            </template>
        </ProjectHeader>

        <EmptyState v-if="data.sites.length === 0" icon="view-grid" :title="t('Add your first site')" :description="t('You’ll get a one-line snippet to paste into your pages. No cookies, no personal data.')">
            <template #action><AcmeBtn v-if="data.canManage" variant="primary" icon="plus" :to="{ query: { dialog: 'add-site' } }">{{ t('Add a site') }}</AcmeBtn></template>
        </EmptyState>
        <div v-else class="space-y-6">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <NuxtLink v-for="site in data.sites" :key="site.id" :to="`/projects/${project.id}/analytics?site=${site.id}`" class="flex flex-col rounded-2xl border border-line bg-surface p-5 shadow-card transition hover:border-accent/40">
                    <p class="flex items-start justify-between gap-2">
                        <span class="flex min-w-0 items-center gap-3">
                            <AcmeMonogram :text="site.name.slice(0, 2)" tone="sky" />
                            <b class="truncate font-medium text-ink">{{ site.name }}</b>
                        </span>
                        <AcmeBadge :tone="state(site).tone" dot>{{ state(site).label }}</AcmeBadge>
                    </p>
                    <p class="mt-4 truncate font-mono text-xs text-muted">{{ site.domains.join(', ') }}</p>
                    <p class="mt-auto flex items-end justify-between gap-3 pt-4">
                        <span><b class="text-2xl font-semibold tabular-nums text-ink">{{ number(site.visitors) }}</b><span class="block text-xs text-muted">{{ t('visitors, 30 days') }}</span></span>
                        <span class="text-right text-xs text-muted">
                            <template v-if="!site.verified">{{ t('Waiting for verification') }}</template>
                            <Rich v-else-if="site.lastEventAt" :text="t('Last visit :time')"><template #time><RelativeTime :at="site.lastEventAt" /></template></Rich>
                            <template v-else>{{ t('No visits yet') }}</template>
                        </span>
                    </p>
                </NuxtLink>
            </div>
            <div class="flex flex-wrap gap-2 text-sm">
                <AcmeBtn v-for="site in data.sites" :key="`settings-${site.id}`" size="sm" variant="ghost" icon="config" :to="`/projects/${project.id}/analytics/sites/${site.id}`">{{ t(':site settings', { site: site.name }) }}</AcmeBtn>
            </div>
            <AcmeAlert v-if="data.sites.some((site) => !site.verified)" tone="info" :title="t('Why “Not verified”?')">
                {{ t('A hostname must be a verified domain of its project (or a subdomain of one) before the site collects. Verify domains on the project’s Domains page.') }}
            </AcmeAlert>
        </div>

        <AddSiteDialog v-if="data.canManage" :project-id="project.id" :timezones="data.timezones" />
    </div>
</template>
