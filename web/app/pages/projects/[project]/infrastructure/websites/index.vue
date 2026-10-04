<script setup lang="ts">
import type { WebsitesPage } from '~/types/infrastructure';

/** The account's websites on its app servers, with creating one or adopting one already on a server. */
definePageMeta({ layout: 'app', service: 'infrastructure' });
const { t } = useT();
const route = useRoute();
const { data } = await useApi<WebsitesPage>(() => `/projects/${route.params.project}/infrastructure/websites`);
const project = computed(() => data.value.overview.project);
const base = computed(() => `/api/app/projects/${project.value.id}/infrastructure/websites`);
const busy = computed(() => data.value.websites.some((website) => !['active', 'failed'].includes(website.status)));
let timer: number | undefined;

watch(busy, (following) => {
    window.clearInterval(timer);
    timer = following ? window.setInterval(() => refreshNuxtData(), 10000) : undefined;
}, { immediate: import.meta.client });
onBeforeUnmount(() => window.clearInterval(timer));
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Websites')" :description="t('Sites on your app servers: a Caddy site with HTTPS, a MySQL database and a .env file each.')">
            <template #actions>
                <a href="/api/app/account/inventory/websites.csv" class="ui-btn ui-btn-quiet ui-btn-sm" download>{{ t('Export CSV') }}</a>
                <template v-if="data.canManage">
                    <UiButton :to="{ query: { dialog: 'import-website' } }">{{ t('Import a website') }}</UiButton>
                    <UiButton variant="primary" :to="{ query: { dialog: 'create-website' } }"><Icon name="plus" class="h-4 w-4" />{{ t('Create a website') }}</UiButton>
                </template>
            </template>
        </ProjectHeader>
        <p v-if="data.canManage && data.limit !== null" class="text-sm text-muted">{{ t(':used of :limit websites on your plan', { used: data.websites.length, limit: data.limit }) }}</p>

        <EmptyState v-if="data.websites.length === 0" icon="globe" :title="t('No websites yet')" :description="t('Create one on an app server, or import an application already under /var/www.')" />
        <DataTable v-else :caption="t('Websites')">
            <template #head>
                <tr><th scope="col">{{ t('Website') }}</th><th scope="col">{{ t('Domain') }}</th><th scope="col">{{ t('Server') }}</th><th scope="col">{{ t('Status') }}</th></tr>
            </template>
            <tr v-for="website in data.websites" :key="website.id">
                <td><NuxtLink :to="`/projects/${project.id}/infrastructure/websites/${website.id}`" class="font-bold text-primary hover:underline">{{ website.name }}</NuxtLink></td>
                <td class="font-mono text-xs">{{ website.url }}</td>
                <td class="text-muted">{{ website.server ?? '—' }}</td>
                <td><WebsiteStatusBadge :status="website.status" /></td>
            </tr>
        </DataTable>

        <template v-if="data.canManage && data.options">
            <template v-if="data.options.hosts.length === 0">
                <UiDialog v-for="id in ['create-website', 'import-website']" :id="id" :key="id" :title="id === 'create-website' ? t('Create a website') : t('Import a website')">
                    <EmptyState icon="server" :title="t('No app servers ready')" :description="t('Websites need an active app server with MySQL. Create one first.')">
                        <template #action><UiButton variant="primary" :to="`/projects/${project.id}/infrastructure/servers?dialog=create-server`">{{ t('Create a server') }}</UiButton></template>
                    </EmptyState>
                </UiDialog>
            </template>
            <template v-else>
                <FormDialog id="create-website" :title="t('Create a website')" :description="t('We set up the Caddy site, a MySQL database and user, and the .env file.')" :action="base" :submit="t('Create website')" size="large">
                    <PlanLimitAlert billing-url="/account/billing" />
                    <WebsiteFields :options="data.options" />
                </FormDialog>
                <FormDialog
                    id="import-website"
                    :title="t('Import a website')"
                    :description="t('Adopt an application already in /var/www on an app server. Its files, Caddy site and database are left as they are.')"
                    :action="`${base}/import`"
                    :submit="t('Import website')"
                    size="wide"
                >
                    <WebsiteImportFields :hosts="data.options.hosts" />
                </FormDialog>
            </template>
        </template>
    </div>
</template>
