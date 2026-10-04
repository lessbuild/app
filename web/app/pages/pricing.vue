<script setup lang="ts">
import type { PageMeta } from '~/types/site';

/** Every service's tiers, add-ons and usage pricing from the catalogue, monthly or yearly, and the comparison pages. */
definePageMeta({ layout: 'public' });
type Tier = { key: string; name: string; monthlyCents: number | null; yearlyCents: number | null; description: string; features: string[] };
type PricingPage = {
    meta: PageMeta;
    trialDays: number;
    services: Array<{ key: string; name: string; tagline: string; tiers: Tier[]; addOns: Array<{ name: string; monthlyCentsPerUnit: number | null; description: string }>; meters: string[] }>;
    competitors: Array<{ slug: string; name: string }>;
};
const { t } = useT();
const route = useRoute();
const { data } = await useApi<PricingPage>('/site/pricing');
usePublicPage(() => data.value.meta);
const yearly = computed(() => route.query.billing === 'yearly');
/** Format US cents as a price: free, a whole number of dollars where it is one, or "Contact us" when unpriced. */
const money = (cents: number | null) => (cents === null ? t('Contact us') : cents === 0 ? t('Free') : `$${(cents / 100).toFixed(cents % 100 === 0 ? 0 : 2)}`);
</script>

<template>
    <div>
        <SiteHero :kicker="t('Pricing')" :title="t('Pricing')" :description="t('Each service has its own tiers, all on one bill. Start free, change tiers any time, and pay monthly or yearly in US dollars.')" centered>
            <nav class="mx-auto mt-8 inline-flex rounded-lg border border-line bg-surface p-1 text-sm font-medium" :aria-label="t('Billing period')">
                <NuxtLink :to="{ query: {} }" :class="['rounded-md px-4 py-1.5', !yearly ? 'bg-[var(--acme-night)] text-white' : 'text-muted hover:text-ink']" :aria-current="!yearly ? 'page' : undefined">{{ t('Monthly') }}</NuxtLink>
                <NuxtLink :to="{ query: { billing: 'yearly' } }" :class="['rounded-md px-4 py-1.5', yearly ? 'bg-[var(--acme-night)] text-white' : 'text-muted hover:text-ink']" :aria-current="yearly ? 'page' : undefined">{{ t('Yearly · 2 months free') }}</NuxtLink>
            </nav>
            <p v-if="data.trialDays > 0" class="mt-4"><Badge tone="success">{{ t('Your first paid plan is free for :days days', { days: data.trialDays }) }}</Badge></p>
        </SiteHero>

        <SiteSection v-for="(service, index) in data.services" :id="service.key" :key="service.key" :tint="index % 2 === 1" class="scroll-mt-16" :labelledby="`${service.key}-heading`">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p class="site-kicker">{{ String(index + 1).padStart(2, '0') }}</p>
                    <h2 :id="`${service.key}-heading`" class="site-h2 mt-2">{{ service.name }}</h2>
                </div>
                <p class="max-w-md text-sm text-muted">{{ service.tagline }}</p>
            </div>
            <div class="mt-10 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <article v-for="tier in service.tiers" :key="tier.key" class="site-card grid content-start gap-3 p-6">
                    <h3 class="font-semibold text-ink">{{ tier.name }}</h3>
                    <p>
                        <span class="text-3xl font-semibold tracking-tight text-ink">{{ money(yearly ? tier.yearlyCents : tier.monthlyCents) }}</span>
                        <template v-if="(tier.monthlyCents ?? 0) > 0">
                            <span class="text-sm text-muted"> {{ yearly ? t('/ year') : t('/ month') }}</span>
                            <span v-if="yearly" class="block text-xs text-muted">{{ t(':monthly a month, billed yearly', { monthly: money(Math.round((tier.yearlyCents ?? 0) / 12)) }) }}</span>
                        </template>
                    </p>
                    <p class="text-sm text-muted">{{ tier.description }}</p>
                    <ul v-if="tier.features.length > 0" class="grid gap-1.5 border-t border-line pt-4 text-sm text-ink">
                        <li v-for="feature in tier.features" :key="feature" class="flex gap-2"><Icon name="check" class="mt-0.5 size-4 shrink-0 text-primary" /><span>{{ feature }}</span></li>
                    </ul>
                </article>
            </div>
            <ul v-if="service.addOns.length > 0 || service.meters.length > 0" class="mt-6 grid gap-1 text-sm text-muted">
                <li v-for="addOn in service.addOns" :key="addOn.name">{{ t('Add-on: :name, :price each per month.', { name: addOn.name, price: money(addOn.monthlyCentsPerUnit) }) }} {{ addOn.description }}</li>
                <li v-for="meter in service.meters" :key="meter">{{ t(':name beyond your tier’s allowance are billed by usage.', { name: meter }) }}</li>
            </ul>
        </SiteSection>

        <SiteSection pad="md" labelledby="compare-heading">
            <h2 id="compare-heading" class="text-lg font-semibold text-ink">{{ t('Coming from another tool?') }}</h2>
            <ul class="mt-4 flex flex-wrap gap-2">
                <li v-for="competitor in data.competitors" :key="competitor.slug"><NuxtLink :to="`/compare/${competitor.slug}`" class="site-btn-2 site-btn-sm">{{ t(':app vs :other', { app: 'BuildPusher', other: competitor.name }) }}</NuxtLink></li>
            </ul>
        </SiteSection>

        <SiteCta :kicker="t('Start free')" :title="t('Every service has a free tier. Upgrade the ones that grow.')" :text="t('No card to start. Each service has its own plan on one monthly bill, and you can change or cancel any of them whenever you like.')">
            <template #actions><NuxtLink to="/register" class="site-btn-light">{{ t('Create your account') }}</NuxtLink></template>
        </SiteCta>
    </div>
</template>
