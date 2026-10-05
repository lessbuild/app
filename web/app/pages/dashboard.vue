<script setup lang="ts">
import type { Dashboard, ProjectCard } from '~/types/projects';

/**
 * The account's projects (the Acme theme's Platform projects page): what needs attention, every project with its
 * health, services, visitors and last deploy (the ones the person pinned first, archived ones on their own list), and the
 * team's recent activity. Where people land after signing in.
 */
definePageMeta({ layout: 'app' });
const { t, tc } = useT();
const route = useRoute();
const { data } = await useApi<Dashboard>('/dashboard', () => ({ activity: typeof route.query.activity === 'string' ? route.query.activity : undefined, projects: route.query.projects === 'archived' ? 'archived' : undefined }));

/**
 * Pin a project to the top of the list, or unpin it.
 *
 * @param project The project.
 */
async function pin(project: ProjectCard) {
    const result = await send<{ message: string }>('PUT', `/projects/${project.id}/pin`, { pinned: !project.pinned }).catch(() => null);
    if (result) {
        flash(result.message);
        await refreshPage();
    }
}

/**
 * Put an archived project back on the list.
 *
 * @param project The project.
 */
async function restore(project: ProjectCard) {
    const result = await send<{ message: string }>('PUT', `/projects/${project.id}/archive`, { archived: false }).catch(() => null);
    if (result) {
        flash(result.message);
        await refreshPage();
    }
}
const verified = computed(() => route.query.verified === '1');
const healthTone = { healthy: 'green', degraded: 'red', setting_up: 'amber' } as const;
</script>

<template>
    <div>
        <PlatformHeader :title="t('Projects')" :subtitle="t('Each project groups the environments, domains and services of one app or site.')">
            <template v-if="data.canCreate && data.projects.length > 0" #actions>
                <AcmeBtn to="/projects/templates" icon="layers">{{ t('From a template') }}</AcmeBtn>
                <AcmeBtn variant="primary" icon="plus" to="/projects/create">{{ t('New project') }}</AcmeBtn>
            </template>
        </PlatformHeader>
        <div class="space-y-6">
            <AcmeAlert v-if="verified" tone="success">{{ t('Your email address is verified. Welcome!') }}</AcmeAlert>

            <AcmeEmptyCard v-if="data.account === null" icon="workspace" :title="t('You’re not in an account')" :description="t('Ask someone to invite you, or create an account.')" />
            <EmptyState
                v-else-if="data.projects.length === 0 && !data.showingArchived"
                icon="layers"
                :title="t('Create your first project')"
                :description="data.canCreate
                    ? t('A project is one app or site. You’ll add domains and turn on Deploy, Monitoring, Analytics or Infrastructure next.')
                    : t('No projects yet. Someone who manages projects in :account can create one.', { account: data.account.name })"
            >
                <template v-if="data.canCreate" #action>
                    <AcmeBtn variant="primary" to="/projects/create">{{ t('Create a project') }}</AcmeBtn>
                    <AcmeBtn to="/projects/templates">{{ t('Start from a template') }}</AcmeBtn>
                    <SampleProjectButton />
                </template>
            </EmptyState>
            <template v-else>
                <section v-if="data.attention.length > 0" aria-labelledby="attention-title">
                    <h2 id="attention-title" class="section-label mb-3">{{ t('Needs attention') }}</h2>
                    <ul class="grid gap-3 md:grid-cols-2">
                        <li v-for="item in data.attention" :key="item.url" class="flex items-start gap-3 rounded-2xl border border-line bg-surface p-4 shadow-card">
                            <span :class="['grid size-9 shrink-0 place-items-center rounded-xl', item.tone === 'red' ? 'bg-rose-500/10 text-rose-600' : 'bg-amber-500/10 text-amber-600']"><AcmeIcon :name="item.icon" :size="18" /></span>
                            <span class="min-w-0 flex-1"><span class="block text-sm font-semibold text-ink">{{ item.title }}</span><span class="text-sm text-muted">{{ item.text }}</span></span>
                            <AcmeBtn size="sm" :to="local(item.url)">{{ item.action }}</AcmeBtn>
                        </li>
                    </ul>
                </section>

                <section aria-labelledby="projects-title">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <h2 id="projects-title" class="section-label">{{ data.showingArchived ? tc(':count archived project|:count archived projects', data.projects.length) : tc(':count project|:count projects', data.projects.length) }}</h2>
                        <NuxtLink v-if="data.showingArchived" :to="{ query: { ...route.query, projects: undefined } }" class="text-sm font-medium text-muted hover:text-ink">{{ t('Back to projects') }}</NuxtLink>
                        <NuxtLink v-else-if="data.archivedCount > 0" :to="{ query: { ...route.query, projects: 'archived' } }" class="text-sm font-medium text-muted hover:text-ink">{{ t('Archived (:count)', { count: data.archivedCount }) }}</NuxtLink>
                    </div>
                    <p v-if="data.showingArchived && data.projects.length === 0" class="rounded-2xl border border-dashed border-line p-6 text-sm text-muted">{{ t('No archived projects.') }}</p>
                    <ul class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <li v-for="project in data.projects" :key="project.id" class="relative">
                            <button v-if="!data.showingArchived" type="button" :class="['absolute right-3 top-3 z-10 grid size-8 place-items-center rounded-lg transition hover:bg-black/[.05] dark:hover:bg-white/[.08]', project.pinned ? 'text-amber-500' : 'text-muted']" :aria-pressed="project.pinned" :aria-label="project.pinned ? t('Unpin :project', { project: project.name }) : t('Pin :project to the top', { project: project.name })" @click="pin(project)"><AcmeIcon name="star" :size="16" :class="project.pinned && '[&_path]:fill-current'" /></button>
                            <AcmeBtn v-else-if="data.canCreate" size="sm" class="absolute right-3 top-3 z-10" icon="refresh" @click="restore(project)">{{ t('Restore') }}</AcmeBtn>
                            <NuxtLink :to="`/projects/${project.id}`" class="group flex h-full flex-col rounded-2xl border border-line bg-surface p-5 shadow-card transition hover:-translate-y-0.5 hover:shadow-lift">
                                <div class="flex items-start justify-between gap-3 pr-9">
                                    <div class="min-w-0"><p class="font-semibold text-ink group-hover:underline">{{ project.name }}</p><p v-if="project.description" class="mt-0.5 line-clamp-2 text-sm text-muted">{{ project.description }}</p></div>
                                    <AcmeBadge :tone="healthTone[project.health]" dot>{{ project.healthLabel }}</AcmeBadge>
                                </div>
                                <div class="mt-4 flex items-end justify-between gap-3">
                                    <span class="flex -space-x-1">
                                        <span v-for="(key, index) in project.serviceKeys" :key="key" :class="['grid size-7 place-items-center rounded-lg text-white ring-2 ring-surface', serviceStyle(key).tone]" :title="project.serviceNames[index]"><AcmeIcon :name="serviceStyle(key).icon" :size="14" /><span class="sr-only">{{ project.serviceNames[index] }}</span></span>
                                        <span v-if="project.serviceKeys.length === 0" class="text-xs text-muted">{{ t('No services yet') }}</span>
                                    </span>
                                    <AcmeSparkline v-if="project.visitors.length > 0" :values="project.visitors" :label="t(':project visitors, last 10 days', { project: project.name })" area class="h-9 w-24" />
                                </div>
                                <p class="mt-4 flex items-center justify-between gap-3 border-t border-line pt-3 text-xs text-muted">
                                    <span>{{ tc(':count environment|:count environments', project.environmentCount) }}</span>
                                    <span v-if="project.lastDeployAt"><Rich :text="t('Last deploy :time')"><template #time><RelativeTime :at="project.lastDeployAt" /></template></Rich></span>
                                </p>
                            </NuxtLink>
                        </li>
                        <li v-if="data.canCreate && !data.showingArchived">
                            <NuxtLink  to="/projects/create" class="grid h-full min-h-44 place-items-center rounded-2xl border border-dashed border-line p-5 text-center text-sm text-muted transition hover:border-accent hover:text-ink">
                                <span><AcmeIcon name="plus" :size="20" class="mx-auto" /><span class="mt-2 block font-medium">{{ t('New project') }}</span><span class="text-xs">{{ t('One per app or site') }}</span></span>
                            </NuxtLink>
                        </li>
                    </ul>
                </section>

                <ActivityFeed :initial="data.activity" :kind="data.activityKind" :kinds="data.activityKinds" />
            </template>
        </div>
    </div>
</template>
