<script setup lang="ts">
import type { PageMeta, ServiceCopy } from '~/types/site';

/**
 * How BuildPusher compares with another tool (the Acme theme's Stratus comparison page): the alternative-to hero, an
 * at-a-glance table, who should choose which, reasons people switch, a look at the matching services, what each
 * includes, how to move, the cost, common questions and the other comparisons. Every claim about the other tool comes
 * from config/compare.php, checked against its public site.
 */
definePageMeta({ layout: 'public' });
type Included = { group: string; rows: Array<[string, boolean | string, boolean | string]> };
type Brief = { slug: string; name: string; what: string; summary: string; overlaps: Array<{ key: string; name: string }> };
type ComparePage = {
    meta: PageMeta;
    slug: string;
    copy: {
        name: string;
        summary: string;
        same: string[];
        different: string[];
        choose_them: string;
        what: string;
        pitch: string;
        stance: [string, string];
        glance: Array<[string, string, string]>;
        stronger: string[];
        reasons: Array<{ title: string; text: string }>;
        included: Included[];
        migrate: string[];
        guide: string;
    };
    overlaps: Array<{ key: string; name: string }>;
    previews: Array<ServiceCopy & { key: string; name: string }>;
    startingPrices: string;
    faqs: Array<{ title: string; body: string }>;
    others: Brief[];
    checked: string;
};
const { t } = useT();
const route = useRoute();
const { data } = await useApi<ComparePage>(() => `/site/compare/${route.params.competitor}`);
usePublicPage(() => data.value.meta);
const copy = computed(() => data.value.copy);
const tour = ref(0);
const preview = computed(() => data.value.previews[tour.value % Math.max(1, data.value.previews.length)] ?? null);
const open = ref<string[]>([]);
watch(() => data.value.slug, () => {
    tour.value = 0;
    open.value = data.value.copy.included[0] ? [data.value.copy.included[0].group] : [];
}, { immediate: true });
const lead = computed(() => data.value.overlaps[0]?.name ?? 'BuildPusher');
const steps = ['link', 'checks', 'rocket'];

/**
 * Start a sentence with a capital letter.
 *
 * @param text The sentence.
 */
const sentence = (text: string) => text.charAt(0).toUpperCase() + text.slice(1);

/**
 * Open or close one group of the "What's included" table.
 *
 * @param group The group's name.
 */
function toggle(group: string) {
    open.value = open.value.includes(group) ? open.value.filter((name) => name !== group) : [...open.value, group];
}
</script>

<template>
    <div>
        <section class="relative overflow-hidden border-b border-line">
            <div class="site-dots pointer-events-none absolute inset-0" aria-hidden="true" />
            <div class="site-frame relative py-16 text-center sm:py-20">
                <NuxtLink to="/compare" class="inline-flex items-center gap-1 text-sm text-muted hover:text-ink"><AcmeIcon name="chevronLeft" :size="14" />{{ t('All comparisons') }}</NuxtLink>
                <h1 class="mx-auto mt-6 max-w-3xl text-[clamp(2.25rem,4.8vw,3.75rem)] font-semibold leading-[1.05] tracking-[-0.035em] text-ink">
                    <Rich :text="t(':app is the :pitch alternative to :other', { app: 'BuildPusher', pitch: copy.pitch })"><template #other><span class="text-primary">{{ copy.name }}</span></template></Rich>
                </h1>
                <p class="mx-auto mt-5 max-w-2xl text-lg text-muted">{{ copy.summary }} {{ t(':app puts deploys, servers, monitoring and analytics in one account.', { app: 'BuildPusher' }) }}</p>
                <div class="mt-8 flex flex-wrap justify-center gap-2"><NuxtLink to="/register" class="site-btn">{{ t('Start free') }}</NuxtLink><NuxtLink :to="`/help/${copy.guide}`" class="site-btn-2">{{ t('How to move') }}</NuxtLink></div>
                <p class="mt-3 text-xs text-muted">{{ t('No card to start. Every service has a free tier.') }}</p>
            </div>
        </section>

        <section class="border-b border-line">
            <div class="site-frame py-16">
                <h2 class="text-center text-[clamp(1.5rem,2.8vw,2.25rem)] font-semibold tracking-[-0.03em] text-ink">{{ sentence(t(':other is :stance.', { other: copy.name, stance: copy.stance[0] })) }} <span class="text-primary">{{ t(':app is :stance.', { app: 'BuildPusher', stance: copy.stance[1] }) }}</span></h2>
                <div class="mx-auto mt-10 max-w-4xl overflow-x-auto rounded-xl border border-line">
                    <table class="w-full min-w-[36rem] text-sm">
                        <caption class="sr-only">{{ t(':app and :other at a glance', { app: 'BuildPusher', other: copy.name }) }}</caption>
                        <thead><tr><td class="w-1/4" /><th scope="col" class="bg-[var(--acme-night)] px-5 py-3 text-left font-semibold text-white">BuildPusher</th><th scope="col" class="bg-black/[.04] px-5 py-3 text-left font-semibold text-ink dark:bg-white/[.06]">{{ copy.name }}</th></tr></thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="row in copy.glance" :key="row[0]"><th scope="row" class="px-5 py-3.5 text-left font-medium text-muted">{{ row[0] }}</th><td class="bg-primary/[.04] px-5 py-3.5 font-medium text-ink">{{ row[1] }}</td><td class="px-5 py-3.5 text-muted">{{ row[2] }}</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="border-b border-line bg-surface-muted">
            <div class="site-frame py-16">
                <h2 class="text-center text-[clamp(1.5rem,2.8vw,2.25rem)] font-semibold tracking-[-0.03em] text-ink">{{ t('Who should choose which?') }}</h2>
                <div class="mx-auto mt-10 grid max-w-4xl gap-4 md:grid-cols-2">
                    <article class="site-night p-7 shadow-[0_20px_50px_-20px_rgb(60_40_140/.5)] ring-2 ring-primary">
                        <h3 class="text-xl font-semibold">{{ t('Choose :app if', { app: 'BuildPusher' }) }}…</h3>
                        <p class="mt-2 text-white/75">{{ sentence(t('you want deploys, the servers they run on, monitoring and analytics to work together, on one bill.')) }}</p>
                        <ul class="mt-5 space-y-3 text-sm"><li v-for="line in copy.different.slice(0, 3)" :key="line" class="flex gap-2"><AcmeIcon name="check" :size="15" class="mt-0.5 shrink-0 text-[#c4b5fd]" />{{ line }}</li></ul>
                    </article>
                    <article class="rounded-2xl border border-line bg-surface p-7">
                        <h3 class="text-xl font-semibold text-ink">{{ t('Choose :other if', { other: copy.name }) }}…</h3>
                        <p class="mt-2 text-muted">{{ sentence(copy.choose_them) }}</p>
                        <ul class="mt-5 space-y-3 text-sm text-muted"><li v-for="line in copy.stronger" :key="line" class="flex gap-2"><AcmeIcon name="star" :size="15" class="mt-0.5 shrink-0 text-amber-500" />{{ line }}</li></ul>
                    </article>
                </div>
                <div class="mt-8 text-center"><NuxtLink to="/register" class="site-btn">{{ t('Start free') }}</NuxtLink></div>
            </div>
        </section>

        <section class="border-b border-line">
            <div class="site-frame py-16">
                <h2 class="text-center text-[clamp(1.5rem,2.8vw,2.25rem)] font-semibold tracking-[-0.03em] text-ink">{{ t(':count reasons teams choose :app over :other', { count: copy.reasons.length, app: 'BuildPusher', other: copy.name }) }}</h2>
                <ol class="mx-auto mt-12 max-w-3xl space-y-10">
                    <li v-for="(reason, index) in copy.reasons" :key="reason.title" class="grid items-start gap-5 sm:grid-cols-[3rem_1fr]">
                        <span class="grid size-11 place-items-center rounded-full bg-[var(--acme-night)] text-lg font-semibold text-white">{{ index + 1 }}</span>
                        <div><h3 class="text-xl font-semibold tracking-tight text-ink">{{ reason.title }}</h3><p class="mt-2 leading-relaxed text-muted">{{ reason.text }}</p></div>
                    </li>
                </ol>
            </div>
        </section>

        <section v-if="preview" class="border-b border-line bg-surface-muted">
            <div class="site-frame py-16">
                <h2 class="text-center text-[clamp(1.5rem,2.8vw,2.25rem)] font-semibold tracking-[-0.03em] text-ink">{{ t('Try :app: :service without the busywork', { app: 'BuildPusher', service: lead.toLowerCase() }) }}</h2>
                <div class="mt-10 grid items-center gap-10 lg:grid-cols-[1fr_1.1fr]">
                    <ul class="divide-y divide-line border-y border-line">
                        <li v-for="(line, index) in copy.different" :key="line">
                            <button type="button" class="flex w-full items-start gap-3 py-4 text-left" :aria-pressed="tour === index" @click="tour = index">
                                <span :class="['mt-1.5 h-4 w-0.5 shrink-0 rounded-full', tour === index ? 'bg-primary' : 'bg-transparent']" aria-hidden="true" />
                                <span :class="['text-sm', tour === index ? 'font-medium text-ink' : 'text-muted']">{{ line }}</span>
                            </button>
                        </li>
                    </ul>
                    <Transition mode="out-in" enter-from-class="opacity-0 translate-y-1" enter-active-class="transition duration-200" leave-to-class="opacity-0" leave-active-class="transition duration-100">
                        <ServicePreview :key="preview.key" :copy="preview" :heading-id="`compare-preview-${preview.key}`" />
                    </Transition>
                </div>
                <p class="mt-4 text-center text-xs text-muted">{{ t('Sample data, for illustration.') }}</p>
            </div>
        </section>

        <section class="border-b border-line">
            <div class="site-frame py-16">
                <h2 class="text-center text-[clamp(1.5rem,2.8vw,2.25rem)] font-semibold tracking-[-0.03em] text-ink">{{ t('What’s included in each') }}</h2>
                <div class="mx-auto mt-10 max-w-4xl divide-y divide-line rounded-xl border border-line bg-surface">
                    <div v-for="group in copy.included" :key="group.group">
                        <button type="button" class="flex w-full items-center justify-between px-5 py-4 text-left font-semibold text-ink" :aria-expanded="open.includes(group.group)" @click="toggle(group.group)">{{ group.group }}<AcmeIcon name="chevronDown" :size="16" :class="['transition-transform', open.includes(group.group) && 'rotate-180']" /></button>
                        <AcmeCollapse :open="open.includes(group.group)">
                            <div class="overflow-x-auto px-5 pb-5">
                                <table class="w-full min-w-[30rem] text-sm">
                                    <thead><tr><th scope="col" class="py-2 text-left font-medium text-muted">{{ t('Feature') }}</th><th scope="col" class="w-36 rounded-t-md bg-[var(--acme-night)] py-2 text-center font-semibold text-white">BuildPusher</th><th scope="col" class="w-36 py-2 text-center font-semibold text-ink">{{ copy.name }}</th></tr></thead>
                                    <tbody class="divide-y divide-line">
                                        <tr v-for="row in group.rows" :key="row[0]">
                                            <th scope="row" class="py-3 text-left font-normal text-ink">{{ row[0] }}</th>
                                            <td v-for="(value, index) in [row[1], row[2]]" :key="index" :class="['py-3 text-center', index === 0 && 'bg-primary/[.05]']">
                                                <span v-if="value === true" :class="['inline-grid size-5 place-items-center rounded-full text-white', index ? 'bg-zinc-400' : 'bg-primary']"><AcmeIcon name="check" :size="12" /></span>
                                                <span v-else-if="value === false" class="text-muted">—</span>
                                                <span v-else class="text-xs text-muted">{{ value }}</span>
                                                <span v-if="typeof value === 'boolean'" class="sr-only">{{ value ? t('Yes') : t('No') }}</span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </AcmeCollapse>
                    </div>
                </div>
                <div class="mx-auto mt-8 grid max-w-4xl gap-3 md:grid-cols-2">
                    <div class="rounded-xl border border-line bg-surface p-5">
                        <h3 class="text-sm font-semibold text-ink">{{ t('What’s alike') }}</h3>
                        <ul class="mt-3 space-y-2 text-sm text-muted"><li v-for="line in copy.same" :key="line" class="flex gap-2"><AcmeIcon name="check" :size="15" class="mt-0.5 shrink-0" />{{ line }}</li></ul>
                    </div>
                    <div v-if="copy.different.length > 3" class="rounded-xl border border-line bg-surface p-5">
                        <h3 class="text-sm font-semibold text-ink">{{ t('Also in :app', { app: 'BuildPusher' }) }}</h3>
                        <ul class="mt-3 space-y-2 text-sm text-muted"><li v-for="line in copy.different.slice(3)" :key="line" class="flex gap-2"><AcmeIcon name="plus" :size="15" class="mt-0.5 shrink-0 text-primary" />{{ line }}</li></ul>
                    </div>
                </div>
            </div>
        </section>

        <section class="border-b border-line bg-surface-muted">
            <div class="site-frame py-16">
                <h2 class="text-center text-[clamp(1.5rem,2.8vw,2.25rem)] font-semibold tracking-[-0.03em] text-ink">{{ t('Switching is easier than you think') }}</h2>
                <p class="mt-2 text-center text-muted">{{ t('Three steps, with nothing to rebuild by hand.') }}</p>
                <ol class="mx-auto mt-10 grid max-w-4xl gap-4 md:grid-cols-3">
                    <li v-for="(step, index) in copy.migrate" :key="step" class="rounded-xl border border-line bg-surface p-6">
                        <span class="grid size-10 place-items-center rounded-lg bg-primary/10 text-primary"><AcmeIcon :name="steps[index] ?? 'check'" :size="18" /></span>
                        <p class="mt-4 font-mono text-xs text-muted">{{ t('Step :number', { number: index + 1 }) }}</p>
                        <p class="mt-1 text-sm font-medium text-ink">{{ step }}</p>
                    </li>
                </ol>
                <p class="mt-8 text-center"><NuxtLink :to="`/help/${copy.guide}`" class="inline-flex items-center gap-1 text-sm font-medium text-ink underline underline-offset-2">{{ t('Read the step-by-step guide') }}<AcmeIcon name="arrowRight" :size="14" /></NuxtLink></p>
            </div>
        </section>

        <section class="border-b border-line">
            <div class="site-frame py-14">
                <div class="site-night grid items-center gap-6 p-8 sm:p-10 md:grid-cols-[1.4fr_1fr]">
                    <div><h2 class="text-2xl font-semibold tracking-tight">{{ t('Run :service without the overhead.', { service: lead.toLowerCase() }) }}</h2><p class="mt-2 text-white/65">{{ t('Every service has a free tier. Paid tiers: :prices. The free tiers of every other service come with it, on one bill.', { prices: data.startingPrices }) }}</p></div>
                    <div class="flex flex-wrap gap-2 md:justify-end"><NuxtLink to="/register" class="site-btn-light">{{ t('Start free') }}</NuxtLink><NuxtLink :to="`/pricing#${data.overlaps[0]?.key ?? ''}`" class="site-btn-outline-light">{{ t('See pricing') }}</NuxtLink></div>
                </div>
            </div>
        </section>

        <section class="border-b border-line">
            <div class="site-frame grid gap-8 py-16 lg:grid-cols-[1fr_1.6fr]">
                <div>
                    <h2 class="text-[clamp(1.5rem,2.8vw,2.25rem)] font-semibold tracking-[-0.03em] text-ink">{{ t('What else you should know') }}</h2>
                    <p class="mt-3 text-xs text-muted">{{ t(':other is a trademark of its owner. This page is based on their public information as of :date; products change, so check their site for the latest.', { other: copy.name, date: data.checked }) }}</p>
                </div>
                <AcmeAccordion :items="data.faqs" />
            </div>
        </section>

        <section class="border-b border-line">
            <div class="site-frame py-12">
                <h2 class="font-semibold text-ink">{{ t('Other comparisons') }}</h2>
                <ul class="mt-4 flex flex-wrap gap-2"><li v-for="other in data.others" :key="other.slug"><NuxtLink :to="`/compare/${other.slug}`" class="block rounded-full border border-line px-3 py-1.5 text-sm text-ink hover:bg-black/[.03] dark:hover:bg-white/[.05]">{{ t(':app vs :other', { app: 'BuildPusher', other: other.name }) }}</NuxtLink></li></ul>
            </div>
        </section>
    </div>
</template>
