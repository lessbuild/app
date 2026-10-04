<script setup lang="ts">
import type { PageMeta } from '~/types/site';

/** How BuildPusher compares with another tool, and when to choose each (the Acme theme's Stratus comparison page). */
definePageMeta({ layout: 'public' });
type ComparePage = { meta: PageMeta; slug: string; copy: { name: string; summary: string; same: string[]; different: string[]; choose_them: string }; checked: string };
const { t } = useT();
const route = useRoute();
const { data } = await useApi<ComparePage>(() => `/site/compare/${route.params.competitor}`);
usePublicPage(() => data.value.meta);
const copy = computed(() => data.value.copy);
const sentence = (text: string) => text.charAt(0).toUpperCase() + text.slice(1);
</script>

<template>
    <div>
        <section class="relative overflow-hidden border-b border-line">
            <div class="site-dots pointer-events-none absolute inset-0" aria-hidden="true" />
            <div class="site-frame relative py-16 text-center sm:py-20">
                <NuxtLink to="/pricing" class="inline-flex items-center gap-1 text-sm text-muted hover:text-ink"><AcmeIcon name="chevronLeft" :size="14" />{{ t('Pricing') }}</NuxtLink>
                <h1 class="mx-auto mt-6 max-w-3xl text-[clamp(2.25rem,4.8vw,3.75rem)] font-semibold leading-[1.05] tracking-[-0.035em] text-ink">{{ t(':app vs :other', { app: 'BuildPusher', other: copy.name }) }}</h1>
                <p class="mx-auto mt-5 max-w-2xl text-lg text-muted">{{ copy.summary }} {{ t(':app puts deploys, servers, monitoring and analytics in one account.', { app: 'BuildPusher' }) }}</p>
                <div class="mt-8 flex flex-wrap justify-center gap-2"><NuxtLink to="/register" class="site-btn">{{ t('Start free') }}</NuxtLink><NuxtLink to="/help" class="site-btn-2">{{ t('Help centre') }}</NuxtLink></div>
            </div>
        </section>

        <section class="border-b border-line bg-surface-muted">
            <div class="site-frame py-16">
                <h2 class="text-center text-[clamp(1.5rem,2.8vw,2.25rem)] font-semibold tracking-[-0.03em] text-ink">{{ t('Which to choose') }}</h2>
                <div class="mx-auto mt-10 grid max-w-4xl gap-4 md:grid-cols-2">
                    <article class="site-night p-7 shadow-[0_20px_50px_-20px_rgb(60_40_140/.5)] ring-2 ring-primary">
                        <h3 class="text-xl font-semibold">{{ t('Choose :app if', { app: 'BuildPusher' }) }}…</h3>
                        <p class="mt-2 text-white/75">{{ sentence(t('you want deploys, the servers they run on, monitoring and analytics to work together, on one bill.')) }}</p>
                        <ul class="mt-5 space-y-3 text-sm"><li v-for="line in copy.different" :key="line" class="flex gap-2"><AcmeIcon name="check" :size="15" class="mt-0.5 shrink-0 text-[#c4b5fd]" />{{ line }}</li></ul>
                    </article>
                    <article class="rounded-2xl border border-line bg-surface p-7">
                        <h3 class="text-xl font-semibold text-ink">{{ t('Choose :other if', { other: copy.name }) }}…</h3>
                        <p class="mt-2 text-muted">{{ sentence(copy.choose_them) }}</p>
                    </article>
                </div>
                <div class="mt-8 text-center"><NuxtLink to="/register" class="site-btn">{{ t('Start free') }}</NuxtLink></div>
            </div>
        </section>

        <section class="border-b border-line">
            <div class="site-frame py-16">
                <h2 class="text-center text-[clamp(1.5rem,2.8vw,2.25rem)] font-semibold tracking-[-0.03em] text-ink">{{ t('What’s alike') }}</h2>
                <ul class="mx-auto mt-10 grid max-w-4xl gap-3 md:grid-cols-2">
                    <li v-for="line in copy.same" :key="line" class="flex gap-3 rounded-xl border border-line bg-surface p-5 text-sm text-ink"><AcmeIcon name="check" :size="16" class="mt-0.5 shrink-0 text-muted" />{{ line }}</li>
                </ul>
            </div>
        </section>

        <section class="border-b border-line">
            <div class="site-frame py-14">
                <div class="site-night grid items-center gap-6 p-8 sm:p-10 md:grid-cols-[1.4fr_1fr]">
                    <div><h2 class="text-2xl font-semibold tracking-tight">{{ t('Every service has a free tier. Upgrade the ones that grow.') }}</h2><p class="mt-2 text-white/65">{{ t('No card to start. Each service has its own plan on one monthly bill, and you can change or cancel any of them whenever you like.') }}</p></div>
                    <div class="flex flex-wrap gap-2 md:justify-end"><NuxtLink to="/register" class="site-btn-light">{{ t('Start free') }}</NuxtLink><NuxtLink to="/pricing" class="site-btn-outline-light">{{ t('See pricing') }}</NuxtLink></div>
                </div>
                <p class="mt-6 text-xs text-muted">{{ t(':other is a trademark of its owner. This page is based on their public information as of :date; products change, so check their site for the latest.', { other: copy.name, date: data.checked }) }}</p>
            </div>
        </section>
    </div>
</template>
