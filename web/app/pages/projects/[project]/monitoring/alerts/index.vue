<script setup lang="ts">
import type { AlertDestinationOptions } from '~/types/monitoring';
import type { ProjectOverview } from '~/types/projects';

/** Where the account's alerts go: email, push, texts and calls, webhooks, Slack, Teams, Discord or PagerDuty. */
definePageMeta({ layout: 'app', service: 'monitoring', tab: 'monitoring/rules', dialogs: ['add-destination'] });
type Destination = { id: number; name: string; type: string; target: string; monitors: number; enabled: boolean };
const { t, tc } = useT();
const route = useRoute();
const { data } = await useApi<{ overview: ProjectOverview; accountName: string; destinations: Destination[]; options: AlertDestinationOptions | null; canManage: boolean }>(() => `/projects/${route.params.project}/monitoring/alerts`);
const project = computed(() => data.value.overview.project);
/**
 * The icon for a kind of destination.
 *
 * @param type The destination's type, as shown.
 */
const kindIcon = (type: string) => (/mail/i.test(type) ? 'mail' : /slack|teams|discord/i.test(type) ? 'message' : /pager|sms|phone/i.test(type) ? 'phone' : /webhook/i.test(type) ? 'link' : 'bell');
</script>

<template>
    <div>
        <ProjectHeader :overview="data.overview" :title="t('Monitoring')" :description="t('Rules that open incidents, the places alerts go, and who gets woken up. Destinations belong to :account and any project’s monitors can use them.', { account: data.accountName })">
            <template v-if="data.canManage" #actions>
                <AcmeBtn variant="primary" :to="{ query: { dialog: 'add-destination' } }" icon="plus">{{ t('Add destination') }}</AcmeBtn>
            </template>
        </ProjectHeader>
        <div class="space-y-6">
            <SectionNav section="alerts" :project-id="project.id" />
            <EmptyState v-if="data.destinations.length === 0" icon="share" :title="t('No alert destinations yet')" :description="t('Send alerts to a member’s email, a signed webhook, Slack, Microsoft Teams, Discord or PagerDuty.')" />
            <ul v-else class="grid gap-3 sm:grid-cols-2" :aria-label="t('Alert destinations')">
                <li v-for="destination in data.destinations" :key="destination.id" class="flex items-start gap-3 rounded-2xl border border-line bg-surface p-4 shadow-card">
                    <AcmeIconBubble :icon="kindIcon(destination.type)" />
                    <span class="min-w-0 flex-1">
                        <NuxtLink :to="`/projects/${project.id}/monitoring/alerts/${destination.id}`" class="block font-medium text-ink hover:underline">{{ destination.name }} <span class="font-normal text-muted">· {{ destination.type }}</span></NuxtLink>
                        <span class="block truncate text-xs text-muted">{{ destination.target }}</span>
                        <span class="text-xs text-muted">{{ tc(':count monitor|:count monitors', destination.monitors, { count: destination.monitors }) }}</span>
                    </span>
                    <span class="flex flex-col items-end gap-2">
                        <AcmeBadge :tone="destination.enabled ? 'green' : 'gray'" dot>{{ destination.enabled ? t('On') : t('Off') }}</AcmeBadge>
                        <ApiForm v-if="data.canManage && destination.enabled" :action="`/api/app/projects/${project.id}/monitoring/alerts/${destination.id}/test`">
                            <SubmitButton variant="quiet" size="sm">{{ t('Send test') }}</SubmitButton>
                        </ApiForm>
                    </span>
                </li>
            </ul>
        </div>

        <AddDestinationDialog v-if="data.canManage && data.options" :project-id="project.id" :options="data.options" />
    </div>
</template>
