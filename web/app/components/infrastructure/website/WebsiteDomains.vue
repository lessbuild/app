<script setup lang="ts">
import type { WebsitePage } from '~/types/infrastructure';

/** A website's domains: aliases and redirects, DNS and certificates, and Cloudflare's CDN and firewall for them. */
defineProps<{ page: WebsitePage; base: string }>();
const { t, date } = useT();
const types = computed(() => [{ value: 'alias', label: t('Alias') }, { value: 'redirect', label: t('Redirect') }]);
const domainTypes = computed<Record<string, string>>(() => ({ primary: t('Primary'), alias: t('Alias'), redirect: t('Redirect') }));
const states = computed<Record<string, string>>(() => ({ pending: t('pending'), active: t('active'), expiring: t('expiring'), expired: t('expired'), error: t('error') }));
const dnsProvider = ref<string | null>('');
</script>

<template>
    <AcmeCard :padded="false" :title="t('Domains')" :description="t('Aliases serve the website too; redirects send visitors elsewhere. The primary domain changes with the website’s domain setting.')">
        <div class="grid gap-4 px-5 pb-5 sm:px-6 sm:pb-6">
            <ul class="divide-y divide-line">
                <li v-for="domain in page.domains" :key="domain.id" class="flex flex-wrap items-center justify-between gap-3 py-3">
                    <div class="min-w-0">
                        <p class="font-bold text-ink">
                            {{ domain.hostname }}
                            <span class="text-xs font-normal text-muted">
                                {{ domainTypes[domain.type] ?? domain.type }}<template v-if="domain.redirectUrl"> → {{ domain.redirectUrl }}</template><template v-if="domain.temporary"> · {{ t('temporary') }}</template>
                            </span>
                        </p>
                        <p class="text-xs text-muted">
                            {{ t('DNS :dns · certificate :ssl', { dns: states[domain.dnsStatus] ?? domain.dnsStatus, ssl: states[domain.sslStatus] ?? domain.sslStatus }) }}
                            <template v-if="domain.certificateExpiresAt"> · {{ t('expires :date', { date: date(domain.certificateExpiresAt) }) }}</template>
                            <template v-if="domain.dnsProvider"> · {{ domain.dnsProvider }}</template>
                        </p>
                        <p v-if="domain.edgeError" class="text-xs text-danger">{{ domain.edgeError }}</p>
                    </div>
                    <div v-if="page.canManage" class="flex flex-wrap gap-1">
                        <FormDialog
                            v-if="domain.edge"
                            :id="`edge-${domain.id}`"
                            :title="t('CDN and firewall for :domain', { domain: domain.hostname })"
                            :description="t('Set at Cloudflare, which manages this domain’s DNS. Rules you made yourself in Cloudflare are left alone.')"
                            :action="`${base}/domains/${domain.id}/edge`"
                            method="PUT"
                            :submit="t('Save')"
                        >
                            <template #trigger="{ open }"><AcmeBtn variant="ghost" size="sm" @click="open">{{ domain.edge.proxied ? t('CDN and firewall · on') : t('CDN and firewall') }}</AcmeBtn></template>
                            <div class="grid gap-5">
                                <CheckboxField
                                    :id="`edge-${domain.id}-cdn`"
                                    name="cdn_proxied"
                                    :label="t('Serve through Cloudflare’s CDN')"
                                    :checked="domain.edge.proxied"
                                    :description="t('Visitors reach the site through Cloudflare’s network: static files are cached near them, attacks are absorbed, and the cache is purged after every deploy. Set Cloudflare’s SSL mode to Full (strict).')"
                                />
                                <InputField :id="`edge-${domain.id}-countries`" name="blocked_countries" :label="t('Block countries')" :model-value="domain.edge.blockedCountries" placeholder="KP, RU" :description="t('Two-letter country codes.')" />
                                <TextareaField :id="`edge-${domain.id}-ips`" name="blocked_ips" :label="t('Block addresses')" :model-value="domain.edge.blockedIps" rows="3" :placeholder="'203.0.113.7\n198.51.100.0/24'" :description="t('One IP address or network a line.')" />
                                <InputField
                                    :id="`edge-${domain.id}-rate`"
                                    name="rate_limit_requests"
                                    type="number"
                                    min="1"
                                    max="10000"
                                    :label="t('Rate limit (requests per visitor per 10 seconds)')"
                                    :model-value="domain.edge.rateLimit === null ? '' : String(domain.edge.rateLimit)"
                                    :description="t('Visitors over it are blocked for 10 seconds. Leave empty for no limit.')"
                                />
                            </div>
                        </FormDialog>
                        <template v-if="domain.type !== 'primary'">
                            <ApiForm v-if="domain.canSync" :action="`${base}/domains/${domain.id}/sync`"><SubmitButton variant="quiet" size="sm">{{ t('Update DNS') }}</SubmitButton></ApiForm>
                            <ApiForm :action="`${base}/domains/${domain.id}`" method="DELETE" :confirm="t('Remove :name?', { name: domain.hostname })"><SubmitButton variant="quiet" size="sm">{{ t('Remove') }}</SubmitButton></ApiForm>
                        </template>
                    </div>
                </li>
            </ul>
            <div v-if="page.canManage" class="flex flex-wrap items-center gap-3">
                <FormDialog
                    id="add-domain"
                    :title="t('Add a domain to :website', { website: page.website.name })"
                    :description="t('An alias serves the website; a redirect sends visitors elsewhere. A certificate is issued once DNS points here.')"
                    :action="`${base}/domains`"
                    :submit="t('Add domain')"
                    size="wide"
                >
                    <template #trigger="{ open }"><AcmeBtn icon="plus" @click="open">{{ t('Add a domain') }}</AcmeBtn></template>
                    <div class="grid items-start gap-4 sm:grid-cols-2">
                        <InputField name="hostname" :label="t('Hostname')" placeholder="www.example.com" maxlength="255" required autofocus />
                        <SelectField name="type" :label="t('Type')" :options="types" />
                        <InputField name="redirect_url" :label="t('Redirect to (redirects)')" placeholder="https://example.com" maxlength="2048" />
                        <SelectField v-model="dnsProvider" name="dns_provider_id" :label="t('Manage DNS with')" :placeholder="t('I’ll point DNS myself')" :options="page.dnsProviders" />
                    </div>
                </FormDialog>
                <ApiForm v-if="page.temporaryDomains && page.dnsProviders.length > 0" :action="`${base}/domains/temporary`">
                    <input type="hidden" name="dns_provider_id" :value="page.dnsProviders[0]?.value">
                    <SubmitButton variant="quiet" size="sm">{{ t('Get a temporary domain') }}</SubmitButton>
                </ApiForm>
            </div>
        </div>
    </AcmeCard>
</template>
