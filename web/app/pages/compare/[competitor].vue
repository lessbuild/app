<script setup lang="ts">
import type { PageMeta } from '~/types/site';

/** How BuildPusher compares with another tool, and when to choose each. */
definePageMeta({ layout: 'public' });
type ComparePage = { meta: PageMeta; slug: string; copy: { name: string; summary: string; same: string[]; different: string[]; choose_them: string }; checked: string };
const { t } = useT();
const route = useRoute();
const { data } = await useApi<ComparePage>(() => `/site/compare/${route.params.competitor}`);
usePublicPage(() => data.value.meta);
const copy = computed(() => data.value.copy);
</script>

<template>
    <div>
        <SiteHero :kicker="t('Compare')" :title="t(':app vs :other', { app: 'BuildPusher', other: copy.name })" :description="`${copy.summary} ${t(':app puts deploys, servers, monitoring and analytics in one account.', { app: 'BuildPusher' })}`" />
        <SiteSection tint frame-class="grid gap-4 md:grid-cols-2">
            <section class="site-card p-6" aria-labelledby="same-heading">
                <h2 id="same-heading" class="font-semibold text-ink">{{ t('What’s alike') }}</h2>
                <ul class="mt-4 grid gap-3">
                    <li v-for="line in copy.same" :key="line" class="flex gap-2 text-sm leading-6 text-muted"><Icon name="check" class="mt-1 size-4 shrink-0 text-subtle" /><span>{{ line }}</span></li>
                </ul>
            </section>
            <section class="site-card p-6" aria-labelledby="different-heading">
                <h2 id="different-heading" class="font-semibold text-ink">{{ t('Where :app is different', { app: 'BuildPusher' }) }}</h2>
                <ul class="mt-4 grid gap-3">
                    <li v-for="line in copy.different" :key="line" class="flex gap-2 text-sm leading-6 text-ink"><Icon name="check" class="mt-1 size-4 shrink-0 text-primary" /><span>{{ line }}</span></li>
                </ul>
            </section>
        </SiteSection>
        <SiteSection pad="md" labelledby="choose-heading">
            <h2 id="choose-heading" class="site-h2">{{ t('Which to choose') }}</h2>
            <p class="mt-4 max-w-3xl leading-7 text-muted"><span class="font-semibold text-ink">{{ t('Choose :other if', { other: copy.name }) }}</span> {{ copy.choose_them.charAt(0).toLowerCase() + copy.choose_them.slice(1) }}</p>
            <p class="mt-2 max-w-3xl leading-7 text-muted"><span class="font-semibold text-ink">{{ t('Choose :app if', { app: 'BuildPusher' }) }}</span> {{ t('you want deploys, the servers they run on, monitoring and analytics to work together, on one bill.') }}</p>
            <div class="mt-6 flex flex-wrap gap-2">
                <NuxtLink to="/register" class="site-btn">{{ t('Start free') }}</NuxtLink>
                <NuxtLink to="/pricing" class="site-btn-2">{{ t('See pricing') }}</NuxtLink>
            </div>
            <p class="mt-10 text-xs text-muted">{{ t(':other is a trademark of its owner. This page is based on their public information as of :date; products change, so check their site for the latest.', { other: copy.name, date: data.checked }) }}</p>
        </SiteSection>
    </div>
</template>
