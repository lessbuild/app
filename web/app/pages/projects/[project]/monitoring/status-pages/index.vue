<script setup lang="ts">
import type { StatusPageForm } from '~/types/monitoring';
import type { ProjectOverview } from '~/types/projects';

/**
 * The account's public status pages (the Acme theme's status pages): a card each with a preview of what it shows now,
 * its address and subscribers, and whether it's published.
 */
definePageMeta({ layout: 'app', service: 'monitoring' });
type StatusPagesPage = {
    overview: ProjectOverview;
    accountName: string;
    pages: Array<{ id: number; name: string; slug: string; domain: string | null; components: number; subscribers: number; published: boolean; preview: Array<{ name: string; health: string; uptime: number | null }>; allUp: boolean }>;
    form: StatusPageForm | null;
    canManage: boolean;
};
const { t, tc, number } = useT();
const route = useRoute();
const { data } = await useApi<StatusPagesPage>(() => `/projects/${route.params.project}/monitoring/status-pages`);
const project = computed(() => data.value.overview.project);
</script>

<template>
    <div>
        <ProjectHeader :overview="data.overview" :title="t('Monitoring')" :description="t('Public pages that show your customers how :account’s services are doing, fed by your monitors and the updates you post.', { account: data.accountName })">
            <template v-if="data.canManage" #actions>
                <AcmeBtn variant="primary" :to="{ query: { dialog: 'add-status-page' } }" icon="plus">{{ t('New status page') }}</AcmeBtn>
            </template>
        </ProjectHeader>

        <AcmeEmptyState v-if="data.pages.length === 0" icon="globe" :title="t('No status pages yet')" :description="t('Choose which monitors to show, publish the page, and post updates when something goes wrong.')" />
        <div v-else class="grid gap-4 xl:grid-cols-2">
            <NuxtLink v-for="page in data.pages" :key="page.id" :to="`/projects/${project.id}/monitoring/status-pages/${page.id}`" class="group overflow-hidden rounded-2xl border border-line bg-surface shadow-card transition hover:border-accent/40">
                <div class="hatch border-b border-line p-5">
                    <div class="rounded-xl border border-line bg-surface p-4">
                        <p class="flex items-center gap-2 text-sm font-medium text-ink">
                            <span :class="['size-2.5 rounded-full', page.allUp ? 'bg-emerald-500' : 'bg-amber-500']" aria-hidden="true" />
                            {{ page.preview.length === 0 ? t('No components yet') : page.allUp ? t('All systems working') : t('Some systems need attention') }}
                        </p>
                        <ul v-if="page.preview.length > 0" class="mt-3 space-y-1.5 text-xs">
                            <li v-for="component in page.preview" :key="component.name" class="flex justify-between gap-3">
                                <span class="truncate text-ink">{{ component.name }}</span>
                                <span class="text-muted">{{ component.uptime === null ? component.health : `${number(component.uptime)}%` }}</span>
                            </li>
                        </ul>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-3 p-5">
                    <span class="min-w-0 flex-1">
                        <b class="block font-medium text-ink group-hover:underline">{{ page.name }}</b>
                        <span class="text-xs text-muted">{{ page.domain ?? `/status/${page.slug}` }} · {{ tc(':count component|:count components', page.components) }} · {{ tc(':count subscriber|:count subscribers', page.subscribers) }}</span>
                    </span>
                    <AcmeBadge :tone="page.published ? 'green' : 'gray'" dot>{{ page.published ? t('Published') : t('Draft') }}</AcmeBadge>
                </div>
            </NuxtLink>
        </div>

        <FormDialog
            v-if="data.canManage && data.form"
            id="add-status-page"
            :title="t('Add a status page')"
            :action="`/api/app/projects/${project.id}/monitoring/status-pages`"
            :submit="t('Add status page')"
            size="large"
        >
            <PlanLimitAlert billing-url="/account/billing" />
            <StatusPageFields :form="data.form" prefix="new-page" />
        </FormDialog>
    </div>
</template>
