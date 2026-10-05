<script setup lang="ts">
import type { PageMeta } from '~/types/site';

/**
 * Pricing as a stack builder (the Acme theme's Stratus pricing page): typical setups to start from, a tab per service
 * with its tiers from the catalogue, a usage estimate for metered services, each service's key features by tier, a tier
 * picked for each (or the service left off), and "Your stack" adding up the bill, monthly or yearly. Infrastructure
 * comes with Deploy.
 */
definePageMeta({ layout: 'public' });
type Tier = { key: string; name: string; monthlyCents: number | null; yearlyCents: number | null; description: string; features: string[]; recommended: boolean };
type Meter = { name: string; unit: string; unitSize: number; unitCents: number; allowances: Array<number | null> };
type MatrixGroup = { group: string; rows: Array<{ label: string; values: Array<boolean | string> }> };
type Service = { key: string; name: string; tagline: string; tiers: Tier[]; addOns: Array<{ name: string; monthlyCentsPerUnit: number | null; description: string }>; meters: Meter[]; matrix: MatrixGroup[] };
type Preset = { key: string; name: string; description: string; icon: string; picks: Record<string, string | null> };
type PricingPage = { meta: PageMeta; trialDays: number; services: Service[]; presets: Preset[]; competitors: Array<{ slug: string; name: string; what: string }> };
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

// Infrastructure has no plan of its own: it comes with Deploy.
watch(() => picks.deploy, (deploy) => {
    if ('infrastructure' in picks) {
        picks.infrastructure = deploy ? (data.value.services.find((item) => item.key === 'infrastructure')?.tiers[0]?.key ?? null) : null;
    }
});
const preset = computed(() => data.value.presets.find((item) => Object.entries(item.picks).every(([key, tier]) => !(key in picks) || picks[key] === tier)) ?? null);

/**
 * Start the stack from a typical setup.
 *
 * @param item The setup.
 */
function applyPreset(item: Preset) {
    for (const [key, tier] of Object.entries(item.picks)) {
        if (key in picks) {
            picks[key] = tier;
        }
    }
}

/**
 * What a typical setup costs a month, in cents, for the chosen billing period.
 *
 * @param item The setup.
 */
const presetTotal = (item: Preset) => data.value.services.reduce((sum, entry) => {
    const tier = entry.tiers.find((option) => option.key === item.picks[entry.key]);
    return sum + (tier ? perMonth(tier) ?? 0 : 0);
}, 0);

// The usage estimate: how much a metered service is used a month, on a slider that grows by powers of ten.
const meter = computed(() => service.value.meters[0] ?? null);
const usage = reactive<Record<string, number>>(Object.fromEntries(data.value.services.filter((item) => item.meters[0]).map((item) => [item.key, item.meters[0]!.allowances[1] ?? item.meters[0]!.allowances[0] ?? 1])));
const range = computed(() => {
    const allowances = (meter.value?.allowances ?? []).filter((value): value is number => value !== null);
    return { min: Math.max(1, Math.floor((allowances[0] ?? 1) / 10)), max: Math.max(10, (allowances.at(-1) ?? 10) * 3) };
});
const slider = computed({
    get: () => Math.round((Math.log((usage[service.value.key] ?? 1) / range.value.min) / Math.log(range.value.max / range.value.min)) * 100),
    set: (position: number) => {
        const raw = range.value.min * (range.value.max / range.value.min) ** (position / 100);
        const step = 10 ** Math.max(0, Math.floor(Math.log10(raw)) - 1);
        usage[service.value.key] = Math.max(1, Math.round(raw / step) * step);
    },
});

/**
 * Write a count briefly: 1.5K, 20M.
 *
 * @param value The count.
 */
const compact = (value: number) => (value >= 1e6 ? `${+(value / 1e6).toFixed(1)}M` : value >= 1e3 ? `${+(value / 1e3).toFixed(1)}K` : String(value));

// Each tier's monthly cost at the estimated usage: its price plus usage beyond its allowance (Pay as you go, on paid
// tiers). A free tier stops at its allowance instead.
const estimates = computed(() => {
    const current = meter.value;
    if (!current) {
        return [];
    }
    const used = usage[service.value.key] ?? 0;
    return service.value.tiers.map((tier, index) => {
        const allowance = current.allowances[index] ?? null;
        const over = allowance === null ? 0 : Math.max(0, used - allowance);
        const overage = Math.ceil(over / current.unitSize) * current.unitCents;
        const fits = over === 0 || (tier.monthlyCents ?? 0) > 0;
        return { tier, overage, total: (tier.monthlyCents ?? 0) + overage, fits };
    });
});
const best = computed(() => estimates.value.filter((estimate) => estimate.fits).reduce<(typeof estimates.value)[number] | null>((cheapest, estimate) => (cheapest === null || estimate.total < cheapest.total ? estimate : cheapest), null));
const faqs = computed(() => [
    { title: t('Do I pay for servers through BuildPusher?'), body: t('No. Servers are created in your own cloud accounts and your provider bills you for them directly. BuildPusher plans cover the software.') },
    { title: t('What happens if I go over a limit?'), body: t('On a paid monthly plan, turn on Pay as you go and usage past the allowance is billed per unit, with a monthly spend cap if you like; the estimate above shows what that would cost. Otherwise usage stops at the allowance. Server, website and seat limits ask you to move up a tier first.') },
    { title: t('Can I mix plans?'), body: t('Yes, that’s what the stack builder is for: for example Deploy Pro, Monitoring Free and Analytics Business on one account. Each plan is a line on the same invoice.') },
    { title: t('Can I change or cancel?'), body: t('Change a plan whenever you like. A cancelled plan stays active until the end of the period you’ve paid for, then moves to the free tier.') },
    { title: t('Is there a discount for paying yearly?'), body: t('Yes: yearly billing costs ten months, so two months are free.') },
]);

/**
 * Switch between monthly and yearly prices, keeping the address shareable.
 *
 * @param value Whether to show yearly prices.
 */
function period(value: boolean) {
    router.replace({ query: value ? { billing: 'yearly' } : {}, hash: route.hash });
}

/**
 * Show a service's tiers, and slide its tab fully into view when the row scrolls sideways (on phones).
 *
 * @param key The service's key.
 * @param event The click, whose button is the tab; otherwise the tab is looked up by its id.
 */
function choose(key: string, event?: Event) {
    tab.value = key;
    const button = (event?.currentTarget as HTMLElement | null) ?? document.getElementById(`tab-${key}`);
    const row = button?.closest('.overflow-x-auto');
    if (button && row) {
        const start = button.offsetLeft - 16;
        const end = button.offsetLeft + button.offsetWidth + 16 - row.clientWidth;
        row.scrollTo({ left: Math.min(Math.max(row.scrollLeft, end), start), behavior: 'smooth' });
    }
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
        choose(target.key);
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
                <p class="mt-10 text-sm font-medium text-ink">{{ t('Start from a typical setup') }}</p>
                <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4" role="radiogroup" :aria-label="t('Starting point')">
                    <button
                        v-for="item in data.presets"
                        :key="item.key"
                        type="button"
                        role="radio"
                        :aria-checked="preset?.key === item.key"
                        :class="['flex items-start gap-3 rounded-xl border p-4 text-left transition', preset?.key === item.key ? 'border-primary bg-primary/[.06] ring-1 ring-primary' : 'border-line hover:bg-black/[.02] dark:hover:bg-white/[.03]']"
                        @click="applyPreset(item)"
                    >
                        <span :class="['grid size-9 shrink-0 place-items-center rounded-lg', preset?.key === item.key ? 'bg-primary text-on-primary' : 'bg-black/[.05] text-ink dark:bg-white/10']"><AcmeIcon :name="item.icon" :size="16" /></span>
                        <span>
                            <span class="block text-sm font-semibold text-ink">{{ item.name }}</span>
                            <span class="text-xs text-muted">{{ item.description }}</span>
                            <span class="mt-1 block text-sm font-semibold text-ink">{{ money(presetTotal(item)) }}<span v-if="presetTotal(item) > 0" class="font-normal text-muted"> {{ t('/ month') }}</span></span>
                        </span>
                    </button>
                </div>
            </div>
        </section>

        <div class="border-b border-line bg-surface-muted">
            <div class="mx-auto grid max-w-6xl border-x border-line lg:grid-cols-[1fr_20rem]">
                <div class="min-w-0">
                    <div class="sticky top-16 z-20 overflow-x-auto overflow-y-hidden overscroll-x-contain border-b border-line bg-surface/90 backdrop-blur [scrollbar-width:none]">
                        <div class="flex w-max min-w-full" role="tablist" :aria-label="t('Services')" @keydown="keys">
                            <button
                                v-for="item in data.services"
                                :id="`tab-${item.key}`"
                                :key="item.key"
                                type="button"
                                role="tab"
                                :aria-selected="tab === item.key"
                                :aria-controls="`panel-${item.key}`"
                                :tabindex="tab === item.key ? 0 : -1"
                                :class="['relative flex shrink-0 grow basis-auto items-center justify-center gap-2 whitespace-nowrap px-4 py-4 text-sm font-medium transition sm:px-5', tab === item.key ? 'text-ink' : 'text-muted hover:text-ink']"
                                @click="choose(item.key, $event)"
                            >
                                <AcmeIcon :name="serviceStyle(item.key).icon" :size="15" />{{ item.name }}
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
                            <div v-if="service.key !== 'infrastructure'" class="flex items-center gap-2 text-sm"><span class="text-muted">{{ t('In my stack') }}</span><AcmeToggle :model-value="!!picks[service.key]" :label="t('Include :service in my stack', { service: service.name })" @update:model-value="toggle(service)" /></div>
                        </div>

                        <div v-if="meter" class="mt-6 rounded-xl border border-line bg-surface p-5">
                            <div class="flex flex-wrap items-center justify-between gap-2 text-sm"><span class="font-semibold text-ink">{{ t('Estimate your usage') }}</span><span class="font-mono text-muted">{{ t(':count :unit a month', { count: compact(usage[service.key] ?? 0), unit: meter.unit }) }}</span></div>
                            <input v-model.number="slider" type="range" min="0" max="100" class="mt-4 w-full accent-[var(--ui-primary)]" :aria-label="t(':name a month', { name: meter.name })" :aria-valuetext="t(':count :unit a month', { count: compact(usage[service.key] ?? 0), unit: meter.unit })">
                            <div :class="['mt-4 grid gap-2', estimates.length >= 4 ? 'grid-cols-2 sm:grid-cols-4' : 'grid-cols-3']">
                                <div v-for="estimate in estimates" :key="estimate.tier.key" :class="['rounded-lg border p-3 text-xs', best && estimate.tier.key === best.tier.key ? 'border-primary bg-primary/[.06]' : 'border-line']">
                                    <p class="flex items-center justify-between gap-1 font-semibold text-ink">{{ estimate.tier.name }}<span v-if="best && estimate.tier.key === best.tier.key" class="rounded-full bg-primary px-1.5 text-[0.625rem] text-on-primary">{{ t('Best value') }}</span></p>
                                    <template v-if="estimate.fits">
                                        <p class="mt-1 text-base font-semibold text-ink">{{ money(estimate.total) }}</p>
                                        <p class="text-muted">{{ estimate.overage > 0 ? t(':plan plan + :usage usage', { plan: money(estimate.tier.monthlyCents), usage: money(estimate.overage) }) : t('Within the allowance') }}</p>
                                    </template>
                                    <p v-else class="mt-1 text-muted">{{ t('Over its allowance') }}</p>
                                </div>
                            </div>
                            <button v-if="best && picks[service.key] !== best.tier.key" type="button" class="mt-4 text-sm font-medium text-primary underline underline-offset-2" @click="picks[service.key] = best.tier.key">{{ t('Use :tier in my stack', { tier: best.tier.name }) }}</button>
                            <p v-else-if="best" class="mt-4 flex items-center gap-1.5 text-sm text-emerald-700 dark:text-emerald-300"><AcmeIcon name="checkCircle" :size="15" />{{ t('Your stack already uses the best-value tier.') }}</p>
                            <p class="mt-3 text-xs text-muted">{{ t('Usage past a paid tier’s allowance is billed per unit with Pay as you go (monthly plans), at :price per :size :unit.', { price: money(meter.unitCents), size: compact(meter.unitSize), unit: meter.unit }) }}</p>
                        </div>

                        <div :class="['mt-8 grid gap-4', service.tiers.length >= 5 ? 'sm:grid-cols-2 xl:grid-cols-3' : service.tiers.length === 4 ? 'sm:grid-cols-2' : service.tiers.length === 3 ? 'md:grid-cols-3' : 'max-w-sm']">
                            <article v-for="(tier, index) in service.tiers" :key="tier.key" :class="['relative flex flex-col rounded-2xl p-5 text-[#18162b] transition dark:text-white', tint(index).card, picks[service.key] === tier.key && 'ring-2 ring-[#18162b] ring-offset-2 ring-offset-surface-muted dark:ring-white']">
                                <span v-if="tier.recommended" class="absolute -top-2.5 left-1/2 -translate-x-1/2 whitespace-nowrap rounded-full bg-[#18162b] px-2.5 py-0.5 text-[0.6875rem] font-medium text-white dark:bg-white dark:text-[#18162b]">{{ t('Recommended') }}</span>
                                <h3 class="text-lg font-semibold tracking-tight">{{ tier.name }}</h3>
                                <p class="mt-0.5 text-sm opacity-65">{{ tier.description }}</p>
                                <p class="mt-5"><span class="text-4xl font-medium tracking-tight">{{ money(perMonth(tier)) }}</span><span v-if="(tier.monthlyCents ?? 0) > 0" class="text-sm opacity-60"> {{ t('/ month') }}</span></p>
                                <p class="h-4 text-xs opacity-60">{{ yearly && (tier.yearlyCents ?? 0) > 0 ? t(':price billed yearly', { price: money(tier.yearlyCents) }) : '' }}</p>
                                <p v-if="index > 0" class="mt-4 text-xs font-medium opacity-60">{{ t('Everything in :tier, and:', { tier: service.tiers[index - 1]!.name }) }}</p>
                                <ul :class="['flex-1 space-y-1.5 text-sm', index > 0 ? 'mt-2' : 'mt-4']"><li v-for="feature in tier.features" :key="feature" class="flex gap-2"><span :class="['mt-1.5 size-1.5 shrink-0 rounded-full', tint(index).dot]" aria-hidden="true" />{{ feature }}</li></ul>
                                <p v-if="service.key === 'infrastructure'" class="mt-5 text-sm opacity-65">{{ picks.deploy ? t('Included with your Deploy plan.') : t('Turn on Deploy to include it.') }}</p>
                                <button v-else type="button" :class="['mt-5 rounded-full py-2.5 text-center text-sm font-medium transition', picks[service.key] === tier.key ? 'bg-primary text-on-primary' : 'bg-[#18162b] text-white hover:bg-black dark:bg-white dark:text-[#18162b]']" :aria-pressed="picks[service.key] === tier.key" @click="picks[service.key] = tier.key">
                                    {{ picks[service.key] === tier.key ? t('✓ In your stack') : t('Choose :tier', { tier: tier.name }) }}
                                </button>
                            </article>
                        </div>
                        <ul v-if="service.addOns.length > 0" class="mt-6 space-y-1 text-sm text-muted">
                            <li v-for="addOn in service.addOns" :key="addOn.name">{{ t('Add-on: :name, :price each per month.', { name: addOn.name, price: money(addOn.monthlyCentsPerUnit) }) }} {{ addOn.description }}</li>
                        </ul>
                        <p v-if="service.key === 'infrastructure'" class="mt-4 text-sm text-muted">{{ t('Infrastructure has no plan of its own: server limits come from Deploy, and your cloud provider bills you for the servers.') }}</p>

                        <template v-if="service.matrix.length > 0">
                            <h3 class="mt-12 border-b border-line pb-3 text-lg font-semibold tracking-tight text-ink">{{ t('Key features') }}</h3>
                            <div class="overflow-x-auto">
                                <table :class="['w-full text-sm', service.tiers.length > 1 && 'min-w-[34rem]']">
                                    <caption class="sr-only">{{ t(':service tiers compared', { service: service.name }) }}</caption>
                                    <thead>
                                        <tr>
                                            <td />
                                            <th v-for="(tier, index) in service.tiers" :key="tier.key" scope="col" class="px-2 py-3 text-center text-xs font-semibold text-ink"><span class="inline-flex items-center gap-1.5"><span :class="['size-2 rounded-full', tint(index).dot]" aria-hidden="true" />{{ tier.name }}</span></th>
                                        </tr>
                                    </thead>
                                    <tbody v-for="group in service.matrix" :key="group.group">
                                        <tr><th :colspan="service.tiers.length + 1" scope="colgroup" class="pb-1 pt-5 text-left text-[0.6875rem] font-semibold uppercase tracking-wider text-muted">{{ group.group }}</th></tr>
                                        <tr v-for="row in group.rows" :key="row.label" class="border-b border-line">
                                            <th scope="row" class="py-3 pr-4 text-left text-[0.8125rem] font-medium text-ink">{{ row.label }}</th>
                                            <td v-for="(value, index) in row.values" :key="index" :class="['px-2 py-3 text-center text-[0.8125rem]', picks[service.key] === service.tiers[index]?.key && 'bg-black/[.03] dark:bg-white/[.04]']">
                                                <span v-if="value === true" :class="['inline-grid size-5 place-items-center rounded-full text-white', tint(index).dot]"><AcmeIcon name="check" :size="12" /></span>
                                                <span v-else-if="value === false" class="text-muted">–</span>
                                                <span v-else class="font-medium text-ink">{{ value }}</span>
                                                <span v-if="typeof value === 'boolean'" class="sr-only">{{ value ? t('Included') : t('Not included') }}</span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </template>
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
            <div class="site-frame grid gap-10 py-16 lg:grid-cols-[1fr_1.5fr]">
                <div>
                    <h2 class="text-2xl font-semibold tracking-tight text-ink">{{ t('One account, one bill.') }}</h2>
                    <p class="mt-3 text-muted">{{ t('Every service starts free. Upgrade only what a project outgrows; each plan is a line on the same invoice.') }}</p>
                </div>
                <AcmeAccordion :items="faqs" />
            </div>
        </section>

        <section id="compare" class="border-b border-line">
            <div class="site-frame py-16">
                <div class="flex flex-wrap items-end justify-between gap-4"><div><h2 class="text-xl font-semibold text-ink">{{ t('Coming from another tool?') }}</h2><p class="mt-1 text-sm text-muted">{{ t('Honest comparisons, including where the other tool is the better pick.') }}</p></div><NuxtLink to="/compare" class="flex items-center gap-1 text-sm font-medium text-ink hover:underline">{{ t('All comparisons') }}<AcmeIcon name="arrowRight" :size="14" /></NuxtLink></div>
                <ul class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <li v-for="competitor in data.competitors" :key="competitor.slug"><NuxtLink :to="`/compare/${competitor.slug}`" class="group block h-full rounded-xl border border-line p-4 transition hover:shadow-md"><span class="block font-medium text-ink group-hover:underline">{{ t(':app vs :other', { app: 'BuildPusher', other: competitor.name }) }}</span><span class="text-sm text-muted">{{ competitor.what }}</span></NuxtLink></li>
                </ul>
            </div>
        </section>
    </div>
</template>
