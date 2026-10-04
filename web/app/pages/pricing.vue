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
    <div class="mx-auto grid max-w-6xl gap-12 px-5 py-12 sm:px-8 sm:py-16">
        <section class="grid gap-3 text-center">
            <h1 class="text-4xl font-extrabold tracking-tight text-ink">{{ t('Pricing') }}</h1>
            <p class="mx-auto max-w-2xl text-lg text-muted">{{ t('Each service has its own tiers, all on one bill. Start free, change tiers any time, and pay monthly or yearly in US dollars.') }}</p>
            <nav class="mx-auto inline-flex rounded-full border border-line bg-surface p-1 text-sm font-bold" :aria-label="t('Billing period')">
                <NuxtLink :to="{ query: {} }" :class="['rounded-full px-4 py-1.5', !yearly ? 'bg-primary-soft text-primary' : 'text-muted hover:text-ink']" :aria-current="!yearly ? 'page' : undefined">{{ t('Monthly') }}</NuxtLink>
                <NuxtLink :to="{ query: { billing: 'yearly' } }" :class="['rounded-full px-4 py-1.5', yearly ? 'bg-primary-soft text-primary' : 'text-muted hover:text-ink']" :aria-current="yearly ? 'page' : undefined">{{ t('Yearly · 2 months free') }}</NuxtLink>
            </nav>
            <p v-if="data.trialDays > 0" class="mx-auto"><Badge tone="success">{{ t('Your first paid plan is free for :days days', { days: data.trialDays }) }}</Badge></p>
        </section>

        <section v-for="service in data.services" :id="service.key" :key="service.key" class="grid scroll-mt-20 gap-4" :aria-labelledby="`${service.key}-heading`">
            <div>
                <h2 :id="`${service.key}-heading`" class="text-2xl font-extrabold text-ink">{{ service.name }}</h2>
                <p class="text-sm text-muted">{{ service.tagline }}</p>
            </div>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <div v-for="tier in service.tiers" :key="tier.key" class="ui-card grid content-start gap-3 p-5">
                    <p class="font-extrabold text-ink">{{ tier.name }}</p>
                    <p>
                        <span class="text-3xl font-extrabold text-ink">{{ money(yearly ? tier.yearlyCents : tier.monthlyCents) }}</span>
                        <template v-if="(tier.monthlyCents ?? 0) > 0">
                            <span class="text-sm text-muted"> {{ yearly ? t('/ year') : t('/ month') }}</span>
                            <span v-if="yearly" class="block text-xs text-muted">{{ t(':monthly a month, billed yearly', { monthly: money(Math.round((tier.yearlyCents ?? 0) / 12)) }) }}</span>
                        </template>
                    </p>
                    <p class="text-sm text-muted">{{ tier.description }}</p>
                    <ul v-if="tier.features.length > 0" class="grid gap-1 text-sm">
                        <li v-for="feature in tier.features" :key="feature" class="flex gap-2"><Icon name="check" class="mt-0.5 size-4 shrink-0 text-success" /><span>{{ feature }}</span></li>
                    </ul>
                </div>
            </div>
            <ul v-if="service.addOns.length > 0 || service.meters.length > 0" class="grid gap-1 text-sm text-muted">
                <li v-for="addOn in service.addOns" :key="addOn.name">{{ t('Add-on: :name, :price each per month.', { name: addOn.name, price: money(addOn.monthlyCentsPerUnit) }) }} {{ addOn.description }}</li>
                <li v-for="meter in service.meters" :key="meter">{{ t(':name beyond your tier’s allowance are billed by usage.', { name: meter }) }}</li>
            </ul>
        </section>

        <section aria-labelledby="compare-heading" class="border-t border-line pt-10">
            <h2 id="compare-heading" class="text-lg font-extrabold text-ink">{{ t('Coming from another tool?') }}</h2>
            <ul class="mt-4 flex flex-wrap gap-2">
                <li v-for="competitor in data.competitors" :key="competitor.slug"><NuxtLink :to="`/compare/${competitor.slug}`" class="ui-chip hover:text-ink">{{ t(':app vs :other', { app: 'BuildPusher', other: competitor.name }) }}</NuxtLink></li>
            </ul>
            <p class="mt-6 text-xs text-subtle"><NuxtLink to="/privacy" class="hover:underline">{{ t('Privacy') }}</NuxtLink> · <NuxtLink to="/terms" class="hover:underline">{{ t('Terms') }}</NuxtLink></p>
        </section>
    </div>
</template>
