<script setup lang="ts">
import type { ServerRow, ServersPage } from '~/types/infrastructure';

/**
 * The account's servers (the Acme theme's servers page): plan use, monthly cost, idle servers and average CPU up top,
 * then every server with its type, address, where it runs, its latest use and its status.
 */
definePageMeta({ layout: 'app', service: 'infrastructure' });
const { t } = useT();
const route = useRoute();
const money = useMoney();
const { data } = await useApi<ServersPage>(() => `/projects/${route.params.project}/infrastructure/servers`);
const project = computed(() => data.value.overview.project);
const busy = computed(() => data.value.servers.some((server) => !['active', 'failed'].includes(server.status)));
const type = ref('');
const rows = computed(() => data.value.servers.filter((server) => type.value === '' || server.type === type.value));
const measured = computed(() => data.value.servers.filter((server) => server.status === 'active' && server.usage !== null));
const costs = computed(() => data.value.servers.reduce<Record<string, number>>((totals, server) => {
    if (server.monthlyCost !== null && server.currency !== null) {
        totals[server.currency] = (totals[server.currency] ?? 0) + server.monthlyCost;
    }
    return totals;
}, {}));
const idle = computed(() => measured.value.filter((server) => (server.usage?.cpu ?? 0) < 10).length);
const averageCpu = computed(() => (measured.value.length === 0 ? '—' : `${Math.round(measured.value.reduce((total, server) => total + (server.usage?.cpu ?? 0), 0) / measured.value.length)}%`));
const typeIcon: Record<string, string> = { app: 'globe', web: 'globe', database: 'sheet', 'load-balancer': 'layers', worker: 'zap', cache: 'cube' };
const columns = computed(() => [
    { key: 'name', label: t('Server'), sortable: true },
    { key: 'typeLabel', label: t('Type'), sortable: true },
    { key: 'region', label: t('Where') },
    { key: 'usage', label: t('Usage'), sortable: true, sortValue: (row: ServerRow) => row.usage?.cpu ?? -1 },
    { key: 'monthlyCost', label: t('Per month'), sortable: true, class: 'text-right', sortValue: (row: ServerRow) => row.monthlyCost ?? -1 },
    { key: 'status', label: t('Status'), sortable: true },
]);
const more = computed(() => [
    ...(data.value.canManage
        ? [
              { label: t('Import a server'), icon: 'download', to: `/projects/${project.value.id}/infrastructure/imports/create` },
              { label: t('Move from Forge or Ploi'), icon: 'transfer', to: `/projects/${project.value.id}/infrastructure/moves` },
              { divider: true },
          ]
        : []),
    { label: t('Export CSV'), icon: 'sheet', onSelect: () => window.location.assign('/api/app/account/inventory/servers.csv') },
]);

/**
 * The colour of a usage bar: red when nearly full, amber when busy, green otherwise.
 *
 * @param value The percentage used.
 */
const bar = (value: number) => (value > 80 ? 'bg-rose-500' : value > 60 ? 'bg-amber-500' : 'bg-emerald-500');
let timer: number | undefined;

// While a server is being set up, the list keeps itself current.
watch(busy, (following) => {
    window.clearInterval(timer);
    timer = following ? window.setInterval(() => refreshNuxtData(), 10000) : undefined;
}, { immediate: import.meta.client });
onBeforeUnmount(() => window.clearInterval(timer));
</script>

<template>
    <div>
        <ProjectHeader :overview="data.overview" :title="t('Infrastructure')" :description="t('Servers belong to :account, so every project can deploy to them.', { account: data.accountName })">
            <template #actions>
                <AcmeMenu :items="more" :label="t('More')" icon="dots" align="right" />
                <AcmeBtn v-if="data.canManage" variant="primary" icon="plus" :to="`/projects/${project.id}/infrastructure/servers/create`">{{ t('Create server') }}</AcmeBtn>
            </template>
        </ProjectHeader>

        <AcmeEmptyState v-if="data.servers.length === 0" icon="cpu" :title="t('No servers yet')" :description="t('Create one at DigitalOcean, Hetzner Cloud or Vultr, or import an Ubuntu server you already run.')">
            <div v-if="data.canManage" class="flex flex-wrap justify-center gap-2">
                <AcmeBtn variant="primary" icon="plus" :to="`/projects/${project.id}/infrastructure/servers/create`">{{ t('Create server') }}</AcmeBtn>
                <AcmeBtn icon="download" :to="`/projects/${project.id}/infrastructure/imports/create`">{{ t('Import a server') }}</AcmeBtn>
            </div>
        </AcmeEmptyState>
        <div v-else class="space-y-6">
            <div class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
                <div class="rounded-2xl border border-line bg-surface p-4 shadow-card sm:p-5">
                    <p class="text-sm text-muted">{{ t('Servers on your plan') }}</p>
                    <p class="mt-2 text-2xl font-semibold tabular-nums text-ink">{{ data.servers.length }}<span v-if="data.limit !== null" class="text-base font-normal text-muted"> {{ t('of :limit', { limit: data.limit }) }}</span></p>
                    <AcmeProgress v-if="data.limit" :value="Math.min(100, (data.servers.length / data.limit) * 100)" :label="t('Servers used')" size="sm" class="mt-3" />
                </div>
                <AcmeStat :label="t('Estimated per month')" :value="money.amounts(costs)" icon="wallet" tone="green" />
                <AcmeStat :label="t('Idle servers')" :value="String(idle)" :period="t('under 10% CPU')" icon="moon" tone="amber" />
                <AcmeStat :label="t('Average CPU')" :value="averageCpu" icon="cpu" tone="blue" />
            </div>

            <AcmeDataTable :caption="t('Servers')" :rows="rows" :columns="columns" :search="['name', 'ip', 'region', 'provider']" :search-placeholder="t('Search servers')" :page-size="10">
                <template #toolbar>
                    <label class="sr-only" for="server-type">{{ t('Type') }}</label>
                    <select id="server-type" v-model="type" class="control w-44">
                        <option value="">{{ t('All types') }}</option>
                        <option v-for="option in data.types" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                </template>
                <template #cell-name="{ row }">
                    <NuxtLink :to="`/projects/${project.id}/infrastructure/servers/${row.id}`" class="flex items-center gap-3 hover:underline">
                        <AcmeIconBubble :icon="typeIcon[row.type] ?? 'cpu'" />
                        <span><b class="block font-medium text-ink">{{ row.name }}</b><span class="font-mono text-xs text-muted">{{ row.ip ?? '—' }}</span></span>
                    </NuxtLink>
                </template>
                <template #cell-typeLabel="{ row }">
                    <span class="flex flex-wrap gap-1"><AcmeBadge>{{ row.typeLabel }}</AcmeBadge><AcmeBadge v-if="row.imported" tone="blue">{{ t('Imported') }}</AcmeBadge></span>
                </template>
                <template #cell-region="{ row }">
                    <span class="block text-sm">{{ row.region ?? '—' }}</span><span class="text-xs text-muted">{{ row.provider ?? t('Your own server') }}</span>
                </template>
                <template #cell-usage="{ row }">
                    <span v-if="row.status === 'active' && row.usage" class="grid w-36 gap-1 text-[0.6875rem] text-muted">
                        <span v-for="meter in [[t('CPU'), row.usage.cpu], [t('Mem'), row.usage.memory], [t('Disk'), row.usage.disk]] as const" :key="meter[0]" class="flex items-center gap-2">
                            <span class="w-7">{{ meter[0] }}</span>
                            <span class="h-1.5 flex-1 overflow-hidden rounded-full bg-black/[.06] dark:bg-white/10" role="meter" :aria-label="meter[0]" :aria-valuenow="meter[1]" aria-valuemin="0" aria-valuemax="100"><span class="block h-full rounded-full" :class="bar(meter[1])" :style="{ width: `${meter[1]}%` }" /></span>
                        </span>
                    </span>
                    <span v-else-if="row.stage !== null && row.status !== 'active'" class="text-xs text-muted">{{ t('Stage :stage of :total', { stage: row.stage, total: row.finalStage }) }}</span>
                    <span v-else class="text-xs text-muted">—</span>
                </template>
                <template #cell-monthlyCost="{ row }">
                    <span class="tabular-nums">{{ row.monthlyCost !== null && row.currency ? money.amount(row.monthlyCost, row.currency) : '—' }}</span>
                </template>
                <template #cell-status="{ row }"><ServerStatusBadge :status="row.status" /></template>
            </AcmeDataTable>
        </div>
    </div>
</template>
