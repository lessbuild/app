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
const highlightColumns = computed(() => (copy.value.highlights.length >= 4 ? 'xl:grid-cols-4' : 'xl:grid-cols-3'));
</script>

<template>
    <div>
        <section class="border-b border-line bg-surface" aria-labelledby="service-heading">
            <div class="mx-auto grid max-w-6xl items-center gap-8 px-5 py-10 sm:px-8 sm:py-16 lg:grid-cols-[.95fr_1.05fr] lg:gap-12 lg:py-20">
                <div class="min-w-0">
                    <NuxtLink to="/#services" class="text-sm font-bold text-muted hover:text-ink"><span aria-hidden="true">←</span> {{ t('All services') }}</NuxtLink>
                    <p :class="['mt-6 flex items-center gap-2 text-xs font-extrabold uppercase tracking-[0.15em] sm:mt-7', `product-accent-${copy.accent}`]">
                        <Icon :name="copy.icon" class="size-4" /> {{ copy.eyebrow }} · {{ name }}
                    </p>
                    <h1 id="service-heading" class="mt-4 max-w-2xl text-4xl font-extrabold leading-[1.04] tracking-[-0.05em] text-ink sm:text-6xl">{{ copy.headline }}</h1>
                    <p class="mt-5 max-w-2xl text-base leading-7 text-muted sm:text-lg sm:leading-8">{{ copy.summary }}</p>
                    <ul class="mt-6 grid gap-2" :aria-label="t(':service highlights', { service: name })">
                        <li v-for="feature in copy.card_features" :key="feature" class="flex items-center gap-2 text-sm font-semibold text-ink"><Icon name="check" class="size-4 shrink-0 text-success" /><span>{{ feature }}</span></li>
                    </ul>
                    <div class="mt-8 flex flex-col gap-3 min-[440px]:flex-row">
                        <NuxtLink to="/register" class="ui-btn ui-btn-primary ui-btn-lg justify-center">{{ t('Start free') }} <Icon name="arrow-right" class="size-4" /></NuxtLink>
                        <NuxtLink :to="`/pricing#${data.service.key}`" class="ui-btn ui-btn-secondary ui-btn-lg justify-center">{{ t(':service pricing', { service: name }) }}</NuxtLink>
                    </div>
                </div>
                <ServicePreview :copy="copy" />
            </div>
        </section>

        <section class="border-b border-line bg-surface" aria-labelledby="glance-heading">
            <div class="mx-auto flex max-w-6xl flex-col gap-3 px-5 py-5 sm:px-8 lg:flex-row lg:items-center lg:justify-between">
                <h2 id="glance-heading" class="text-xs font-bold uppercase tracking-widest text-muted">{{ t('Works with') }}</h2>
                <ul class="flex flex-wrap gap-2">
                    <li v-for="capability in copy.capabilities" :key="capability"><Badge class="px-3 py-1.5 text-xs sm:text-sm">{{ capability }}</Badge></li>
                </ul>
            </div>
        </section>

        <section class="bg-page py-12 sm:py-20" aria-labelledby="highlights-heading">
            <div class="mx-auto max-w-6xl px-5 sm:px-8">
                <div class="mx-auto max-w-3xl sm:text-center">
                    <p :class="['text-xs font-extrabold uppercase tracking-[0.15em]', `product-accent-${copy.accent}`]">{{ copy.eyebrow }}</p>
                    <h2 id="highlights-heading" class="mt-3 text-3xl font-extrabold tracking-[-0.04em] text-ink sm:text-4xl">{{ copy.highlights_heading }}</h2>
                </div>
                <div :class="['mt-8 grid gap-3 sm:gap-4 md:grid-cols-2', highlightColumns]">
                    <article v-for="highlight in copy.highlights" :key="highlight.title" class="ui-card flex gap-4 p-4 sm:block sm:p-6">
                        <span :class="['grid size-10 shrink-0 place-items-center rounded-xl sm:size-11', `product-icon-${copy.accent}`]" aria-hidden="true"><Icon :name="highlight.icon" class="size-5" /></span>
                        <div>
                            <h3 class="text-base font-extrabold text-ink sm:mt-5 sm:text-lg">{{ highlight.title }}</h3>
                            <p class="mt-1 text-sm leading-6 text-muted sm:mt-2">{{ highlight.text }}</p>
                        </div>
                    </article>
                </div>
            </div>
        </section>

        <section v-if="data.screenshot" class="bg-page pb-12 sm:pb-20" aria-labelledby="screenshot-heading">
            <div class="mx-auto max-w-6xl px-5 sm:px-8">
                <h2 id="screenshot-heading" class="sr-only">{{ t('What :service looks like', { service: name }) }}</h2>
                <figure class="overflow-hidden rounded-panel border border-line bg-surface shadow-panel">
                    <div class="flex items-center gap-1.5 border-b border-line bg-surface-muted px-4 py-3" aria-hidden="true"><span class="size-2.5 rounded-full bg-danger/60" /><span class="size-2.5 rounded-full bg-warning/60" /><span class="size-2.5 rounded-full bg-success/60" /></div>
                    <img :src="data.screenshot" :alt="t('A screenshot of :service in :app', { service: name, app: 'BuildPusher' })" width="1360" height="860" loading="lazy" class="block h-auto w-full">
                    <figcaption class="border-t border-line px-4 py-3 text-xs text-muted">{{ t('The real :service, with sample data.', { service: name }) }}</figcaption>
                </figure>
            </div>
        </section>

        <section id="capabilities" class="border-y border-line bg-surface-muted/60 py-12 sm:py-20" aria-labelledby="capabilities-heading">
            <div class="mx-auto max-w-6xl px-5 sm:px-8">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div class="max-w-3xl">
                        <p class="ui-eyebrow">{{ t('Inside :service', { service: name }) }}</p>
                        <h2 id="capabilities-heading" class="mt-3 text-3xl font-extrabold tracking-[-0.04em] text-ink sm:text-4xl">{{ t('What you can do') }}</h2>
                    </div>
                    <p class="hidden max-w-xl text-sm leading-6 text-muted sm:block">{{ t('A closer look at what :service does, grouped by the work it supports.', { service: name }) }}</p>
                </div>
                <div class="mt-8 grid gap-4 sm:gap-5 lg:grid-cols-2">
                    <article v-for="group in copy.groups" :key="group.label" class="ui-card p-5 sm:p-7" :aria-label="group.label">
                        <p :class="['text-xs font-extrabold uppercase tracking-[0.14em]', `product-accent-${copy.accent}`]">{{ group.label }}</p>
                        <h3 class="mt-2 text-xl font-extrabold text-ink sm:text-2xl">{{ group.title }}</h3>
                        <p class="mt-2 text-sm leading-6 text-muted">{{ group.description }}</p>
                        <ul class="mt-4 grid gap-2 sm:mt-5 sm:grid-cols-2 sm:gap-3">
                            <li v-for="[title, text] in group.features" :key="title" class="flex gap-3 rounded-control border border-line bg-surface p-3 sm:p-4">
                                <span class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full bg-emphasis text-emphasis-ink" aria-hidden="true"><Icon name="check" class="size-3" /></span>
                                <div>
                                    <h4 class="text-sm font-bold text-ink">{{ title }}</h4>
                                    <p class="mt-1 hidden text-sm leading-6 text-muted sm:block">{{ text }}</p>
                                </div>
                            </li>
                        </ul>
                    </article>
                </div>
            </div>
        </section>

        <section class="bg-page py-12 sm:py-20" aria-labelledby="workflows-heading">
            <div class="mx-auto grid max-w-6xl gap-8 px-5 sm:px-8 lg:grid-cols-2 lg:gap-10">
                <div>
                    <p class="ui-eyebrow">{{ t('A practical path through :service', { service: name }) }}</p>
                    <h2 id="workflows-heading" class="mt-3 text-3xl font-extrabold tracking-tight text-ink">{{ copy.workflows_heading }}</h2>
                    <p class="mt-3 max-w-xl text-sm leading-7 text-muted">{{ copy.workflows_intro }}</p>
                    <ol class="mt-6 grid gap-3">
                        <li v-for="([title, text], index) in copy.workflows" :key="title" class="ui-card flex gap-4 p-4">
                            <span :class="['grid size-8 shrink-0 place-items-center rounded-full text-xs font-extrabold', `product-icon-${copy.accent}`]">{{ String(index + 1).padStart(2, '0') }}</span>
                            <div>
                                <h3 class="font-bold text-ink">{{ title }}</h3>
                                <p class="mt-1 text-sm leading-6 text-muted">{{ text }}</p>
                            </div>
                        </li>
                    </ol>
                </div>
                <section class="ui-emphasis relative overflow-hidden rounded-panel p-6 sm:p-8" aria-labelledby="guardrails-heading">
                    <p class="text-xs font-extrabold uppercase tracking-[0.16em] text-brand-300">{{ t('Safety and accountability') }}</p>
                    <h2 id="guardrails-heading" class="mt-3 text-2xl font-extrabold tracking-tight text-emphasis-ink">{{ copy.guardrails_title }}</h2>
                    <p class="ui-emphasis-muted mt-3 leading-7">{{ copy.guardrails_description }}</p>
                    <ul class="mt-6 grid grid-cols-2 gap-3">
                        <li v-for="guardrail in copy.guardrails" :key="guardrail" class="flex items-start gap-2 text-sm font-semibold text-emphasis-ink"><Icon name="check" class="mt-0.5 size-4 shrink-0" /><span>{{ guardrail }}</span></li>
                    </ul>
                </section>
            </div>
        </section>

        <section class="border-y border-line bg-surface-muted/60 py-12 sm:py-20" aria-labelledby="together-heading">
            <div class="mx-auto max-w-6xl px-5 sm:px-8">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <p class="ui-eyebrow">{{ t('Better together') }}</p>
                        <h2 id="together-heading" class="mt-3 text-3xl font-extrabold tracking-tight text-ink">{{ t('Works with the rest of :app.', { app: 'BuildPusher' }) }}</h2>
                    </div>
                    <ul class="grid max-w-2xl gap-2">
                        <li v-for="[label, text] in copy.together" :key="label" class="text-sm leading-6 text-muted"><span class="font-bold text-ink">{{ label }}:</span> {{ text }}</li>
                    </ul>
                </div>
                <div class="mt-7 grid gap-3 sm:grid-cols-3 sm:gap-4">
                    <NuxtLink v-for="other in data.others" :key="other.key" :to="`/features/${other.key}`" class="ui-card ui-card--interactive flex items-center gap-4 p-4 sm:block sm:p-6">
                        <span :class="['grid size-10 shrink-0 place-items-center rounded-xl', `product-icon-${other.copy.accent}`]" aria-hidden="true"><Icon :name="other.copy.icon" class="size-5" /></span>
                        <span class="min-w-0 flex-1">
                            <span :class="['block text-xs font-extrabold uppercase tracking-[0.14em] sm:mt-4', `product-accent-${other.copy.accent}`]">{{ other.copy.eyebrow }}</span>
                            <span class="mt-1 block text-lg font-extrabold text-ink">{{ other.name }}</span>
                            <span class="mt-2 hidden text-sm leading-6 text-muted sm:block">{{ other.copy.card_summary }}</span>
                        </span>
                        <Icon name="arrow-right" class="size-4 shrink-0 text-muted sm:hidden" />
                    </NuxtLink>
                </div>
            </div>
        </section>

        <section v-if="copy.questions.length > 0" class="bg-page py-12 sm:py-20" aria-labelledby="questions-heading">
            <div class="mx-auto grid max-w-6xl gap-8 px-5 sm:px-8 lg:grid-cols-[.7fr_1.3fr]">
                <div>
                    <p class="ui-eyebrow">{{ t('Good to know') }}</p>
                    <h2 id="questions-heading" class="mt-3 text-3xl font-extrabold tracking-tight text-ink">{{ t('Straight answers about :service.', { service: name }) }}</h2>
                </div>
                <div class="grid gap-2">
                    <Disclosure v-for="[question, answer] in copy.questions" :key="question" :title="question"><p class="text-sm leading-6 text-muted">{{ answer }}</p></Disclosure>
                </div>
            </div>
        </section>

        <section class="bg-page px-5 pb-12 sm:px-8 sm:pb-20">
            <div class="ui-emphasis relative mx-auto max-w-6xl overflow-hidden rounded-panel p-6 sm:p-10">
                <div class="absolute -right-16 -top-20 h-64 w-64 rounded-full bg-primary/30 blur-3xl" aria-hidden="true" />
                <div class="relative flex flex-col justify-between gap-7 md:flex-row md:items-end">
                    <div class="max-w-2xl">
                        <p class="text-xs font-extrabold uppercase tracking-[0.15em] text-brand-300">{{ t('Part of :app', { app: 'BuildPusher' }) }}</p>
                        <h2 class="mt-4 text-3xl font-extrabold tracking-tight sm:text-4xl">{{ t('Start with :service.', { service: name }) }}</h2>
                        <p class="ui-emphasis-muted mt-4 max-w-xl leading-7">{{ t('Every service has a free tier. Turn on the others when a project needs them, on the same account and bill.') }}</p>
                    </div>
                    <NuxtLink to="/register" class="ui-btn ui-btn-lg shrink-0 border border-white bg-white text-slate-950 hover:bg-white/90">{{ t('Start free') }} <Icon name="arrow-right" class="size-4" /></NuxtLink>
                </div>
            </div>
        </section>
    </div>
</template>
