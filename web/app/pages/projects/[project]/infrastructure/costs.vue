<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';

/** What the account's servers cost a month, the providers' actual bills, right-sizing and the monthly budget. */
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
    rightsizing: Array<{ server: { id: number; name: string }; direction: string; cpu: number; memory: number; current: Sizing; suggested: Sizing; saving: number | null }>;
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
const currencies = [{ value: 'USD', label: 'USD' }, { value: 'EUR', label: 'EUR' }];
const imported = computed(() => data.value.rows.filter((row) => row.imported));
/** Who a server's cost belongs to: one project, several, or none. */
const usedBy = (row: Row) => (row.attribution === 'direct' ? row.projects[0] : row.attribution === 'shared' ? t('Shared: :projects', { projects: row.projects.join(', ') }) : t('No project'));
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Costs')" :description="t('What :account’s servers cost a month, from each provider’s price list, and which are idle.', { account: data.accountName })" />

        <div class="grid gap-4 sm:grid-cols-3">
            <StatCard
                :label="t('Estimated per month')"
                :value="money.amounts(data.totals)"
                :description="data.unknown > 0 ? tc(':count server has no known price|:count servers have no known price', data.unknown, { count: data.unknown }) : null"
            />
            <StatCard :label="t('Budget')" :value="data.budget === null ? t('Not set') : money.amount(data.budget, 'USD')" :description="budgetLine" />
            <StatCard :label="t('Idle servers')" :value="number(data.idle)" :description="t('No websites, or under 10% CPU for the last hour')" />
        </div>
        <Alert v-if="data.budget !== null && usd > data.budget" tone="warning">{{ t('Servers priced in US dollars cost more than the monthly budget.') }}</Alert>

        <EmptyState v-if="data.rows.length === 0" icon="server" :title="t('No servers yet')" :description="t('Costs appear once the account has servers.')" />
        <template v-else>
            <DataTable :caption="t('Server costs')">
                <template #head>
                    <tr><th scope="col">{{ t('Server') }}</th><th scope="col">{{ t('Size') }}</th><th scope="col">{{ t('Used by') }}</th><th scope="col">{{ t('CPU (1 h)') }}</th><th scope="col" class="text-right">{{ t('Per month') }}</th></tr>
                </template>
                <tr v-for="row in data.rows" :key="row.serverId">
                    <td>
                        <NuxtLink :to="`/projects/${project.id}/infrastructure/servers/${row.serverId}`" class="font-bold text-primary hover:underline">{{ row.server }}</NuxtLink>
                        <Badge v-if="row.idle" tone="warning" class="ml-2">{{ t('Idle') }}</Badge>
                    </td>
                    <td class="text-muted">{{ row.provider ?? t('Imported') }}<template v-if="row.size"> · <span class="font-mono text-xs">{{ row.size }}</span></template></td>
                    <td class="text-muted">{{ tc(':count website|:count websites', row.websites, { count: row.websites }) }} · {{ usedBy(row) }}</td>
                    <td>{{ row.averageCpu === null ? '—' : `${row.averageCpu}%` }}</td>
                    <td class="text-right font-bold">
                        {{ row.monthly === null ? '—' : money.amount(row.monthly, row.currency) }}
                        <span v-if="row.entered" class="text-xs font-normal text-muted">{{ t('entered') }}</span>
                    </td>
                </tr>
            </DataTable>
            <p class="text-xs text-muted">{{ t('Prices are list prices from each provider (Hetzner in euros, including VAT), checked daily; bandwidth, backups and taxes aren’t included.') }}</p>
        </template>

        <SettingsSection
            v-if="data.bills.length > 0"
            id="bills"
            :title="t('Actual bills')"
            :description="t('What each provider charged, read daily from its billing API, next to the estimate from list prices. Hetzner has no billing API, so it isn’t shown.')"
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
                        <span class="font-bold text-ink">{{ bill.provider }}</span> <span class="text-muted">· {{ bill.type }}</span>
                        <p v-if="bill.error" class="text-xs text-danger">{{ bill.error }}</p>
                        <p v-else-if="!bill.checked" class="text-xs text-muted">{{ t('Not read yet') }}</p>
                    </td>
                    <td class="text-right tabular-nums">{{ bill.estimate === null ? '—' : money.amount(bill.estimate, 'USD') }}</td>
                    <td class="text-right tabular-nums">{{ bill.previous === null ? '—' : money.amount(bill.previous.amount, bill.previous.currency) }}</td>
                    <td class="text-right tabular-nums">
                        <template v-if="bill.difference === null">—</template>
                        <template v-else-if="Math.abs(bill.difference) < 0.01">{{ t('Matches') }}</template>
                        <Badge v-else :tone="bill.difference > 0 ? 'warning' : 'success'">
                            {{ bill.difference > 0 ? t(':amount more', { amount: money.amount(bill.difference, 'USD') }) : t(':amount less', { amount: money.amount(-bill.difference, 'USD') }) }}
                        </Badge>
                    </td>
                    <td class="text-right tabular-nums">{{ bill.current === null ? '—' : money.amount(bill.current.amount, bill.current.currency) }}</td>
                </tr>
            </DataTable>
            <p class="px-4 pb-4 text-xs text-muted sm:px-6">
                {{ t('Invoices include bandwidth, backups, snapshots, volumes and anything else in the provider account, so they’re usually higher than the servers alone. Lightsail and EC2 read AWS Cost Explorer, which needs ce:GetCostAndUsage on the key.') }}
            </p>
        </SettingsSection>

        <SettingsSection v-if="data.canManage" :title="t('Prices')" :description="t('Ask the providers for current prices now, or enter what an imported server costs.')">
            <div class="grid gap-4 p-4 sm:p-6">
                <ApiForm :action="`${base}/refresh`"><SubmitButton variant="secondary" size="sm">{{ t('Check prices and bills now') }}</SubmitButton></ApiForm>
                <ApiForm v-for="row in imported" :key="row.serverId" :action="`${base}/servers/${row.serverId}`" method="PUT" class="grid items-end gap-3 sm:grid-cols-[1fr_10rem_7rem_auto]">
                    <p class="text-sm font-bold text-ink">{{ row.server }}</p>
                    <InputField :id="`cost-${row.serverId}`" name="monthly_cost" type="number" step="0.01" min="0" :label="t('Per month')" :model-value="row.enteredCost === null ? '' : String(row.enteredCost)" />
                    <SelectField :id="`currency-${row.serverId}`" name="monthly_cost_currency" :label="t('Currency')" :options="currencies" :model-value="row.currency" />
                    <SubmitButton variant="secondary">{{ t('Save') }}</SubmitButton>
                </ApiForm>
            </div>
        </SettingsSection>

        <SettingsSection
            v-if="data.rightsizing.length > 0"
            id="rightsizing"
            :title="t('Right-size your servers')"
            :description="t('From the last :days days of CPU and memory: the load the busiest 5% of the time needs, with headroom to spare. Resize in your provider’s dashboard; plan for a short restart.', { days: data.rightsizingDays })"
        >
            <DataTable :caption="t('Suggested sizes')" :framed="false">
                <template #head>
                    <tr><th scope="col">{{ t('Server') }}</th><th scope="col">{{ t('Busiest 5%') }}</th><th scope="col">{{ t('Now') }}</th><th scope="col">{{ t('Suggested') }}</th><th scope="col" class="text-right">{{ t('Per month') }}</th></tr>
                </template>
                <tr v-for="row in data.rightsizing" :key="row.server.id">
                    <td><NuxtLink :to="`/projects/${project.id}/infrastructure/servers/${row.server.id}`" class="font-bold text-primary hover:underline">{{ row.server.name }}</NuxtLink></td>
                    <td class="text-sm">{{ t('CPU :cpu%, memory :memory%', { cpu: row.cpu, memory: row.memory }) }}</td>
                    <td class="text-sm"><span class="font-mono">{{ row.current.id }}</span> <span class="text-muted">· {{ t(':cpu vCPU, :memory GB', { cpu: row.current.vcpus, memory: row.current.memory_gb }) }}</span></td>
                    <td class="text-sm">
                        <Badge :tone="row.direction === 'up' ? 'warning' : 'success'">{{ row.direction === 'up' ? t('Bigger') : t('Smaller') }}</Badge>
                        <span class="font-mono"> {{ row.suggested.id }}</span> <span class="text-muted">· {{ t(':cpu vCPU, :memory GB', { cpu: row.suggested.vcpus, memory: row.suggested.memory_gb }) }}</span>
                    </td>
                    <td class="text-right tabular-nums">
                        {{ row.saving === null ? '—' : row.saving >= 0 ? t('saves :amount', { amount: number(row.saving) }) : t('costs :amount more', { amount: number(-row.saving) }) }}
                    </td>
                </tr>
            </DataTable>
        </SettingsSection>

        <SettingsSection v-if="data.canBudget" :title="t('Monthly budget')" :description="t('In US dollars, compared with the servers priced in dollars. Leave it empty for no budget.')">
            <ApiForm :action="`${base}/budget`" method="PUT" class="flex flex-wrap items-end gap-3 p-4 sm:p-6">
                <InputField id="budget" name="monthly_infrastructure_budget" type="number" step="0.01" min="0" :label="t('Budget (USD)')" :model-value="data.budget === null ? '' : String(data.budget)" />
                <SubmitButton>{{ t('Save budget') }}</SubmitButton>
            </ApiForm>
        </SettingsSection>
        <Alert v-else-if="data.canManage" tone="info">{{ t('Budgets come with the Pro Deploy plan and above.') }}</Alert>
    </div>
</template>
