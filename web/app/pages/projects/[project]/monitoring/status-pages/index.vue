<script setup lang="ts">
import type { StatusPageForm } from '~/types/monitoring';
import type { ProjectOverview } from '~/types/projects';

/** The account's public status pages. */
definePageMeta({ layout: 'app', service: 'monitoring' });
type StatusPagesPage = {
    overview: ProjectOverview;
    accountName: string;
    pages: Array<{ id: number; name: string; slug: string; components: number; subscribers: number; published: boolean }>;
    form: StatusPageForm | null;
    canManage: boolean;
};
const { t, tc } = useT();
const route = useRoute();
const { data } = await useApi<StatusPagesPage>(() => `/projects/${route.params.project}/monitoring/status-pages`);
const project = computed(() => data.value.overview.project);
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Status pages')" :description="t('Public pages that show your customers how :account’s services are doing, with the updates you post.', { account: data.accountName })">
            <template v-if="data.canManage" #actions>
                <AcmeBtn variant="primary" :to="{ query: { dialog: 'add-status-page' } }" icon="plus">{{ t('Add a status page') }}</AcmeBtn>
            </template>
        </ProjectHeader>

        <EmptyState v-if="data.pages.length === 0" icon="globe" :title="t('No status pages yet')" :description="t('Choose which monitors to show, publish the page, and post updates when something goes wrong.')" />
        <section v-else class="ui-card overflow-hidden">
            <ul class="divide-y divide-line" :aria-label="t('Status pages')">
                <li v-for="page in data.pages" :key="page.id" class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                    <div class="min-w-0">
                        <NuxtLink :to="`/projects/${project.id}/monitoring/status-pages/${page.id}`" class="font-semibold text-ink hover:underline">{{ page.name }}</NuxtLink>
                        <p class="mt-0.5 text-xs text-muted">/status/{{ page.slug }} · {{ tc(':count component|:count components', page.components) }} · {{ tc(':count subscriber|:count subscribers', page.subscribers) }}</p>
                    </div>
                    <AcmeBadge :tone="acmeTone(page.published ? 'success' : 'neutral')">{{ page.published ? t('Published') : t('Draft') }}</AcmeBadge>
                </li>
            </ul>
        </section>

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
