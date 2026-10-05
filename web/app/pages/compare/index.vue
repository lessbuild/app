<script setup lang="ts">
import type { PageMeta } from '~/types/site';

/**
 * Every comparison page (the Acme theme's comparisons index): a card for each tool, saying what it is and which of our
 * services it overlaps, linking to the side-by-side page.
 */
definePageMeta({ layout: 'public' });
type Brief = { slug: string; name: string; what: string; summary: string; overlaps: Array<{ key: string; name: string }> };
const { t } = useT();
const { data } = await useApi<{ meta: PageMeta; comparisons: Brief[]; checked: string }>('/site/compare');
usePublicPage(() => data.value.meta);
</script>

<template>
    <div>
        <section class="border-b border-line bg-surface-muted">
            <div class="site-frame py-16">
                <p class="site-kicker">{{ t('Compare') }}</p>
                <h1 class="mt-2 text-[clamp(2.25rem,4.5vw,3.25rem)] font-semibold tracking-[-0.035em] text-ink">{{ t('Coming from another tool?') }}</h1>
                <p class="mt-4 max-w-xl text-lg text-muted">{{ t('What’s the same, what’s different, how to move, and when the other tool is the better choice.') }}</p>

                <ul class="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <li v-for="item in data.comparisons" :key="item.slug">
                        <NuxtLink :to="`/compare/${item.slug}`" class="group flex h-full flex-col rounded-xl border border-line bg-surface p-6 transition hover:-translate-y-0.5 hover:shadow-lg">
                            <p class="font-mono text-[0.6875rem] uppercase tracking-wider text-muted">{{ item.what }}</p>
                            <h2 class="mt-2 text-lg font-semibold text-ink group-hover:underline">{{ t(':app vs :other', { app: 'BuildPusher', other: item.name }) }}</h2>
                            <p class="mt-2 flex-1 text-sm text-muted">{{ item.summary }}</p>
                            <p class="mt-4 flex flex-wrap gap-1.5">
                                <span v-for="service in item.overlaps" :key="service.key" class="inline-flex items-center gap-1 rounded-full bg-black/[.05] px-2 py-0.5 text-xs font-medium text-ink dark:bg-white/10"><AcmeIcon :name="serviceStyle(service.key).icon" :size="12" />{{ service.name }}</span>
                            </p>
                        </NuxtLink>
                    </li>
                </ul>
                <p class="mt-6 text-xs text-muted">{{ t('Other products are trademarks of their owners. These pages are based on their public information as of :date; products change, so check their sites for the latest.', { date: data.checked }) }}</p>
            </div>
        </section>

        <section class="border-b border-line">
            <div class="site-frame py-14">
                <div class="site-night grid items-center gap-6 p-8 sm:p-10 md:grid-cols-[1.4fr_1fr]">
                    <div><h2 class="text-2xl font-semibold tracking-tight">{{ t('Every service has a free tier. Upgrade the ones that grow.') }}</h2><p class="mt-2 text-white/65">{{ t('No card to start. Each service has its own plan on one monthly bill, and you can change or cancel any of them whenever you like.') }}</p></div>
                    <div class="flex flex-wrap gap-2 md:justify-end"><NuxtLink to="/register" class="site-btn-light">{{ t('Start free') }}</NuxtLink><NuxtLink to="/pricing" class="site-btn-outline-light">{{ t('See pricing') }}</NuxtLink></div>
                </div>
            </div>
        </section>
    </div>
</template>
