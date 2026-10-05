<script setup lang="ts">
import type { AlertDestinationOptions } from '~/types/monitoring';
import type { ProjectOverview } from '~/types/projects';

/** Where the account's alerts go: email, push, texts and calls, webhooks, Slack, Teams, Discord or PagerDuty. */
definePageMeta({ layout: 'app', service: 'monitoring' });
type Destination = { id: number; name: string; type: string; target: string; monitors: number; enabled: boolean };
const { t, tc } = useT();
const route = useRoute();
const { data } = await useApi<{ overview: ProjectOverview; accountName: string; destinations: Destination[]; options: AlertDestinationOptions | null; canManage: boolean }>(() => `/projects/${route.params.project}/monitoring/alerts`);
const project = computed(() => data.value.overview.project);
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Alert destinations')" :description="t('Where incident alerts go. Destinations belong to :account and any project’s monitors can use them.', { account: data.accountName })">
            <template v-if="data.canManage" #actions>
                <AcmeBtn variant="primary" :to="{ query: { dialog: 'add-destination' } }" icon="plus">{{ t('Add a destination') }}</AcmeBtn>
            </template>
        </ProjectHeader>
        <SectionNav section="alerts" :project-id="project.id" />

        <EmptyState v-if="data.destinations.length === 0" icon="share" :title="t('No alert destinations yet')" :description="t('Send alerts to a member’s email, a signed webhook, Slack, Microsoft Teams, Discord or PagerDuty.')" />
        <section v-else class="ui-card overflow-hidden">
            <ul class="divide-y divide-line" :aria-label="t('Alert destinations')">
                <li v-for="destination in data.destinations" :key="destination.id" class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                    <div class="min-w-0">
                        <NuxtLink :to="`/projects/${project.id}/monitoring/alerts/${destination.id}`" class="font-semibold text-ink hover:underline">{{ destination.name }}</NuxtLink>
                        <p class="mt-0.5 break-all text-xs text-muted">{{ destination.type }} · {{ destination.target }} · {{ tc(':count monitor|:count monitors', destination.monitors, { count: destination.monitors }) }}</p>
                    </div>
                    <AcmeBadge :tone="acmeTone(destination.enabled ? 'success' : 'neutral')">{{ destination.enabled ? t('On') : t('Off') }}</AcmeBadge>
                </li>
            </ul>
        </section>

        <FormDialog
            v-if="data.canManage && data.options"
            id="add-destination"
            :title="t('Add a destination')"
            :description="t('Account admins manage destinations. Pick which monitors use one on each monitor’s settings.')"
            :action="`/api/app/projects/${project.id}/monitoring/alerts`"
            :submit="t('Add destination')"
        >
            <DestinationFields :options="data.options" />
        </FormDialog>
    </div>
</template>
