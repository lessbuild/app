<script setup lang="ts">
import type { SitePage } from '~/types/analytics';
import type { Option } from '~/types/ui';

/**
 * A site's campaigns: build tagged links, see which campaigns bring visitors who convert, and bring in what the ads
 * cost (a CSV import or a connected Google Ads or Meta account) for cost per conversion and return on ad spend.
 */
definePageMeta({ layout: 'app', service: 'analytics' });
type CampaignsPage = SitePage & {
    builder: { url: string; parameters: Array<{ key: string; label: string; value: string | null }>; link: string | null; invalid: boolean };
    results: Array<{ campaign: string; source: string | null; medium: string | null; visits: number; pageviews: number; converted: number; rate: number; revenue: string | null; cost: string | null; costPerConversion: string | null; roas: number | null }>;
    spend: Array<{ source: string; days: number; from: string; until: string }>;
    adAccounts: Array<{ id: number; name: string; platform: string; source: string; syncedAt: string | null; error: string | null }>;
    adPlatforms: Array<{ key: string; name: string }>;
    adChoice: Option[] | null;
};
const PARAMETERS = ['url', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
const { t, tc, number } = useT();
const route = useRoute();
const text = (value: unknown) => (typeof value === 'string' && value !== '' ? value : undefined);
const { data } = await useApi<CampaignsPage>(() => `/projects/${route.params.project}/analytics/campaigns`, () => Object.fromEntries(['site', ...PARAMETERS].map((key) => [key, text(route.query[key])])));
const project = computed(() => data.value.overview.project);
const site = computed(() => data.value.site);
const base = computed(() => `/api/app/projects/${project.value.id}/analytics/sites/${site.value?.id}`);
const builder = reactive<Record<string, string>>({ url: data.value.builder.url, ...Object.fromEntries(data.value.builder.parameters.map((parameter) => [parameter.key, parameter.value ?? ''])) });
const placeholders: Record<string, string> = { utm_source: 'newsletter', utm_medium: 'email', utm_campaign: 'autumn-launch' };
const hasSpend = computed(() => data.value.spend.length > 0);
const notice = computed(() => (typeof route.query.error === 'string' ? { tone: 'danger' as const, text: route.query.error } : typeof route.query.notice === 'string' ? { tone: 'success' as const, text: route.query.notice } : null));

/** Build the tagged link (the API checks the address and adds the parameters). */
function build() {
    navigateTo({ query: { site: site.value ? String(site.value.id) : undefined, ...Object.fromEntries(Object.entries(builder).map(([key, value]) => [key, value || undefined])) }, hash: '#builder' });
}
/** Off to the ad platform to connect an account: the API answers with the address to go to. */
const away = (result: Record<string, unknown>) => (typeof result.redirect === 'string' ? result.redirect : null);
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Campaigns')" :description="t('Tag the links you share with UTM parameters, then see which campaigns bring visitors who convert.')" />
        <EmptyState v-if="!site" icon="view-grid" :title="t('Add a site first')" :description="t('Campaign results belong to a site.')" />
        <template v-else>
            <SitePicker :sites="data.sites" :site="site" />
            <AcmeAlert v-if="notice" :tone="notice.tone" :role="notice.tone === 'danger' ? 'alert' : 'status'">{{ notice.text }}</AcmeAlert>

            <AcmeCard id="builder" :padded="false" :title="t('Campaign link builder')" :description="t('Source is required by most tools; campaign names what you’re promoting. Values show up exactly as typed, so keep them consistent (e.g. newsletter, not Newsletter).')">
                <div class="ui-card grid gap-4 px-5 pb-5 sm:px-6 sm:pb-6">
                    <form class="grid items-start gap-4 sm:grid-cols-2" @submit.prevent="build">
                        <div class="sm:col-span-2"><InputField v-model="builder.url" name="url" type="url" :label="t('Page address')" maxlength="2048" required /></div>
                        <InputField
                            v-for="parameter in data.builder.parameters"
                            :key="parameter.key"
                            v-model="builder[parameter.key]"
                            :name="parameter.key"
                            :label="`${parameter.label} (${parameter.key})`"
                            maxlength="200"
                            :placeholder="placeholders[parameter.key] ?? (parameter.key === 'utm_term' ? t('paid keyword') : t('which link or ad'))"
                        />
                        <div class="sm:col-span-2"><AcmeBtn type="submit" variant="primary">{{ t('Build link') }}</AcmeBtn></div>
                    </form>
                    <AcmeAlert v-if="data.builder.invalid" tone="danger">{{ t('Enter a full http:// or https:// address.') }}</AcmeAlert>
                    <div v-else-if="data.builder.link" class="grid gap-2">
                        <p class="text-xs font-bold text-muted">{{ t('Your link') }}</p>
                        <CodeBlock :code="data.builder.link" class="whitespace-pre-wrap break-all" />
                    </div>
                </div>
            </AcmeCard>

            <AcmeCard :padded="false" :title="t('Campaign results')" :description="t('Visits that arrived through a tagged link in the last 30 days.')">
                <p v-if="data.results.length === 0" class="ui-card p-4 text-sm text-muted sm:p-6">{{ t('No tagged visits yet. Share a link from the builder above.') }}</p>
                <DataTable v-else :caption="t('Campaign results')">
                    <template #head>
                        <tr>
                            <th scope="col">{{ t('Campaign') }}</th><th scope="col">{{ t('Source / medium') }}</th><th scope="col" class="text-right">{{ t('Visits') }}</th>
                            <th scope="col" class="text-right">{{ t('Pageviews') }}</th><th scope="col" class="text-right">{{ t('Converted') }}</th><th scope="col" class="text-right">{{ t('Revenue') }}</th>
                            <template v-if="hasSpend">
                                <th scope="col" class="text-right">{{ t('Cost') }}</th><th scope="col" class="text-right">{{ t('Cost per conversion') }}</th>
                                <th scope="col" class="text-right"><abbr :title="t('Return on ad spend: revenue divided by cost')">{{ t('ROAS') }}</abbr></th>
                            </template>
                        </tr>
                    </template>
                    <tr v-for="row in data.results" :key="`${row.campaign}-${row.source}-${row.medium}`">
                        <td><NuxtLink :to="`/projects/${project.id}/analytics?site=${site.id}&days=30&campaign=${encodeURIComponent(row.campaign)}`" class="font-bold text-primary hover:underline">{{ row.campaign }}</NuxtLink></td>
                        <td class="text-muted">{{ row.source ?? '—' }}{{ row.medium ? ` / ${row.medium}` : '' }}</td>
                        <td class="text-right tabular-nums">{{ number(row.visits) }}</td>
                        <td class="text-right tabular-nums">{{ number(row.pageviews) }}</td>
                        <td class="text-right tabular-nums">{{ number(row.converted) }} <span class="text-muted">({{ row.rate }}%)</span></td>
                        <td class="text-right tabular-nums">{{ row.revenue ?? '—' }}</td>
                        <template v-if="hasSpend">
                            <td class="text-right tabular-nums">{{ row.cost ?? '—' }}</td>
                            <td class="text-right tabular-nums">{{ row.costPerConversion ?? '—' }}</td>
                            <td class="text-right tabular-nums">{{ row.roas === null ? '—' : `${row.roas.toFixed(2)}×` }}</td>
                        </template>
                    </tr>
                </DataTable>
            </AcmeCard>

            <AcmeCard id="ad-spend" :padded="false" :title="t('Ad spend')" :description="t('Import what your ads cost to see cost per conversion and return on ad spend beside each campaign. Export a daily campaign report from Google Ads, Meta, LinkedIn or any ad platform as CSV, with date, campaign and cost columns. Campaign and source must match the utm_campaign and utm_source on your ads’ links.')">
                <div class="ui-card grid gap-4 px-5 pb-5 sm:px-6 sm:pb-6">
                    <ul v-if="data.spend.length > 0" class="divide-y divide-line">
                        <li v-for="row in data.spend" :key="row.source" class="flex flex-wrap items-center justify-between gap-3 py-2 text-sm">
                            <span><span class="font-bold text-ink">{{ row.source }}</span> <span class="text-muted">· {{ tc(':count campaign day|:count campaign days', row.days, { count: number(row.days) }) }} · {{ row.from }} – {{ row.until }}</span></span>
                            <DeleteDialog v-if="data.canManage" :id="`spend-${row.source}`" :title="t('Remove :source’s spend?', { source: row.source })" :action="`${base}/ad-spend`" :submit-label="t('Remove')">
                                <template #trigger="{ open }"><AcmeBtn variant="ghost" size="sm" @click="open">{{ t('Remove') }}</AcmeBtn></template>
                                <input type="hidden" name="source" :value="row.source">
                            </DeleteDialog>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-muted">{{ t('No ad spend imported yet.') }}</p>
                    <ul v-if="data.adAccounts.length > 0" class="divide-y divide-line border-t border-line">
                        <li v-for="account in data.adAccounts" :key="account.id" class="flex flex-wrap items-center justify-between gap-3 py-2 text-sm">
                            <span>
                                <span class="font-bold text-ink">{{ account.name }}</span>
                                <span class="text-muted">
                                    · {{ account.platform }} · {{ t('filed under :source', { source: account.source }) }} ·
                                    <Rich v-if="account.syncedAt" :text="t('read :time')"><template #time><RelativeTime :at="account.syncedAt" /></template></Rich><template v-else>{{ t('not read yet') }}</template>
                                </span>
                                <span v-if="account.error" class="block text-xs text-danger">{{ account.error }}</span>
                            </span>
                            <span v-if="data.canManage" class="flex gap-1">
                                <ApiForm :action="`${base}/ads/${account.id}/sync`"><SubmitButton variant="secondary" size="sm">{{ t('Read now') }}</SubmitButton></ApiForm>
                                <DeleteDialog :id="`ad-account-${account.id}`" :title="t('Disconnect :account?', { account: account.name })" :description="t('Disconnected. Spend already read stays until you remove it.')" :action="`${base}/ads/${account.id}`" :submit-label="t('Disconnect')">
                                    <template #trigger="{ open }"><AcmeBtn variant="ghost" size="sm" @click="open">{{ t('Disconnect') }}</AcmeBtn></template>
                                </DeleteDialog>
                            </span>
                        </li>
                    </ul>
                    <ApiForm v-if="data.canManage && data.adChoice" :action="`${base}/ads`" class="flex flex-wrap items-end gap-3 border-t border-line pt-3">
                        <SelectField id="ad-account" name="account_id" :label="t('Ad account')" :options="data.adChoice" />
                        <SubmitButton>{{ t('Connect this account') }}</SubmitButton>
                    </ApiForm>
                    <div v-if="data.canManage" class="flex flex-wrap items-center gap-2 border-t border-line pt-3">
                        <FormDialog id="import-spend" :title="t('Import ad spend')" :description="t('A CSV with date, campaign and cost columns.')" :action="`${base}/ad-spend`" :submit="t('Import')">
                            <template #trigger="{ open }"><AcmeBtn variant="secondary" size="sm" icon="cloud-upload" @click="open">{{ t('Import a CSV') }}</AcmeBtn></template>
                            <InputField id="spend-file" name="file" type="file" accept=".csv,.tsv,text/csv" :label="t('CSV file')" required />
                            <div class="grid gap-4 sm:grid-cols-2">
                                <InputField id="spend-source" name="source" :label="t('Source (when the file has none)')" maxlength="100" required model-value="google" />
                                <InputField id="spend-currency" name="currency" :label="t('Currency (when the file has none)')" maxlength="3" required model-value="USD" />
                            </div>
                        </FormDialog>
                        <template v-if="data.adPlatforms.length > 0">
                            <span class="text-sm text-muted">{{ t('Read spend daily, straight from the platform:') }}</span>
                            <ApiForm v-for="platform in data.adPlatforms" :key="platform.key" :action="`${base}/ads/${platform.key}/connect`" :after="away">
                                <SubmitButton variant="secondary" size="sm">{{ t('Connect :platform', { platform: platform.name }) }}</SubmitButton>
                            </ApiForm>
                        </template>
                    </div>
                </div>
            </AcmeCard>
        </template>
    </div>
</template>
