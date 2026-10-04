<script setup lang="ts">
import type { PageMeta } from '~/types/site';

/**
 * Pricing as a stack builder (the Acme theme's Stratus pricing page): a tab per service with its tiers from the
 * catalogue, a tier picked for each (or the service left off), and "Your stack" adding up the bill, monthly or yearly.
 */
definePageMeta({ layout: 'public' });
type Tier = { key: string; name: string; monthlyCents: number | null; yearlyCents: number | null; description: string; features: string[] };
type Service = { key: string; name: string; tagline: string; tiers: Tier[]; addOns: Array<{ name: string; monthlyCentsPerUnit: number | null; description: string }>; meters: string[] };
type PricingPage = { meta: PageMeta; trialDays: number; services: Service[]; competitors: Array<{ slug: string; name: string }> };
const { t } = useT();
const route = useRoute();
const router = useRouter();
const { data } = await useApi<PricingPage>('/site/pricing');
usePublicPage(() => data.value.meta);
const yearly = computed(() => route.query.billing === 'yearly');
const tab = ref(data.value.services.find((service) => service.key === route.hash.slice(1))?.key ?? data.value.services[0]?.key ?? '');
const service = computed(() => data.value.services.find((item) => item.key === tab.value) ?? data.value.services[0]!);
// Every service starts on its first (free) tier.
const picks = reactive<Record<string, string | null>>(Object.fromEntries(data.value.services.map((item) => [item.key, item.tiers[0]?.key ?? null])));
// Each tier gets its own pastel card and matching dot, as in the theme.
const tints = [
    { card: 'bg-[#fbe9e0] dark:bg-[#3a2a24]', dot: 'bg-[#d9805f]' },
    { card: 'bg-[#e4edf0] dark:bg-[#24313a]', dot: 'bg-[#6f95a0]' },
    { card: 'bg-[#edf3e2] dark:bg-[#2b3324]', dot: 'bg-[#93ad5f]' },
    { card: 'bg-[#eee9fb] dark:bg-[#2e2840]', dot: 'bg-[#8b6fd6]' },
    { card: 'bg-[#f6eedb] dark:bg-[#3a3324]', dot: 'bg-[#c49a3c]' },
    { card: 'bg-[#e6eefb] dark:bg-[#242c40]', dot: 'bg-[#5f86c9]' },
];
const tint = (index: number) => tints[index % tints.length]!;

/**
 * A tier's price for the chosen period, per month, in cents (null when it's priced on request).
 *
 * @param tier The tier.
 */
const perMonth = (tier: Tier) => (yearly.value ? (tier.yearlyCents === null ? null : Math.round(tier.yearlyCents / 12)) : tier.monthlyCents);

/**
 * Format US cents as a price: free, whole dollars where it is, or "Contact us" when unpriced.
 *
 * @param cents The amount.
 */
const money = (cents: number | null) => (cents === null ? t('Contact us') : cents === 0 ? t('Free') : `$${(cents / 100).toFixed(cents % 100 === 0 ? 0 : 2)}`);

/**
 * The tier picked for a service, if it's in the stack.
 *
 * @param item The service.
 */
const picked = (item: Service) => item.tiers.find((tier) => tier.key === picks[item.key]) ?? null;
const total = computed(() => data.value.services.reduce((sum, item) => sum + (picked(item) ? perMonth(picked(item)!) ?? 0 : 0), 0));

/**
 * Put a service in the stack on its first tier, or take it out.
 *
 * @param item The service.
 */
function toggle(item: Service) {
    picks[item.key] = picks[item.key] ? null : item.tiers[0]?.key ?? null;
}

/**
 * Switch between monthly and yearly prices, keeping the address shareable.
 *
 * @param value Whether to show yearly prices.
 */
function period(value: boolean) {
    router.replace({ query: value ? { billing: 'yearly' } : {}, hash: route.hash });
}

/**
 * Move between the service tabs with the arrow keys, as a tab list does.
 *
 * @param event The key press.
 */
function keys(event: KeyboardEvent) {
    const index = data.value.services.findIndex((item) => item.key === tab.value);
    const next = { ArrowRight: index + 1, ArrowLeft: index - 1 }[event.key];
    if (next === undefined) {
        return;
    }
    event.preventDefault();
    const target = data.value.services[(next + data.value.services.length) % data.value.services.length];
    if (target) {
        tab.value = target.key;
        nextTick(() => document.getElementById(`tab-${target.key}`)?.focus());
    }
}
</script>

<template>
    <div>
        <section class="border-b border-line">
            <div class="site-frame pb-12 pt-16">
                <h1 class="text-[clamp(3rem,8vw,6.5rem)] font-medium leading-[0.95] tracking-[-0.05em] text-ink">{{ t('Simple pricing') }}</h1>
                <div class="mt-8 grid gap-8 lg:grid-cols-[1.2fr_1fr] lg:items-end">
                    <p class="max-w-lg text-xl leading-snug text-ink">{{ t('Each service has its own tiers, all on one bill. Start free, change tiers any time, and pay monthly or yearly in US dollars.') }}</p>
                    <div class="flex flex-col items-start gap-3 lg:items-end">
                        <div class="inline-flex rounded-full border border-line bg-surface p-1 text-sm font-medium" role="group" :aria-label="t('Billing period')">
                            <button type="button" :class="['rounded-full px-4 py-1.5', !yearly ? 'bg-[var(--acme-night)] text-white' : 'text-ink']" :aria-pressed="!yearly" @click="period(false)">{{ t('Monthly') }}</button>
                            <button type="button" :class="['rounded-full px-4 py-1.5', yearly ? 'bg-[var(--acme-night)] text-white' : 'text-ink']" :aria-pressed="yearly" @click="period(true)">{{ t('Yearly · 2 months free') }}</button>
                        </div>
                        <span v-if="data.trialDays > 0" class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">{{ t('Your first paid plan is free for :days days', { days: data.trialDays }) }}</span>
                    </div>
                </div>
            </div>
        </section>

        <div class="border-b border-line bg-surface-muted">
            <div class="mx-auto grid max-w-6xl border-x border-line lg:grid-cols-[1fr_20rem]">
                <div class="min-w-0">
                    <div class="sticky top-16 z-20 overflow-x-auto border-b border-line bg-surface/90 backdrop-blur [scrollbar-width:none]">
                        <div class="flex min-w-max" role="tablist" :aria-label="t('Services')" @keydown="keys">
                            <button
                                v-for="item in data.services"
                                :id="`tab-${item.key}`"
                                :key="item.key"
                                type="button"
                                role="tab"
                                :aria-selected="tab === item.key"
                                :aria-controls="`panel-${item.key}`"
                                :tabindex="tab === item.key ? 0 : -1"
                                :class="['relative flex flex-1 items-center justify-center gap-2 px-5 py-4 text-sm font-medium transition', tab === item.key ? 'text-ink' : 'text-muted hover:text-ink']"
                                @click="tab = item.key"
                            >
                                {{ item.name }}
                                <span :class="['size-1.5 rounded-full', picks[item.key] ? 'bg-primary' : 'bg-line']" aria-hidden="true" />
                                <span v-if="tab === item.key" class="absolute inset-x-4 bottom-0 h-0.5 bg-primary" aria-hidden="true" />
                            </button>
                        </div>
                    </div>

                    <section :id="`panel-${service.key}`" class="scroll-mt-32 px-6 py-10 sm:px-8" role="tabpanel" :aria-labelledby="`tab-${service.key}`">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <h2 class="text-2xl font-semibold tracking-tight text-ink">{{ service.name }}</h2>
                                <p class="mt-1 max-w-lg text-sm text-muted">{{ service.tagline }} <NuxtLink :to="`/features/${service.key}`" class="font-medium text-ink underline underline-offset-2">{{ t('What it does') }}</NuxtLink></p>
                            </div>
                            <div class="flex items-center gap-2 text-sm"><span class="text-muted">{{ t('In my stack') }}</span><AcmeToggle :model-value="!!picks[service.key]" :label="t('Include :service in my stack', { service: service.name })" @update:model-value="toggle(service)" /></div>
                        </div>

                        <div :class="['mt-8 grid gap-4', service.tiers.length >= 5 ? 'sm:grid-cols-2 xl:grid-cols-3' : service.tiers.length === 4 ? 'sm:grid-cols-2' : service.tiers.length === 3 ? 'md:grid-cols-3' : 'max-w-sm']">
                            <article v-for="(tier, index) in service.tiers" :key="tier.key" :class="['relative flex flex-col rounded-2xl p-5 text-[#18162b] transition dark:text-white', tint(index).card, picks[service.key] === tier.key && 'ring-2 ring-[#18162b] ring-offset-2 ring-offset-surface-muted dark:ring-white']">
                                <h3 class="text-lg font-semibold tracking-tight">{{ tier.name }}</h3>
                                <p class="mt-0.5 text-sm opacity-65">{{ tier.description }}</p>
                                <p class="mt-5"><span class="text-4xl font-medium tracking-tight">{{ money(perMonth(tier)) }}</span><span v-if="(tier.monthlyCents ?? 0) > 0" class="text-sm opacity-60"> {{ t('/ month') }}</span></p>
                                <p class="h-4 text-xs opacity-60">{{ yearly && (tier.yearlyCents ?? 0) > 0 ? t(':price billed yearly', { price: money(tier.yearlyCents) }) : '' }}</p>
                                <ul class="mt-4 flex-1 space-y-1.5 text-sm"><li v-for="feature in tier.features" :key="feature" class="flex gap-2"><span :class="['mt-1.5 size-1.5 shrink-0 rounded-full', tint(index).dot]" aria-hidden="true" />{{ feature }}</li></ul>
                                <button type="button" :class="['mt-5 rounded-full py-2.5 text-center text-sm font-medium transition', picks[service.key] === tier.key ? 'bg-primary text-on-primary' : 'bg-[#18162b] text-white hover:bg-black dark:bg-white dark:text-[#18162b]']" :aria-pressed="picks[service.key] === tier.key" @click="picks[service.key] = tier.key">
                                    {{ picks[service.key] === tier.key ? t('✓ In your stack') : t('Choose :tier', { tier: tier.name }) }}
                                </button>
                            </article>
                        </div>
                        <ul v-if="service.addOns.length > 0 || service.meters.length > 0" class="mt-6 space-y-1 text-sm text-muted">
                            <li v-for="addOn in service.addOns" :key="addOn.name">{{ t('Add-on: :name, :price each per month.', { name: addOn.name, price: money(addOn.monthlyCentsPerUnit) }) }} {{ addOn.description }}</li>
                            <li v-for="meter in service.meters" :key="meter">{{ t(':name beyond your tier’s allowance are billed by usage.', { name: meter }) }}</li>
                        </ul>
                    </section>
                </div>

                <aside class="border-t border-line bg-surface lg:border-l lg:border-t-0" :aria-label="t('Your stack')">
                    <div class="p-6 lg:sticky lg:top-16">
                        <p class="site-kicker">{{ t('Your stack') }}</p>
                        <ul class="mt-4 divide-y divide-line text-sm">
                            <li v-for="item in data.services" :key="item.key">
                                <button type="button" :class="['flex w-full items-center gap-3 py-2.5 text-left', !picks[item.key] && 'opacity-50']" @click="tab = item.key">
                                    <span class="min-w-0 flex-1"><span class="block font-medium text-ink">{{ item.name }}</span><span class="text-xs text-muted">{{ picked(item)?.name ?? t('Off') }}</span></span>
                                    <span class="tabular-nums text-ink">{{ picked(item) ? money(perMonth(picked(item)!)) : '—' }}</span>
                                </button>
                            </li>
                        </ul>
                        <div class="mt-2 border-t border-line pt-4" aria-live="polite">
                            <p class="flex items-baseline justify-between"><span class="text-sm font-medium text-ink">{{ t('Total') }}</span><span><span class="text-3xl font-semibold tracking-tight text-ink">{{ money(total) }}</span><span v-if="total > 0" class="text-sm text-muted"> {{ t('/ month') }}</span></span></p>
                            <p v-if="total === 0" class="mt-1 text-right text-xs text-emerald-700 dark:text-emerald-300">{{ t('Free at this size') }}</p>
                            <p v-else-if="yearly" class="mt-1 text-right text-xs text-emerald-700 dark:text-emerald-300">{{ t('Billed yearly, with two months free') }}</p>
                        </div>
                        <NuxtLink to="/register" class="site-btn mt-5 w-full">{{ total > 0 && data.trialDays > 0 ? t('Start your :days-day trial', { days: data.trialDays }) : t('Start free') }}</NuxtLink>
                        <p class="mt-3 text-center text-xs text-muted">{{ t('No card to start. Servers are billed by your provider.') }}</p>
                    </div>
                </aside>
            </div>
        </div>

        <section class="border-b border-line">
            <div class="site-frame py-16">
                <div class="flex flex-wrap items-end justify-between gap-4"><div><h2 class="text-xl font-semibold text-ink">{{ t('Coming from another tool?') }}</h2><p class="mt-1 text-sm text-muted">{{ t('Honest comparisons, including where the other tool is the better pick.') }}</p></div></div>
                <ul class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <li v-for="competitor in data.competitors" :key="competitor.slug"><NuxtLink :to="`/compare/${competitor.slug}`" class="group block h-full rounded-xl border border-line p-4 transition hover:shadow-md"><span class="block font-medium text-ink group-hover:underline">{{ t(':app vs :other', { app: 'BuildPusher', other: competitor.name }) }}</span></NuxtLink></li>
                </ul>
            </div>
        </section>
    </div>
</template>
