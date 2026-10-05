<script setup lang="ts">
import type { RepositoriesPage } from '~/types/deploy';

/**
 * Deploy's front page (the Acme theme's repositories page): how delivery has gone lately, deploys waiting for
 * approval, each repository with its last deploy, and every recent deploy in one table filtered by status.
 */
definePageMeta({ layout: 'app', service: 'deploy' });
const { t, tc } = useT();
const route = useRoute();
const labels = useDeployLabels();
const { data } = await useApi<RepositoriesPage>(() => `/projects/${route.params.project}/deploy`);
const project = computed(() => data.value.overview.project);
const status = ref('all');
const statuses = computed(() => [
    { value: 'all', label: t('All') }, { value: 'succeeded', label: t('Live') }, { value: 'running', label: t('Deploying') },
    { value: 'awaiting_approval', label: t('Waiting for approval') }, { value: 'failed', label: t('Failed') },
]);
const rows = computed(() => data.value.recent.filter((row) => status.value === 'all' || row.status === status.value || (status.value === 'running' && row.status === 'deploying')));
const live = computed(() => data.value.repositories.filter((repository) => repository.latestBuild?.status === 'succeeded').length);
const stats = computed(() => data.value.stats);

/**
 * A signed change, such as "+6" or "−2", or nothing when there's nothing to compare.
 *
 * @param now This period's number.
 * @param before The period before's.
 * @param unit What follows the number.
 */
function change(now: number | null, before: number | null, unit = ''): string | undefined {
    if (now === null || before === null || now === before) {
        return undefined;
    }
    return `${now > before ? '+' : '−'}${Math.abs(now - before)}${unit}`;
}
</script>

<template>
    <div>
        <ProjectHeader :overview="data.overview" :title="t('Deploy')" :description="t('Git repositories that deploy to your websites. Each deploy is a new release; the previous ones stay on the server for rollbacks.')">
            <template #actions>
                <AcmeBtn icon="download" to="/api/app/account/inventory/repositories.csv" external download>{{ t('Export CSV') }}</AcmeBtn>
                <AcmeBtn v-if="data.canCreate" variant="primary" icon="plus" :to="`/projects/${project.id}/deploy/repositories/create`">{{ t('Connect repository') }}</AcmeBtn>
            </template>
        </ProjectHeader>
        <div class="space-y-6">
            <div class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
                <AcmeStat :label="t('Deploys this week')" :value="String(stats.deploysThisWeek)" :delta="change(stats.deploysThisWeek, stats.deploysLastWeek)" :down="stats.deploysThisWeek < stats.deploysLastWeek" :period="t('vs last week')" icon="rocket" tone="violet" :spark="stats.daily" />
                <AcmeStat :label="t('Success rate')" :value="stats.successRate === null ? '—' : `${stats.successRate}%`" :delta="change(stats.successRate, stats.successRateBefore, ` ${t('pts')}`)" :down="(stats.successRate ?? 0) < (stats.successRateBefore ?? 0)" :period="t('vs the 30 days before')" icon="checkCircle" tone="green" />
                <AcmeStat :label="t('Median deploy time')" :value="labels.duration(stats.medianSeconds)" :delta="change(stats.medianSeconds, stats.medianSecondsBefore, 's')" :down="(stats.medianSeconds ?? 0) > (stats.medianSecondsBefore ?? 0)" :period="t('vs the 30 days before')" icon="clock" tone="blue" />
                <AcmeStat :label="t('Rollbacks (30 days)')" :value="String(stats.rollbacks)" :delta="change(stats.rollbacks, stats.rollbacksBefore)" :down="stats.rollbacks > stats.rollbacksBefore" :period="t('vs the 30 days before')" icon="refresh" tone="gray" />
            </div>

            <section v-if="data.waiting.length > 0" class="flex flex-wrap items-center gap-4 rounded-2xl border border-amber-500/30 bg-amber-500/[.06] p-4" :aria-label="t('Waiting for approval')">
                <span class="grid size-9 place-items-center rounded-xl bg-amber-500/15 text-amber-700 dark:text-amber-300" aria-hidden="true"><AcmeIcon name="alert" :size="18" /></span>
                <p class="min-w-0 flex-1 text-sm text-ink">
                    <span class="font-semibold">{{ tc('Deploy #:id is waiting for approval.|:count deploys are waiting for approval.', data.waiting.length, { id: data.waiting[0]!.id }) }}</span>{{ ' ' }}<span v-if="data.waiting[0]!.commitMessage" class="text-muted">{{ data.waiting[0]!.repository }}: {{ data.waiting[0]!.commitMessage }}</span>
                </p>
                <AcmeBtn size="sm" variant="primary" :to="`/projects/${project.id}/deploy/builds/${data.waiting[0]!.id}`">{{ t('Review deploy') }}</AcmeBtn>
            </section>

            <AcmeEmptyCard v-if="data.repositories.length === 0" icon="branch" :title="t('No repositories yet')" :description="t('Connect a GitHub, GitLab or Bitbucket repository to deploy it to one of your websites.')" />
            <section v-else aria-labelledby="repos-title">
                <h2 id="repos-title" class="section-label mb-3">{{ tc(':count repository|:count repositories', data.repositories.length) }} · {{ tc(':count live release|:count live releases', live) }}</h2>
                <ul class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <li v-for="repository in data.repositories" :key="repository.id">
                        <NuxtLink :to="`/projects/${project.id}/deploy/repositories/${repository.id}`" class="group flex h-full flex-col rounded-2xl border border-line bg-surface p-5 shadow-card transition hover:-translate-y-0.5 hover:shadow-lift">
                            <div class="flex items-start gap-3">
                                <AcmeIconBubble icon="branch" size="md" />
                                <span class="min-w-0 flex-1"><span class="block truncate font-semibold text-ink group-hover:underline">{{ repository.name }}</span><span class="block truncate font-mono text-xs text-muted">{{ repository.url }}</span></span>
                            </div>
                            <p class="mt-4 flex flex-wrap items-center gap-1.5 text-xs text-ink"><AcmeBadge>{{ repository.branch }}</AcmeBadge><AcmeIcon name="arrowRight" :size="12" class="text-muted" /><span class="truncate">{{ repository.website }}<template v-if="repository.environment"> · {{ repository.environment }}</template></span></p>
                            <div class="mt-4 flex items-center justify-between border-t border-line pt-3 text-xs">
                                <span v-if="repository.latestBuild" class="flex min-w-0 items-center gap-2"><BuildStatusBadge :status="repository.latestBuild.status" /><span class="truncate text-muted">#{{ repository.latestBuild.id }}<template v-if="repository.latestBuild.createdAt"> · <RelativeTime :at="repository.latestBuild.createdAt" /></template></span></span>
                                <span v-else class="text-muted">{{ t('Never deployed') }}</span>
                                <span class="flex gap-1.5 text-muted">
                                    <span v-if="repository.pushDeploys" :title="t('Pushes deploy')"><AcmeIcon name="zap" :size="14" /><span class="sr-only">{{ t('Pushes deploy') }}</span></span>
                                    <span v-if="repository.previews" :title="t('Previews on')"><AcmeIcon name="eye" :size="14" /><span class="sr-only">{{ t('Previews on') }}</span></span>
                                </span>
                            </div>
                        </NuxtLink>
                    </li>
                </ul>
            </section>

            <AcmeCard v-if="data.recent.length > 0" :title="t('Recent deploys')" :padded="false">
                <template #action><SelectField id="deploy-status" v-model="status" name="status" :label="t('Status')" :options="statuses" class="w-44 [&_label]:sr-only" /></template>
                <DataTable :caption="t('Recent deploys')" :framed="false">
                    <template #head><tr><th scope="col">{{ t('Deploy') }}</th><th scope="col">{{ t('Repository') }}</th><th scope="col">{{ t('Environment') }}</th><th scope="col">{{ t('Started by') }}</th><th scope="col">{{ t('Took') }}</th><th scope="col">{{ t('Status') }}</th></tr></template>
                    <tr v-for="row in rows" :key="row.id">
                        <td>
                            <NuxtLink :to="`/projects/${project.id}/deploy/builds/${row.id}`" class="block hover:underline">
                                <span class="font-medium text-ink">#{{ row.id }}<template v-if="row.commitMessage"> · {{ row.commitMessage }}</template></span>
                                <span class="block font-mono text-xs text-muted">{{ row.revision ?? '—' }}<template v-if="row.createdAt"> · <RelativeTime :at="row.createdAt" /></template></span>
                            </NuxtLink>
                        </td>
                        <td>{{ row.repository }}</td>
                        <td><AcmeBadge v-if="row.environment" :tone="row.production ? 'violet' : 'gray'">{{ row.environment }}</AcmeBadge></td>
                        <td><span class="flex items-center gap-2"><AcmeAvatar v-if="row.requester" :name="row.requester" size="xs" />{{ labels.trigger(row.trigger) }}</span></td>
                        <td class="tabular-nums">{{ labels.duration(row.seconds) }}</td>
                        <td><BuildStatusBadge :status="row.status" /></td>
                    </tr>
                    <tr v-if="rows.length === 0"><td colspan="6" class="text-center text-muted">{{ t('No deploys match.') }}</td></tr>
                </DataTable>
            </AcmeCard>
        </div>
    </div>
</template>
