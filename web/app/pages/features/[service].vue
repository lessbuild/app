<script setup lang="ts">
import type { PageMeta, ServiceCopy } from '~/types/site';

/** A service's page: what it does, how it's used, its safeguards, how it works with the rest, and common questions. */
definePageMeta({ layout: 'public' });
type FeaturesPage = {
    meta: PageMeta;
    service: { key: string; name: string };
    copy: ServiceCopy;
    screenshot: string | null;
    others: Array<{ key: string; name: string; copy: Pick<ServiceCopy, 'accent' | 'icon' | 'eyebrow' | 'card_summary'> }>;
};
const { t } = useT();
const route = useRoute();
const { data } = await useApi<FeaturesPage>(() => `/site/features/${route.params.service}`);
usePublicPage(() => data.value.meta);
const copy = computed(() => data.value.copy);
const name = computed(() => data.value.service.name);
const highlightColumns = computed(() => (copy.value.highlights.length === 4 ? 'sm:grid-cols-2 lg:grid-cols-4' : 'md:grid-cols-3'));
</script>

<template>
    <div>
        <section class="border-b border-line" aria-labelledby="service-heading">
            <div class="site-frame grid items-center gap-12 py-14 lg:grid-cols-[1.1fr_1fr] lg:py-20">
                <div class="min-w-0">
                    <NuxtLink to="/#services" class="inline-flex items-center gap-1 text-sm text-muted hover:text-ink"><Icon name="chevron-left" class="size-3.5" />{{ t('All services') }}</NuxtLink>
                    <p :class="['mt-6 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider', `product-accent-${copy.accent}`]"><Icon :name="copy.icon" class="size-3.5" />{{ copy.eyebrow }} · {{ name }}</p>
                    <h1 id="service-heading" class="site-h1 mt-3">{{ copy.headline }}</h1>
                    <p class="mt-5 max-w-lg text-lg leading-8 text-muted">{{ copy.summary }}</p>
                    <ul class="mt-6 space-y-2 text-sm font-medium text-ink" :aria-label="t(':service highlights', { service: name })">
                        <li v-for="feature in copy.card_features" :key="feature" class="flex items-center gap-2"><Icon name="check" :class="['size-4 shrink-0', `product-accent-${copy.accent}`]" />{{ feature }}</li>
                    </ul>
                    <div class="mt-8 flex flex-wrap gap-2">
                        <NuxtLink to="/register" class="site-btn">{{ t('Start free') }} <Icon name="arrow-right" class="size-3.5" /></NuxtLink>
                        <NuxtLink :to="`/pricing#${data.service.key}`" class="site-btn-2">{{ t(':service pricing', { service: name }) }}</NuxtLink>
                    </div>
                </div>
                <ServicePreview :copy="copy" />
            </div>
        </section>

        <SiteSection pad="sm" frame-class="flex flex-wrap items-center gap-x-6 gap-y-3" labelledby="glance-heading">
            <h2 id="glance-heading" class="site-kicker">{{ t('Works with') }}</h2>
            <ul class="flex flex-wrap gap-2">
                <li v-for="capability in copy.capabilities" :key="capability" class="rounded-full border border-line px-3 py-1 text-xs font-medium text-ink">{{ capability }}</li>
            </ul>
        </SiteSection>

        <SiteSection tint labelledby="highlights-heading">
            <p :class="['text-center text-xs font-semibold uppercase tracking-wider', `product-accent-${copy.accent}`]">{{ copy.eyebrow }}</p>
            <h2 id="highlights-heading" class="site-h2 mx-auto mt-3 max-w-2xl text-center">{{ copy.highlights_heading }}</h2>
            <div :class="['mt-12 grid gap-4', highlightColumns]">
                <article v-for="highlight in copy.highlights" :key="highlight.title" class="site-card p-6">
                    <span :class="['grid size-10 place-items-center rounded-lg', `product-icon-${copy.accent}`]" aria-hidden="true"><Icon :name="highlight.icon" class="size-[1.125rem]" /></span>
                    <h3 class="mt-5 font-semibold text-ink">{{ highlight.title }}</h3>
                    <p class="mt-1.5 text-sm leading-relaxed text-muted">{{ highlight.text }}</p>
                </article>
            </div>
            <figure v-if="data.screenshot" class="mt-14">
                <div class="site-card overflow-hidden shadow-[0_40px_80px_-40px_rgb(40_30_100/.35)]">
                    <div class="flex items-center gap-1.5 border-b border-line px-4 py-2.5" aria-hidden="true"><span class="size-2.5 rounded-full bg-line" /><span class="size-2.5 rounded-full bg-line" /><span class="size-2.5 rounded-full bg-line" /></div>
                    <img :src="data.screenshot" :alt="t('A screenshot of :service in :app', { service: name, app: 'BuildPusher' })" width="1360" height="860" loading="lazy" class="block h-auto w-full">
                </div>
                <figcaption class="mt-3 text-center text-xs text-muted">{{ t('The real :service, with sample data.', { service: name }) }}</figcaption>
            </figure>
        </SiteSection>

        <SiteSection id="capabilities" labelledby="capabilities-heading">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p class="site-kicker">{{ t('Inside :service', { service: name }) }}</p>
                    <h2 id="capabilities-heading" class="site-h2 mt-2">{{ t('What you can do') }}</h2>
                </div>
                <p class="max-w-xs text-sm text-muted">{{ t('A closer look at what :service does, grouped by the work it supports.', { service: name }) }}</p>
            </div>
            <div class="mt-10 grid gap-4 lg:grid-cols-2">
                <article v-for="group in copy.groups" :key="group.label" class="site-card p-6" :aria-label="group.label">
                    <p :class="['text-xs font-semibold uppercase tracking-wider', `product-accent-${copy.accent}`]">{{ group.label }}</p>
                    <h3 class="mt-2 text-lg font-semibold text-ink">{{ group.title }}</h3>
                    <p class="mt-1 text-sm text-muted">{{ group.description }}</p>
                    <ul class="mt-5 grid gap-x-6 gap-y-4 sm:grid-cols-2">
                        <li v-for="[title, text] in group.features" :key="title" class="flex gap-3">
                            <span :class="['mt-1.5 size-1.5 shrink-0 rounded-full bg-current', `product-accent-${copy.accent}`]" aria-hidden="true" />
                            <span><span class="block text-sm font-medium text-ink">{{ title }}</span><span class="text-sm leading-relaxed text-muted">{{ text }}</span></span>
                        </li>
                    </ul>
                </article>
            </div>
        </SiteSection>

        <SiteSection tint frame-class="grid gap-8 lg:grid-cols-2" labelledby="workflows-heading">
            <div>
                <p class="site-kicker">{{ t('A practical path through :service', { service: name }) }}</p>
                <h2 id="workflows-heading" class="mt-2 text-[clamp(1.75rem,3vw,2.25rem)] font-semibold tracking-[-0.03em] text-ink">{{ copy.workflows_heading }}</h2>
                <p class="mt-2 text-muted">{{ copy.workflows_intro }}</p>
                <ol class="mt-8 space-y-3">
                    <li v-for="([title, text], index) in copy.workflows" :key="title" class="site-card flex gap-4 p-4">
                        <span :class="['grid size-8 shrink-0 place-items-center rounded-lg font-mono text-xs font-semibold', `product-icon-${copy.accent}`]">{{ String(index + 1).padStart(2, '0') }}</span>
                        <span><span class="block font-medium text-ink">{{ title }}</span><span class="text-sm text-muted">{{ text }}</span></span>
                    </li>
                </ol>
            </div>
            <section class="site-night self-start p-8" aria-labelledby="guardrails-heading">
                <p class="font-mono text-[0.6875rem] uppercase tracking-wider text-white/50">{{ t('Safety and accountability') }}</p>
                <h3 id="guardrails-heading" class="mt-3 text-xl font-semibold">{{ copy.guardrails_title }}</h3>
                <p class="mt-2 text-sm text-white/65">{{ copy.guardrails_description }}</p>
                <ul class="mt-6 grid gap-3 text-sm sm:grid-cols-2">
                    <li v-for="guardrail in copy.guardrails" :key="guardrail" class="flex items-center gap-2"><Icon name="check" class="size-3.5 shrink-0 text-[#c4b5fd]" />{{ guardrail }}</li>
                </ul>
            </section>
        </SiteSection>

        <SiteSection labelledby="together-heading">
            <div class="grid gap-8 lg:grid-cols-[1fr_1.4fr]">
                <div>
                    <p class="site-kicker">{{ t('Better together') }}</p>
                    <h2 id="together-heading" class="mt-2 text-[clamp(1.75rem,3vw,2.25rem)] font-semibold tracking-[-0.03em] text-ink">{{ t('Works with the rest of :app.', { app: 'BuildPusher' }) }}</h2>
                </div>
                <ul class="space-y-2 text-sm">
                    <li v-for="[label, text] in copy.together" :key="label"><span class="font-semibold text-ink">{{ label }}:</span> <span class="text-muted">{{ text }}</span></li>
                </ul>
            </div>
            <div class="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <NuxtLink v-for="other in data.others" :key="other.key" :to="`/features/${other.key}`" class="site-card site-card-link group p-5">
                    <span :class="['grid size-9 place-items-center rounded-lg', `product-icon-${other.copy.accent}`]" aria-hidden="true"><Icon :name="other.copy.icon" class="size-4" /></span>
                    <span :class="['mt-4 block text-[0.6875rem] font-semibold uppercase tracking-wider', `product-accent-${other.copy.accent}`]">{{ other.copy.eyebrow }}</span>
                    <span class="mt-1 block font-semibold text-ink group-hover:underline">{{ other.name }}</span>
                    <span class="mt-1 block text-sm text-muted">{{ other.copy.card_summary }}</span>
                </NuxtLink>
            </div>
        </SiteSection>

        <SiteSection v-if="copy.questions.length > 0" frame-class="grid gap-8 lg:grid-cols-[1fr_1.6fr]" labelledby="questions-heading">
            <div>
                <p class="site-kicker">{{ t('Good to know') }}</p>
                <h2 id="questions-heading" class="mt-2 text-[clamp(1.75rem,3vw,2.25rem)] font-semibold tracking-[-0.03em] text-ink">{{ t('Straight answers about :service.', { service: name }) }}</h2>
            </div>
            <div class="grid gap-2">
                <Disclosure v-for="[question, answer] in copy.questions" :key="question" :title="question"><p class="text-sm leading-6 text-muted">{{ answer }}</p></Disclosure>
            </div>
        </SiteSection>

        <SiteCta :kicker="t('Part of :app', { app: 'BuildPusher' })" :title="t('Start with :service.', { service: name })" :text="t('Every service has a free tier. Turn on the others when a project needs them, on the same account and bill.')">
            <template #actions><NuxtLink to="/register" class="site-btn-light">{{ t('Start free') }} <Icon name="arrow-right" class="size-3.5" /></NuxtLink></template>
        </SiteCta>
    </div>
</template>
