<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';
import type { Option } from '~/types/ui';

/** Caddy proxies that spread a hostname's traffic across app servers, skipping any that fail the health check. */
definePageMeta({ layout: 'app', service: 'infrastructure' });
type Node = { id: number; serverId: number; server: string; ip: string | null; port: number; weight: number; enabled: boolean; serverActive: boolean };
type Balancer = { id: number; hostname: string; healthPath: string; serverId: number; server: string; serverIp: string | null; websiteId: number | null; website: string | null; status: string; removing: boolean; error: string | null; nodes: Node[] };
type LoadBalancersPage = { overview: ProjectOverview; balancers: Balancer[]; servers: Option[]; proxyServers: Option[]; websites: Option[]; canCreate: boolean; canManage: boolean };
const { t } = useT();
const route = useRoute();
const { data } = await useApi<LoadBalancersPage>(() => `/projects/${route.params.project}/infrastructure/load-balancers`);
const project = computed(() => data.value.overview.project);
const base = computed(() => `/api/app/projects/${project.value.id}/infrastructure/load-balancers`);
const statuses = computed<Record<string, { tone: 'success' | 'danger' | 'neutral'; label: string }>>(() => ({
    active: { tone: 'success', label: t('Live') },
    failed: { tone: 'danger', label: t('Failed') },
    removing: { tone: 'neutral', label: t('Removing') },
    removal_failed: { tone: 'danger', label: t('Removal failed') },
}));
/** The servers that can still become one of a balancer's nodes. */
const candidates = (balancer: Balancer) => data.value.servers.filter((server) => Number(server.value) !== balancer.serverId && !balancer.nodes.some((node) => node.serverId === Number(server.value)));
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Load balancers')" :description="t('A Caddy proxy on one server that spreads a hostname’s traffic across application servers, skipping any that fail the health check.')">
            <template v-if="data.canCreate" #actions>
                <UiButton variant="primary" :to="{ query: { dialog: 'add-load-balancer' } }"><Icon name="plus" class="h-4 w-4" />{{ t('Add a load balancer') }}</UiButton>
            </template>
        </ProjectHeader>
        <Alert v-if="!data.canCreate && data.canManage" tone="info">{{ t('Load balancers come with the Business Deploy plan and above.') }}</Alert>

        <EmptyState v-if="data.balancers.length === 0" icon="server" :title="t('No load balancers yet')" :description="t('Add a server of the Load balancer type, then send a hostname’s traffic to two or more app servers.')" />

        <section v-for="balancer in data.balancers" :key="balancer.id" class="ui-card grid gap-4 p-5" :aria-label="balancer.hostname">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-lg font-bold text-ink">{{ balancer.hostname }}</p>
                    <p class="text-sm text-muted">
                        {{ t('On :server (:ip)', { server: balancer.server, ip: balancer.serverIp ?? '—' }) }} · {{ t('health check :path', { path: balancer.healthPath }) }}
                        <template v-if="balancer.websiteId"> · <NuxtLink :to="`/projects/${project.id}/infrastructure/websites/${balancer.websiteId}`" class="font-bold text-primary hover:underline">{{ balancer.website }}</NuxtLink></template>
                    </p>
                </div>
                <Badge :tone="statuses[balancer.status]?.tone ?? 'neutral'">{{ statuses[balancer.status]?.label ?? t('Applying') }}</Badge>
            </div>
            <Alert v-if="balancer.error" tone="danger">{{ balancer.error }}</Alert>
            <p v-if="balancer.nodes.length === 0" class="text-sm text-muted">{{ t('No nodes yet, so visitors get a 503 page.') }}</p>
            <ul v-else class="divide-y divide-line text-sm">
                <li v-for="node in balancer.nodes" :key="node.id" class="flex flex-wrap items-center justify-between gap-3 py-2">
                    <span>
                        <span class="font-bold">{{ node.server }}</span> <span class="font-mono text-xs text-muted">{{ node.ip ?? '—' }}:{{ node.port }}</span>
                        <span class="text-muted"> · {{ t('weight :weight', { weight: node.weight }) }}<template v-if="!node.enabled"> · {{ t('out of rotation') }}</template><template v-if="!node.serverActive"> · {{ t('server not active') }}</template></span>
                    </span>
                    <div v-if="data.canManage && !balancer.removing" class="flex gap-1">
                        <ApiForm :action="`${base}/${balancer.id}/nodes/${node.id}`" method="PUT">
                            <input type="hidden" name="upstream_port" :value="node.port">
                            <input type="hidden" name="weight" :value="node.weight">
                            <input type="hidden" name="is_enabled" :value="node.enabled ? 0 : 1">
                            <SubmitButton variant="quiet" size="sm">{{ node.enabled ? t('Take out') : t('Put back') }}</SubmitButton>
                        </ApiForm>
                        <ApiForm :action="`${base}/${balancer.id}/nodes/${node.id}`" method="DELETE"><SubmitButton variant="quiet" size="sm">{{ t('Remove') }}</SubmitButton></ApiForm>
                    </div>
                </li>
            </ul>
            <div v-if="data.canManage" class="flex flex-wrap gap-2">
                <ApiForm v-if="['failed', 'removal_failed'].includes(balancer.status)" :action="`${base}/${balancer.id}/retry`"><SubmitButton variant="secondary" size="sm">{{ t('Retry') }}</SubmitButton></ApiForm>
                <template v-if="!balancer.removing">
                    <FormDialog :id="`add-node-${balancer.id}`" :title="t('Add a node to :balancer', { balancer: balancer.hostname })" :description="t('Traffic is shared between nodes by weight.')" :action="`${base}/${balancer.id}/nodes`" :submit="t('Add node')" size="wide">
                        <template #trigger="{ open }"><UiButton size="sm" @click="open"><Icon name="plus" class="h-4 w-4" />{{ t('Add a node') }}</UiButton></template>
                        <div class="grid items-start gap-4 sm:grid-cols-3">
                            <SelectField :id="`node-server-${balancer.id}`" name="server_id" :label="t('Server')" :options="candidates(balancer)" />
                            <InputField :id="`node-port-${balancer.id}`" name="upstream_port" type="number" min="1" max="65535" :label="t('Port')" model-value="80" required />
                            <InputField :id="`node-weight-${balancer.id}`" name="weight" type="number" min="1" max="10" :label="t('Weight')" model-value="1" required />
                        </div>
                    </FormDialog>
                    <FormDialog :id="`edit-balancer-${balancer.id}`" :title="t('Edit')" :action="`${base}/${balancer.id}`" method="PUT" :submit="t('Save load balancer')" size="wide">
                        <template #trigger="{ open }"><UiButton variant="quiet" size="sm" @click="open">{{ t('Edit') }}</UiButton></template>
                        <LoadBalancerFields :balancer="balancer" :websites="data.websites" />
                    </FormDialog>
                    <DeleteDialog
                        :id="`delete-balancer-${balancer.id}`"
                        :title="t('Delete the load balancer for :hostname?', { hostname: balancer.hostname })"
                        :description="t('Its Caddy site is removed from :server; the app servers aren’t touched.', { server: balancer.server })"
                        :action="`${base}/${balancer.id}`"
                        :submit-label="t('Delete load balancer')"
                    >
                        <template #trigger="{ open }"><UiButton variant="quiet" size="sm" @click="open">{{ t('Delete') }}</UiButton></template>
                    </DeleteDialog>
                </template>
            </div>
        </section>

        <FormDialog
            v-if="data.canCreate"
            id="add-load-balancer"
            :title="t('Add a load balancer')"
            :description="t('Point the hostname’s DNS at the proxy server; Caddy gets its certificate. Nodes are reached over HTTP on their public address.')"
            :action="base"
            :submit="t('Add load balancer')"
            size="wide"
        >
            <div class="grid items-start gap-4 sm:grid-cols-2">
                <SelectField id="balancer-server" name="server_id" :label="t('Proxy server')" :options="data.proxyServers" :placeholder="data.proxyServers.length === 0 ? t('No active server runs Caddy') : undefined" />
                <LoadBalancerFields :balancer="null" :websites="data.websites" />
            </div>
        </FormDialog>
    </div>
</template>
