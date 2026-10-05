<script setup lang="ts">
import type { ProviderSummary, ProviderType } from '~/types/providers';

/**
 * The account's providers: clouds that host servers, Cloudflare for DNS, and Git hosts, each with its connection. Where
 * the GitHub App is set up, installing it connects GitHub without a personal token.
 */
definePageMeta({ layout: 'app', area: 'account', dialogs: ['add-provider'] });
const { t, tc } = useT();
const route = useRoute();
const { data } = await useApi<{ account: { id: string; name: string }; providers: ProviderSummary[]; types: ProviderType[]; githubApp: boolean }>('/account/providers');
// A link from the setup guide can ask to go back to it afterwards (`?return=`).
const back = computed(() => (typeof route.query.return === 'string' ? route.query.return : ''));
</script>

<template>
    <div class="space-y-8">
        <PageHeader :eyebrow="data.account.name" :title="t('Providers')" :description="t('Credentials for the clouds that host your servers, Cloudflare for DNS, and the Git hosts Deploy builds from.')">
            <template v-if="data.providers.length > 0" #actions>
                <a href="/api/app/account/inventory/providers.csv" class="ui-btn ui-btn-quiet" download>{{ t('Export CSV') }}</a>
                <a v-if="data.githubApp" href="/github-app/connect" class="ui-btn ui-btn-secondary">{{ t('Install the GitHub App') }}</a>
                <AcmeBtn variant="primary" :to="{ query: { dialog: 'add-provider' } }" icon="plus">{{ t('Connect a provider') }}</AcmeBtn>
            </template>
        </PageHeader>

        <EmptyState v-if="data.providers.length === 0" icon="server" :title="t('No providers yet')" :description="t('Connect DigitalOcean, Hetzner Cloud or Vultr to create servers.')">
            <template #action>
                <AcmeBtn variant="primary" :to="{ query: { dialog: 'add-provider' } }">{{ t('Connect a provider') }}</AcmeBtn>
                <a v-if="data.githubApp" href="/github-app/connect" class="ui-btn ui-btn-secondary">{{ t('Install the GitHub App') }}</a>
            </template>
        </EmptyState>
        <ul v-else class="ui-card divide-y divide-line overflow-hidden" :aria-label="t('Providers')">
            <li v-for="provider in data.providers" :key="provider.id" class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                <div class="min-w-0">
                    <NuxtLink :to="`/account/providers/${provider.id}`" class="font-semibold text-ink hover:underline">{{ provider.name }}</NuxtLink>
                    <p class="mt-0.5 text-xs text-muted">
                        {{ provider.typeLabel }} · {{ provider.purpose }}<template v-if="provider.hostsServers"> · {{ tc(':count server|:count servers', provider.serverCount) }}</template>
                    </p>
                </div>
                <ProviderHealth :status="provider.status" />
            </li>
        </ul>

        <AddProviderDialog :types="data.types" :back="back" />
    </div>
</template>
