<script setup lang="ts">
/** What a client receives on the 1st: each project's uptime, incidents, releases, visitors and cost with the markup. */
definePageMeta({ layout: 'app', area: 'account' });
const { t, number, locale } = useT();
const route = useRoute();
type Row = { name: string; uptime: number | null; incidents: number; deploys: number; visitors: number | null; cost: Record<string, number> };
const { data } = await useApi<{ account: { id: string; name: string }; client: { id: number; name: string; markupPercent: number }; report: { month: string; label: string; currency: string; projects: Row[]; total: Record<string, number> } }>(
    () => `/account/clients/${route.params.client}/report`,
    () => ({ month: typeof route.query.month === 'string' ? route.query.month : undefined }),
);

/** Amounts in one or more currencies, such as "$103.50 + €12.00". */
function money(amounts: Record<string, number>): string {
    const entries = Object.entries(amounts);
    return entries.length === 0 ? '—' : entries.map(([currency, amount]) => new Intl.NumberFormat(locale.value, { style: 'currency', currency }).format(amount)).join(' + ');
}
</script>

<template>
    <div class="space-y-6">
        <PageHeader
            :eyebrow="data.account.name"
            :title="t(':client: :month', { client: data.client.name, month: data.report.label })"
            :description="t('What :client receives on the 1st. Costs use your current plans and server prices, with your :percent% markup.', { client: data.client.name, percent: data.client.markupPercent })"
            :breadcrumbs="[{ label: t('Clients'), to: '/account/clients' }]"
        />
        <DataTable :caption="t('Projects')">
            <template #head>
                <tr>
                    <th scope="col">{{ t('Project') }}</th>
                    <th scope="col" class="text-right">{{ t('Uptime') }}</th>
                    <th scope="col" class="text-right">{{ t('Incidents') }}</th>
                    <th scope="col" class="text-right">{{ t('Releases') }}</th>
                    <th scope="col" class="text-right">{{ t('Visitors') }}</th>
                    <th scope="col" class="text-right">{{ t('Cost a month') }}</th>
                </tr>
            </template>
            <tr v-for="project in data.report.projects" :key="project.name">
                <td class="font-bold text-ink">{{ project.name }}</td>
                <td class="text-right tabular-nums">{{ project.uptime === null ? '—' : `${project.uptime.toFixed(2)}%` }}</td>
                <td class="text-right tabular-nums">{{ project.incidents }}</td>
                <td class="text-right tabular-nums">{{ project.deploys }}</td>
                <td class="text-right tabular-nums">{{ project.visitors === null ? '—' : number(project.visitors) }}</td>
                <td class="text-right tabular-nums">{{ money(project.cost) }}</td>
            </tr>
            <tr>
                <td class="font-bold text-ink">{{ t('Total') }}</td>
                <td colspan="4" />
                <td class="text-right font-bold tabular-nums">{{ money(data.report.total) }}</td>
            </tr>
        </DataTable>
    </div>
</template>
