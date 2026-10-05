<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';

/**
 * What the account's servers cost a month (the Acme theme's costs page): the estimate against the budget, cost by
 * server, idle servers, the providers' actual bills, right-sizing and the monthly budget.
 */
definePageMeta({ layout: 'app', service: 'infrastructure' });
type Row = {
    serverId: number;
    server: string;
    provider: string | null;
    imported: boolean;
    size: string | null;
    websites: number;
    attribution: string;
    projects: string[];
    averageCpu: number | null;
    monthly: number | null;
    currency: string;
    entered: boolean;
    enteredCost: number | null;
    idle: boolean;
};
type Money = { amount: number; currency: string } | null;
type Sizing = { id: string; vcpus: number; memory_gb: number; price: number | null };
type CostsPage = {
    overview: ProjectOverview;
    accountName: string;
    totals: Record<string, number>;
    unknown: number;
    idle: number;
    rows: Row[];
    bills: Array<{ provider: string; type: string; error: string | null; checked: boolean; estimate: number | null; previous: Money; difference: number | null; current: Money }>;
    rightsizing: Array<{ server: { id: number; name: string }; direction: string; cpu: number; memory: number; current: Sizing; suggested: Sizing; saving: number | null; currency: string }>;
    rightsizingDays: number;
    budget: number | null;
    canManage: boolean;
    canBudget: boolean;
};
const { t, tc, number } = useT();
const money = useMoney();
const route = useRoute();
const { data } = await useApi<CostsPage>(() => `/projects/${route.params.project}/infrastructure/costs`);
const project = computed(() => data.value.overview.project);
const base = computed(() => `/api/app/projects/${project.value.id}/infrastructure/costs`);
const usd = computed(() => data.value.totals.USD ?? 0);
const budgetLine = computed(() => {
    if (data.value.budget === null) {
        return null;
    }
    return usd.value > data.value.budget
        ? t('Over by :amount', { amount: money.amount(usd.value - data.value.budget, 'USD') })
        : t(':amount left', { amount: money.amount(data.value.budget - usd.value, 'USD') });
});
const priced = computed(() => data.value.rows.filter((row) => row.monthly !== null).sort((a, b) => (b.monthly ?? 0) - (a.monthly ?? 0)));
// With one currency the bars show amounts; with several, each server's currency goes beside its name.
const oneCurrency = computed(() => (new Set(priced.value.map((row) => row.currency)).size === 1 ? priced.value[0]?.currency ?? null : null));
const byServer = computed(() => priced.value.map((row) => ({ label: oneCurrency.value ? row.server : `${row.server} (${row.currency})`, value: row.monthly ?? 0 })));
const formatCost = (value: number) => (oneCurrency.value ? money.amount(value, oneCurrency.value) : number(value));
const idleRows = computed(() => data.value.rows.filter((row) => row.idle));
const savings = computed(() => data.value.rightsizing.reduce<Record<string, number>>((totals, row) => {
    if (row.saving !== null && row.saving > 0) {
        totals[row.currency] = (totals[row.currency] ?? 0) + row.saving;
    }
    return totals;
}, {}));
const currencies = [{ value: 'USD', label: 'USD' }, { value: 'EUR', label: 'EUR' }];
const imported = computed(() => data.value.rows.filter((row) => row.imported));
/** Who a server's cost belongs to: one project, several, or none. */
const usedBy = (row: Row) => (row.attribution === 'direct' ? row.projects[0] : row.attribution === 'shared' ? t('Shared: :projects', { projects: row.projects.join(', ') }) : t('No project'));
</script>

<template>
    <div>
        <ProjectHeader :overview="data.overview" :title="t('Infrastructure')" :description="t('What :account’s servers cost a month, from each provider’s price list, and which are idle.', { account: data.accountName })">
            <template v-if="data.canManage" #actions>
                <ApiForm :action="`${base}/refresh`"><SubmitButton variant="secondary">{{ t('Check prices now') }}</SubmitButton></ApiForm>
            </template>
        </ProjectHeader>

        <div class="space-y-6">
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-2xl border border-line bg-surface p-5 shadow-card sm:col-span-2">
                    <p class="text-sm text-muted">{{ t('Estimated per month') }}</p>
                    <p class="mt-2 text-3xl font-semibold tabular-nums text-ink">{{ money.amounts(data.totals) }}</p>
                    <p v-if="data.unknown > 0" class="mt-1 text-xs text-muted">{{ tc(':count server has no known price|:count servers have no known price', data.unknown, { count: data.unknown }) }}</p>
                    <template v-if="data.budget !== null">
                        <p class="mt-3 flex items-center justify-between gap-3 text-sm"><span class="text-muted">{{ t('Budget (USD servers)') }}</span><b class="text-ink">{{ money.amount(data.budget, 'USD') }} · {{ budgetLine }}</b></p>
                        <AcmeProgress :value="data.budget > 0 ? Math.min(100, (usd / data.budget) * 100) : 100" :label="t('Budget used')" size="lg" striped :color="usd > data.budget ? 'bg-rose-500' : 'bg-emerald-500'" class="mt-2" />
                    </template>
                    <p v-else class="mt-3 text-sm text-muted">{{ t('No budget set.') }}</p>
                </div>
                <AcmeStat :label="t('Idle servers')" :value="number(data.idle)" :period="t('no websites, or under 10% CPU')" icon="moon" tone="amber" />
                <AcmeStat :label="t('Possible savings')" :value="Object.keys(savings).length === 0 ? '—' : `${money.amounts(savings)}${t('/mo')}`" :period="t('from right-sizing')" icon="piggy" tone="green" />
            </div>
            <AcmeAlert v-if="data.budget !== null && usd > data.budget" tone="warning">{{ t('Servers priced in US dollars cost more than the monthly budget.') }}</AcmeAlert>

            <AcmeEmptyState v-if="data.rows.length === 0" icon="cpu" :title="t('No servers yet')" :description="t('Costs appear once the account has servers.')" />
            <template v-else>
                <div class="grid gap-6 xl:grid-cols-3">
                    <AcmeCard :title="t('Cost by server')" :description="t('Per month, list price')" class="xl:col-span-2">
                        <AcmeBarList v-if="byServer.length > 0" :label="t('Cost by server')" :items="byServer" :value-label="t('Per month')" :format="formatCost" />
                        <p v-else class="text-sm text-muted">{{ t('No server has a known price yet.') }}</p>
                    </AcmeCard>
                    <AcmeCard :title="t('Idle servers')" :description="t('No websites, or under 10% CPU for the last hour')">
                        <ul v-if="idleRows.length > 0" class="space-y-2 text-sm">
                            <li v-for="row in idleRows" :key="row.serverId" class="flex items-center gap-2">
                                <NuxtLink :to="`/projects/${project.id}/infrastructure/servers/${row.serverId}`" class="font-medium text-ink hover:underline">{{ row.server }}</NuxtLink>
                                <span class="truncate text-muted">· {{ row.averageCpu === null ? '—' : `CPU ${row.averageCpu}%` }} · {{ usedBy(row) }}</span>
                                <span class="ml-auto tabular-nums">{{ row.monthly === null ? '—' : money.amount(row.monthly, row.currency) }}</span>
                            </li>
                        </ul>
                        <p v-else class="text-sm text-muted">{{ t('None. Every server is busy.') }}</p>
                    </AcmeCard>
                </div>

                <AcmeCard :title="t('Server costs')" :description="t('Prices are list prices from each provider (Hetzner in euros, including VAT), checked daily; bandwidth, backups and taxes aren’t included.')" :padded="false">
                    <DataTable :caption="t('Server costs')" :framed="false">
                        <template #head>
                            <tr><th scope="col">{{ t('Server') }}</th><th scope="col">{{ t('Size') }}</th><th scope="col">{{ t('Used by') }}</th><th scope="col">{{ t('CPU (1 h)') }}</th><th scope="col" class="text-right">{{ t('Per month') }}</th></tr>
                        </template>
                        <tr v-for="row in data.rows" :key="row.serverId">
                            <td>
                                <NuxtLink :to="`/projects/${project.id}/infrastructure/servers/${row.serverId}`" class="font-medium text-ink hover:underline">{{ row.server }}</NuxtLink>
                                <AcmeBadge v-if="row.idle" tone="amber" class="ml-2">{{ t('Idle') }}</AcmeBadge>
                            </td>
                            <td class="text-muted">{{ row.provider ?? t('Imported') }}<template v-if="row.size"> · <span class="font-mono text-xs">{{ row.size }}</span></template></td>
                            <td class="text-muted">{{ tc(':count website|:count websites', row.websites, { count: row.websites }) }} · {{ usedBy(row) }}</td>
                            <td class="tabular-nums">{{ row.averageCpu === null ? '—' : `${row.averageCpu}%` }}</td>
                            <td class="text-right font-medium tabular-nums text-ink">
                                {{ row.monthly === null ? '—' : money.amount(row.monthly, row.currency) }}
                                <span v-if="row.entered" class="text-xs font-normal text-muted">{{ t('entered') }}</span>
                            </td>
                        </tr>
                    </DataTable>
                </AcmeCard>
            </template>

            <AcmeCard
                v-if="data.bills.length > 0"
                id="bills"
                :title="t('Actual bills')"
                :description="t('What each provider charged, read daily from its billing API, next to the estimate from list prices. Hetzner has no billing API, so it isn’t shown.')"
                :padded="false"
            >
                <DataTable :caption="t('Actual bills')" :framed="false">
                    <template #head>
                        <tr>
                            <th scope="col">{{ t('Provider') }}</th><th scope="col" class="text-right">{{ t('Estimate a month') }}</th><th scope="col" class="text-right">{{ t('Last month’s invoice') }}</th>
                            <th scope="col" class="text-right">{{ t('Difference') }}</th><th scope="col" class="text-right">{{ t('This month so far') }}</th>
                        </tr>
                    </template>
                    <tr v-for="(bill, index) in data.bills" :key="index">
                        <td>
                            <span class="font-medium text-ink">{{ bill.provider }}</span> <span class="text-muted">· {{ bill.type }}</span>
                            <p v-if="bill.error" class="text-xs text-rose-600 dark:text-rose-400">{{ bill.error }}</p>
                            <p v-else-if="!bill.checked" class="text-xs text-muted">{{ t('Not read yet') }}</p>
                        </td>
                        <td class="text-right tabular-nums">{{ bill.estimate === null ? '—' : money.amount(bill.estimate, 'USD') }}</td>
                        <td class="text-right tabular-nums">{{ bill.previous === null ? '—' : money.amount(bill.previous.amount, bill.previous.currency) }}</td>
                        <td class="text-right tabular-nums">
                            <template v-if="bill.difference === null">—</template>
                            <template v-else-if="Math.abs(bill.difference) < 0.01">{{ t('Matches') }}</template>
                            <AcmeBadge v-else :tone="bill.difference > 0 ? 'amber' : 'green'">
                                {{ bill.difference > 0 ? t(':amount more', { amount: money.amount(bill.difference, 'USD') }) : t(':amount less', { amount: money.amount(-bill.difference, 'USD') }) }}
                            </AcmeBadge>
                        </td>
                        <td class="text-right tabular-nums">{{ bill.current === null ? '—' : money.amount(bill.current.amount, bill.current.currency) }}</td>
                    </tr>
                </DataTable>
                <p class="px-5 pb-5 text-xs text-muted sm:px-6">
                    {{ t('Invoices include bandwidth, backups, snapshots, volumes and anything else in the provider account, so they’re usually higher than the servers alone. Lightsail and EC2 read AWS Cost Explorer, which needs ce:GetCostAndUsage on the key.') }}
                </p>
            </AcmeCard>

            <AcmeCard
                v-if="data.rightsizing.length > 0"
                id="rightsizing"
                :title="t('Right-size your servers')"
                :description="t('From the last :days days of CPU and memory: the load the busiest 5% of the time needs, with headroom to spare. Resize in your provider’s dashboard; plan for a short restart.', { days: data.rightsizingDays })"
                :padded="false"
            >
                <ul class="divide-y divide-line text-sm">
                    <li v-for="row in data.rightsizing" :key="row.server.id" class="flex flex-wrap items-center gap-3 px-5 py-3.5 sm:px-6">
                        <NuxtLink :to="`/projects/${project.id}/infrastructure/servers/${row.server.id}`" class="w-24 truncate font-medium text-ink hover:underline">{{ row.server.name }}</NuxtLink>
                        <span class="text-muted"><span class="font-mono">{{ row.current.id }}</span> · {{ t(':cpu vCPU, :memory GB', { cpu: row.current.vcpus, memory: row.current.memory_gb }) }}</span>
                        <AcmeIcon name="arrowRight" :size="13" class="text-muted" />
                        <b class="font-medium text-ink"><span class="font-mono">{{ row.suggested.id }}</span> · {{ t(':cpu vCPU, :memory GB', { cpu: row.suggested.vcpus, memory: row.suggested.memory_gb }) }}</b>
                        <AcmeBadge :tone="row.direction === 'up' ? 'amber' : 'green'">{{ row.direction === 'up' ? t('Bigger') : t('Smaller') }}</AcmeBadge>
                        <span class="ml-auto text-xs text-muted">
                            {{ t('Busiest 5%: CPU :cpu%, memory :memory%', { cpu: row.cpu, memory: row.memory }) }}
                            <template v-if="row.saving !== null"> · {{ row.saving >= 0 ? t('saves :amount', { amount: money.amount(row.saving, row.currency) }) : t('costs :amount more', { amount: money.amount(-row.saving, row.currency) }) }}</template>
                        </span>
                    </li>
                </ul>
            </AcmeCard>

            <AcmeCard v-if="data.canManage && imported.length > 0" :title="t('Imported servers’ prices')" :description="t('Enter what an imported server costs, so it counts in the estimate.')">
                <div class="grid gap-4">
                    <ApiForm v-for="row in imported" :key="row.serverId" :action="`${base}/servers/${row.serverId}`" method="PUT" class="grid items-end gap-3 sm:grid-cols-[1fr_10rem_7rem_auto]">
                        <p class="text-sm font-medium text-ink">{{ row.server }}</p>
                        <InputField :id="`cost-${row.serverId}`" name="monthly_cost" type="number" step="0.01" min="0" :label="t('Per month')" :model-value="row.enteredCost === null ? '' : String(row.enteredCost)" />
                        <SelectField :id="`currency-${row.serverId}`" name="monthly_cost_currency" :label="t('Currency')" :options="currencies" :model-value="row.currency" />
                        <SubmitButton variant="secondary">{{ t('Save') }}</SubmitButton>
                    </ApiForm>
                </div>
            </AcmeCard>

            <AcmeCard v-if="data.canBudget" :title="t('Monthly budget')" :description="t('In US dollars, compared with the servers priced in dollars. Leave it empty for no budget.')">
                <ApiForm :action="`${base}/budget`" method="PUT" class="flex flex-wrap items-end gap-3">
                    <InputField id="budget" name="monthly_infrastructure_budget" type="number" step="0.01" min="0" :label="t('Budget (USD)')" :model-value="data.budget === null ? '' : String(data.budget)" />
                    <SubmitButton>{{ t('Save budget') }}</SubmitButton>
                </ApiForm>
            </AcmeCard>
            <AcmeAlert v-else-if="data.canManage" tone="info">{{ t('Budgets come with the Pro Deploy plan and above.') }}</AcmeAlert>
        </div>
    </div>
</template>
