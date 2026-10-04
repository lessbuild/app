<script setup lang="ts">
import type { ServersPage } from '~/types/infrastructure';

/** The account's servers (every project can deploy to them), with creating, importing or moving one in. */
definePageMeta({ layout: 'app', service: 'infrastructure' });
const { t } = useT();
const route = useRoute();
const { data } = await useApi<ServersPage>(() => `/projects/${route.params.project}/infrastructure/servers`);
const project = computed(() => data.value.overview.project);
const busy = computed(() => data.value.servers.some((server) => !['active', 'failed'].includes(server.status)));
let timer: number | undefined;

// While a server is being set up, the list keeps itself current.
watch(busy, (following) => {
    window.clearInterval(timer);
    timer = following ? window.setInterval(() => refreshNuxtData(), 10000) : undefined;
}, { immediate: import.meta.client });
onBeforeUnmount(() => window.clearInterval(timer));
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Servers')" :description="t('Servers belong to :account, so every project can deploy to them.', { account: data.accountName })">
            <template #actions>
                <a href="/api/app/account/inventory/servers.csv" class="ui-btn ui-btn-quiet ui-btn-sm" download>{{ t('Export CSV') }}</a>
                <template v-if="data.canManage">
                    <UiButton :to="`/projects/${project.id}/infrastructure/moves`" variant="quiet">{{ t('Move from Forge or Ploi') }}</UiButton>
                    <UiButton :to="{ query: { dialog: 'import-server' } }">{{ t('Import a server') }}</UiButton>
                    <UiButton variant="primary" :to="{ query: { dialog: 'create-server' } }"><Icon name="plus" class="h-4 w-4" />{{ t('Create a server') }}</UiButton>
                </template>
            </template>
        </ProjectHeader>
        <p v-if="data.canManage && data.limit !== null" class="text-sm text-muted">{{ t(':used of :limit servers on your plan', { used: data.servers.length, limit: data.limit }) }}</p>

        <EmptyState v-if="data.servers.length === 0" icon="server" :title="t('No servers yet')" :description="t('Create one at DigitalOcean, Hetzner Cloud or Vultr, or import an Ubuntu server you already run.')">
            <template v-if="data.canManage" #action>
                <UiButton variant="primary" :to="{ query: { dialog: 'create-server' } }">{{ t('Create a server') }}</UiButton>
            </template>
        </EmptyState>
        <DataTable v-else :caption="t('Servers')">
            <template #head>
                <tr><th scope="col">{{ t('Server') }}</th><th scope="col">{{ t('Type') }}</th><th scope="col">{{ t('Address') }}</th><th scope="col">{{ t('Where') }}</th><th scope="col">{{ t('Status') }}</th></tr>
            </template>
            <tr v-for="server in data.servers" :key="server.id">
                <td><NuxtLink :to="`/projects/${project.id}/infrastructure/servers/${server.id}`" class="font-bold text-primary hover:underline">{{ server.name }}</NuxtLink></td>
                <td>{{ server.typeLabel }}</td>
                <td class="font-mono text-xs">{{ server.ip ?? '—' }}</td>
                <td class="text-muted">{{ server.provider ?? t('Imported') }}<template v-if="server.region"> · {{ server.region }}</template></td>
                <td><ServerStatusBadge :status="server.status" /></td>
            </tr>
        </DataTable>

        <template v-if="data.canManage">
            <CreateServerDialog :project-id="project.id" />
            <FormDialog
                id="import-server"
                :title="t('Import a server')"
                :description="t('We connect over SSH, look around without changing anything, and show what we found before you confirm.')"
                :action="`/api/app/projects/${project.id}/infrastructure/imports`"
                :submit="t('Inspect server')"
                size="large"
            >
                <ServerImportFields :types="data.types" />
            </FormDialog>
        </template>
    </div>
</template>
