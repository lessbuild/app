<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';
import type { Option } from '~/types/ui';

/**
 * Caddy proxies that spread a hostname's traffic across app servers, skipping any that fail the health check (the Acme
 * theme's load balancers page): each balancer's nodes drawn as bars sized by their share of the traffic.
 */
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
/**
 * A node's share of its balancer's traffic, as a whole percentage of the weight of the nodes in rotation.
 *
 * @param balancer The load balancer.
 * @param node The node.
 */
function share(balancer: Balancer, node: Node): number {
    const total = balancer.nodes.filter((item) => item.enabled).reduce((sum, item) => sum + item.weight, 0);
    return total === 0 ? 0 : Math.round((node.weight / total) * 100);
}

/** The servers that can still become one of a balancer's nodes. */
const candidates = (balancer: Balancer) => data.value.servers.filter((server) => Number(server.value) !== balancer.serverId && !balancer.nodes.some((node) => node.serverId === Number(server.value)));
</script>

<template>
    <div>
        <ProjectHeader :overview="data.overview" :title="t('Infrastructure')" :description="t('A Caddy proxy on one server that spreads a hostname’s traffic across application servers, skipping any that fail the health check.')">
            <template v-if="data.canCreate" #actions>
                <AcmeBtn variant="primary" icon="plus" :to="{ query: { dialog: 'add-load-balancer' } }">{{ t('Add load balancer') }}</AcmeBtn>
            </template>
        </ProjectHeader>
        <div class="space-y-6">
            <AcmeAlert v-if="!data.canCreate && data.canManage" tone="info">{{ t('Load balancers come with the Business Deploy plan and above.') }}</AcmeAlert>

            <AcmeEmptyState v-if="data.balancers.length === 0" icon="layers" :title="t('No load balancers yet')" :description="t('Add a server of the Load balancer type, then send a hostname’s traffic to two or more app servers.')" />

            <AcmeCard
                v-for="balancer in data.balancers"
                :key="balancer.id"
                :title="balancer.hostname"
                :description="`${t('On :server (:ip)', { server: balancer.server, ip: balancer.serverIp ?? '—' })} · ${t('health check :path', { path: balancer.healthPath })}`"
            >
                <template #action><AcmeBadge :tone="acmeTone(statuses[balancer.status]?.tone ?? 'neutral')" dot>{{ statuses[balancer.status]?.label ?? t('Applying') }}</AcmeBadge></template>
                <p v-if="balancer.websiteId" class="-mt-3 mb-4 text-sm text-muted">
                    {{ t('Serves') }} <NuxtLink :to="`/projects/${project.id}/infrastructure/websites/${balancer.websiteId}`" class="font-medium text-ink hover:underline">{{ balancer.website }}</NuxtLink>
                </p>
                <AcmeAlert v-if="balancer.error" tone="danger" class="mb-4">{{ balancer.error }}</AcmeAlert>
                <p v-if="balancer.nodes.length === 0" class="text-sm text-muted">{{ t('No nodes yet, so visitors get a 503 page.') }}</p>
                <ul v-else class="space-y-3">
                    <li v-for="node in balancer.nodes" :key="node.id" class="flex flex-wrap items-center gap-3">
                        <span class="w-28 min-w-0">
                            <b class="block truncate font-medium text-ink">{{ node.server }}</b>
                            <span class="font-mono text-xs text-muted">{{ node.ip ?? '—' }}:{{ node.port }}</span>
                        </span>
                        <span class="h-8 min-w-40 flex-1 overflow-hidden rounded-lg bg-black/[.04] dark:bg-white/[.06]">
                            <span
                                class="flex h-full items-center rounded-lg px-3 text-xs font-medium whitespace-nowrap transition-all"
                                :class="node.enabled ? 'bg-accent text-accent-fg' : 'hatch bg-black/[.03] text-muted'"
                                :style="{ width: node.enabled ? `${Math.max(share(balancer, node), 12)}%` : '100%' }"
                            >
                                {{ node.enabled ? t(':share% · weight :weight', { share: share(balancer, node), weight: node.weight }) : t('Out of rotation') }}<template v-if="!node.serverActive"> · {{ t('server not active') }}</template>
                            </span>
                        </span>
                        <div v-if="data.canManage && !balancer.removing" class="flex gap-1">
                            <ApiForm :action="`${base}/${balancer.id}/nodes/${node.id}`" method="PUT">
                                <input type="hidden" name="upstream_port" :value="node.port">
                                <input type="hidden" name="weight" :value="node.weight">
                                <input type="hidden" name="is_enabled" :value="node.enabled ? 0 : 1">
                                <SubmitButton variant="secondary" size="sm">{{ node.enabled ? t('Take out') : t('Put back') }}</SubmitButton>
                            </ApiForm>
                            <ApiForm :action="`${base}/${balancer.id}/nodes/${node.id}`" method="DELETE"><SubmitButton variant="quiet" size="sm">{{ t('Remove') }}</SubmitButton></ApiForm>
                        </div>
                    </li>
                </ul>
                <div v-if="data.canManage" class="mt-5 flex flex-wrap gap-2 border-t border-line pt-4">
                    <ApiForm v-if="['failed', 'removal_failed'].includes(balancer.status)" :action="`${base}/${balancer.id}/retry`"><SubmitButton variant="secondary" size="sm">{{ t('Retry') }}</SubmitButton></ApiForm>
                    <template v-if="!balancer.removing">
                        <FormDialog :id="`add-node-${balancer.id}`" :title="t('Add a node to :balancer', { balancer: balancer.hostname })" :description="t('Traffic is shared between nodes by weight.')" :action="`${base}/${balancer.id}/nodes`" :submit="t('Add node')">
                            <template #trigger="{ open }"><AcmeBtn size="sm" icon="plus" @click="open">{{ t('Add a node') }}</AcmeBtn></template>
                            <div class="grid items-start gap-4">
                                <SelectField :id="`node-server-${balancer.id}`" name="server_id" :label="t('Server')" :options="candidates(balancer)" />
                                <InputField :id="`node-port-${balancer.id}`" name="upstream_port" type="number" min="1" max="65535" :label="t('Port')" model-value="80" required />
                                <InputField :id="`node-weight-${balancer.id}`" name="weight" type="number" min="1" max="10" :label="t('Weight')" model-value="1" required />
                            </div>
                        </FormDialog>
                        <FormDialog :id="`edit-balancer-${balancer.id}`" :title="t('Edit')" :action="`${base}/${balancer.id}`" method="PUT" :submit="t('Save load balancer')">
                            <template #trigger="{ open }"><AcmeBtn size="sm" variant="ghost" icon="edit" @click="open">{{ t('Edit') }}</AcmeBtn></template>
                            <div class="grid gap-4"><LoadBalancerFields :balancer="balancer" :websites="data.websites" /></div>
                        </FormDialog>
                        <DeleteDialog
                            :id="`delete-balancer-${balancer.id}`"
                            :title="t('Delete the load balancer for :hostname?', { hostname: balancer.hostname })"
                            :description="t('Its Caddy site is removed from :server; the app servers aren’t touched.', { server: balancer.server })"
                            :action="`${base}/${balancer.id}`"
                            :submit-label="t('Delete load balancer')"
                        >
                            <template #trigger="{ open }"><AcmeBtn size="sm" variant="ghost" class="ml-auto" @click="open">{{ t('Delete') }}</AcmeBtn></template>
                        </DeleteDialog>
                    </template>
                </div>
            </AcmeCard>

            <FormDialog
                v-if="data.canCreate"
                id="add-load-balancer"
                :title="t('Add a load balancer')"
                :description="t('Point the hostname’s DNS at the proxy server; Caddy gets its certificate. Nodes are reached over HTTP on their public address.')"
                :action="base"
                :submit="t('Add load balancer')"
            >
                <div class="grid items-start gap-4">
                    <SelectField id="balancer-server" name="server_id" :label="t('Proxy server')" :options="data.proxyServers" :placeholder="data.proxyServers.length === 0 ? t('No active server runs Caddy') : undefined" />
                    <LoadBalancerFields :balancer="null" :websites="data.websites" />
                </div>
            </FormDialog>
        </div>
    </div>
</template>
