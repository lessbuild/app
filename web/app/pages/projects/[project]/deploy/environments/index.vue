<script setup lang="ts">
import type { EnvironmentsPage } from '~/types/deploy';

/** How deploys run in each of the project's environments, at a glance (the Acme theme's environment cards). */
definePageMeta({ layout: 'app', service: 'deploy' });
const { t, tc, locale } = useT();
const route = useRoute();
const { data } = await useApi<EnvironmentsPage>(() => `/projects/${route.params.project}/deploy/environments`);
const project = computed(() => data.value.overview.project);
const runtimes: Record<string, string> = { php: 'PHP', node: 'Node.js', python: 'Python', docker: 'Docker' };
const strategies = computed<Record<string, string>>(() => ({ rolling: t('Rolling'), blue_green: t('Blue-green'), canary: t('Canary') }));

/**
 * Say when an environment takes deploys, such as "Mon, Tue 09:00–16:00 UTC".
 *
 * @param window Its deploy window.
 */
function windowText(window: EnvironmentsPage['environments'][number]['window']): string {
    if (window === null) {
        return t('Deploys any time');
    }
    // ISO weekday 1 is Monday; 2024-01-01 was a Monday.
    const days = window.days.map((day) => new Date(Date.UTC(2024, 0, day)).toLocaleDateString(locale.value, { weekday: 'short', timeZone: 'UTC' })).join(', ');
    return t('Deploys only :days, :from–:to :timezone', { days, from: window.start, to: window.end ?? '', timezone: window.timezone ?? '' });
}
</script>

<template>
    <div>
        <ProjectHeader :overview="data.overview" :title="t('Environments')" :description="t('How deploys run in each of the project’s environments: approvals, locks and windows, runtime, variables, workers and resources.')" />
        <ul class="grid gap-4 lg:grid-cols-2">
            <li v-for="environment in data.environments" :key="environment.id">
                <NuxtLink :to="`/projects/${project.id}/deploy/environments/${environment.id}`" class="group block h-full rounded-2xl border border-line bg-surface p-5 shadow-card transition hover:-translate-y-0.5 hover:shadow-lift">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-lg font-semibold text-ink group-hover:underline">{{ environment.name }}</h2>
                        <AcmeBadge v-if="environment.protected" tone="violet" dot>{{ t('Protected') }}</AcmeBadge>
                        <AcmeBadge v-if="environment.locked" tone="red" dot>{{ t('Locked') }}</AcmeBadge>
                        <AcmeBadge v-else-if="environment.blocked" tone="amber" dot>{{ t('Outside window') }}</AcmeBadge>
                        <AcmeBadge v-if="environment.requiresApproval" tone="amber">{{ t('Needs approval') }}</AcmeBadge>
                        <span class="ml-auto text-xs text-muted"><Rich v-if="environment.lastDeployAt" :text="t('Last deploy :time')"><template #time><RelativeTime :at="environment.lastDeployAt" /></template></Rich><template v-else>{{ t('Never deployed') }}</template></span>
                    </div>
                    <p v-if="environment.lockReason" class="mt-3 rounded-lg bg-rose-500/[.06] px-3 py-2 text-sm text-rose-700 dark:text-rose-300"><AcmeIcon name="lock" :size="13" class="mr-1 inline" />{{ environment.lockReason }}</p>
                    <dl class="mt-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                        <div><dt class="text-xs text-muted">{{ t('Strategy') }}</dt><dd class="mt-0.5 font-medium text-ink">{{ strategies[environment.strategy] ?? environment.strategy }}</dd></div>
                        <div><dt class="text-xs text-muted">{{ t('Runtime') }}</dt><dd class="mt-0.5 font-medium text-ink">{{ environment.runtime ? (runtimes[environment.runtime] ?? environment.runtime) : '—' }}<template v-if="environment.runtimeVersion"> {{ environment.runtimeVersion }}</template></dd></div>
                        <div><dt class="text-xs text-muted">{{ t('Replicas') }}</dt><dd class="mt-0.5 font-medium text-ink">{{ environment.replicas.min === environment.replicas.max ? environment.replicas.min : `${environment.replicas.min}–${environment.replicas.max}` }}<template v-if="environment.replicas.autoscale"> · {{ t('auto') }}</template></dd></div>
                        <div><dt class="text-xs text-muted">{{ t('Variables · workers') }}</dt><dd class="mt-0.5 font-medium text-ink">{{ environment.variables }} · {{ environment.processes }}</dd></div>
                    </dl>
                    <p class="mt-4 flex items-center gap-2 border-t border-line pt-3 text-xs text-muted">
                        <AcmeIcon name="clock" :size="13" />{{ windowText(environment.window) }}
                        <span class="ml-auto">{{ tc(':count region|:count regions', environment.regions) }} · {{ tc(':count resource|:count resources', environment.resources, { count: environment.resources }) }}</span>
                    </p>
                </NuxtLink>
            </li>
        </ul>
    </div>
</template>
