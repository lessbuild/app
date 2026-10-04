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
    <div class="mx-auto max-w-4xl px-5 py-12 sm:px-8 sm:py-16">
        <p class="ui-eyebrow">{{ t('Compare') }}</p>
        <h1 class="mt-3 text-4xl font-extrabold tracking-[-0.04em] text-ink sm:text-5xl">{{ t(':app vs :other', { app: 'BuildPusher', other: copy.name }) }}</h1>
        <p class="mt-4 max-w-2xl text-base leading-7 text-muted">{{ copy.summary }} {{ t(':app puts deploys, servers, monitoring and analytics in one account.', { app: 'BuildPusher' }) }}</p>
        <div class="mt-10 grid gap-5 md:grid-cols-2">
            <section class="ui-card p-6" aria-labelledby="same-heading">
                <h2 id="same-heading" class="font-extrabold text-ink">{{ t('What’s alike') }}</h2>
                <ul class="mt-4 grid gap-3">
                    <li v-for="line in copy.same" :key="line" class="flex gap-2 text-sm leading-6 text-muted"><Icon name="check" class="mt-1 size-4 shrink-0 text-subtle" /><span>{{ line }}</span></li>
                </ul>
            </section>
            <section class="ui-card p-6" aria-labelledby="different-heading">
                <h2 id="different-heading" class="font-extrabold text-ink">{{ t('Where :app is different', { app: 'BuildPusher' }) }}</h2>
                <ul class="mt-4 grid gap-3">
                    <li v-for="line in copy.different" :key="line" class="flex gap-2 text-sm leading-6 text-ink"><Icon name="check" class="mt-1 size-4 shrink-0 text-success" /><span>{{ line }}</span></li>
                </ul>
            </section>
        </div>
        <section class="ui-card mt-5 p-6" aria-labelledby="choose-heading">
            <h2 id="choose-heading" class="font-extrabold text-ink">{{ t('Which to choose') }}</h2>
            <p class="mt-3 text-sm leading-6 text-muted"><span class="font-semibold text-ink">{{ t('Choose :other if', { other: copy.name }) }}</span> {{ copy.choose_them.charAt(0).toLowerCase() + copy.choose_them.slice(1) }}</p>
            <p class="mt-2 text-sm leading-6 text-muted"><span class="font-semibold text-ink">{{ t('Choose :app if', { app: 'BuildPusher' }) }}</span> {{ t('you want deploys, the servers they run on, monitoring and analytics to work together, on one bill.') }}</p>
            <div class="mt-5 flex flex-wrap gap-3">
                <NuxtLink to="/register" class="ui-btn ui-btn-primary">{{ t('Start free') }}</NuxtLink>
                <NuxtLink to="/pricing" class="ui-btn ui-btn-secondary">{{ t('See pricing') }}</NuxtLink>
            </div>
        </section>
        <p class="mt-8 text-xs text-subtle">{{ t(':other is a trademark of its owner. This page is based on their public information as of :date; products change, so check their site for the latest.', { other: copy.name, date: data.checked }) }}</p>
    </div>
</template>
