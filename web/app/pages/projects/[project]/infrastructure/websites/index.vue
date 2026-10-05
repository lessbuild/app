<script setup lang="ts">
import type { WebsitesPage } from '~/types/infrastructure';

/**
 * The account's websites on its app servers (the Acme theme's websites page): a card each with its domain, directory,
 * server, PHP version, health checks, environment and CDN, with creating one or adopting one already on a server.
 */
definePageMeta({ layout: 'app', service: 'infrastructure' });
const { t } = useT();
const route = useRoute();
const { data } = await useApi<WebsitesPage>(() => `/projects/${route.params.project}/infrastructure/websites`);
const project = computed(() => data.value.overview.project);
const base = computed(() => `/api/app/projects/${project.value.id}/infrastructure/websites`);
const busy = computed(() => data.value.websites.some((website) => !['active', 'failed'].includes(website.status)));
const search = ref('');
const shown = computed(() => {
    const query = search.value.trim().toLowerCase();
    return data.value.websites.filter((website) => `${website.name} ${website.url} ${website.server ?? ''}`.toLowerCase().includes(query));
});
const more = computed(() => [{ label: t('Export CSV'), icon: 'sheet', onSelect: () => window.location.assign('/api/app/account/inventory/websites.csv') }]);
let timer: number | undefined;

// While a website is being set up, the list keeps itself current.
watch(busy, (following) => {
    window.clearInterval(timer);
    timer = following ? window.setInterval(() => refreshNuxtData(), 10000) : undefined;
}, { immediate: import.meta.client });
onBeforeUnmount(() => window.clearInterval(timer));
</script>

<template>
    <div>
        <ProjectHeader :overview="data.overview" :title="t('Infrastructure')" :description="t('Sites on your app servers: a Caddy site with HTTPS, a MySQL database and a .env file each.')">
            <template #actions>
                <AcmeMenu :items="more" :label="t('More')" icon="dots" align="right" />
                <template v-if="data.canManage">
                    <AcmeBtn icon="download" :to="{ query: { dialog: 'import-website' } }">{{ t('Import a website') }}</AcmeBtn>
                    <AcmeBtn variant="primary" icon="plus" :to="`/projects/${project.id}/infrastructure/websites/create`">{{ t('Create website') }}</AcmeBtn>
                </template>
            </template>
        </ProjectHeader>

        <AcmeEmptyState v-if="data.websites.length === 0" icon="globe" :title="t('No websites yet')" :description="t('Create one on an app server, or import an application already under /var/www.')">
            <AcmeBtn v-if="data.canManage" variant="primary" icon="plus" :to="`/projects/${project.id}/infrastructure/websites/create`">{{ t('Create website') }}</AcmeBtn>
        </AcmeEmptyState>
        <div v-else class="space-y-6">
            <div class="flex flex-wrap items-center gap-3">
                <AcmeSearchInput v-model="search" :label="t('Search websites')" class="w-full sm:w-72" />
                <span v-if="data.limit !== null" class="text-sm text-muted">{{ t(':used of :limit websites on your plan', { used: data.websites.length, limit: data.limit }) }}</span>
            </div>
            <ul class="grid gap-4 md:grid-cols-2">
                <li v-for="website in shown" :key="website.id">
                    <NuxtLink :to="`/projects/${project.id}/infrastructure/websites/${website.id}`" class="group flex h-full flex-col rounded-2xl border border-line bg-surface p-5 shadow-card transition hover:-translate-y-0.5 hover:shadow-lift">
                        <div class="flex items-start gap-3">
                            <AcmeIconBubble icon="globe" size="md" />
                            <span class="min-w-0 flex-1"><b class="block truncate font-semibold text-ink group-hover:underline">{{ website.url }}</b><span class="font-mono text-xs text-muted">/var/www/{{ website.directory }}</span></span>
                            <WebsiteStatusBadge :status="website.status" />
                        </div>
                        <dl class="my-4 grid grid-cols-3 gap-3 text-sm">
                            <div><dt class="text-xs text-muted">{{ t('Server') }}</dt><dd class="mt-0.5 truncate font-medium text-ink">{{ website.server ?? '—' }}</dd></div>
                            <div><dt class="text-xs text-muted">PHP</dt><dd class="mt-0.5 font-medium text-ink">{{ website.php }}</dd></div>
                            <div><dt class="text-xs text-muted">{{ t('Health') }}</dt><dd :class="['mt-0.5 font-medium', website.healthChecked ? 'text-emerald-600 dark:text-emerald-400' : 'text-muted']">{{ website.healthChecked ? t('Checked') : t('Off') }}</dd></div>
                        </dl>
                        <p class="mt-auto flex items-center gap-2 border-t border-line pt-3 text-xs text-muted">
                            <AcmeIcon name="layers" :size="13" />{{ website.environment ?? t('Not linked to an environment') }}
                            <span v-if="website.cdn" class="ml-auto flex items-center gap-1"><AcmeIcon name="shield" :size="13" />CDN</span>
                        </p>
                    </NuxtLink>
                </li>
            </ul>
            <p v-if="shown.length === 0" class="text-sm text-muted">{{ t('No websites match “:query”.', { query: search }) }}</p>
        </div>

        <template v-if="data.canManage && data.options">
            <UiDialog v-if="data.options.hosts.length === 0" id="import-website" :title="t('Import a website')">
                <AcmeEmptyState icon="cpu" :title="t('No app servers ready')" :description="t('Websites need an active app server with MySQL. Create one first.')">
                    <AcmeBtn variant="primary" :to="`/projects/${project.id}/infrastructure/servers/create`">{{ t('Create a server') }}</AcmeBtn>
                </AcmeEmptyState>
            </UiDialog>
            <FormDialog
                v-else
                id="import-website"
                :title="t('Import a website')"
                :description="t('Adopt an application already in /var/www on an app server. Its files, Caddy site and database are left as they are.')"
                :action="`${base}/import`"
                :submit="t('Import website')"
            >
                <WebsiteImportFields :hosts="data.options.hosts" />
            </FormDialog>
        </template>
    </div>
</template>
