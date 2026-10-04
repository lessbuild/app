<script setup lang="ts">
import type { PageMeta } from '~/types/site';

/** Every release, newest first. */
definePageMeta({ layout: 'public' });
const { t, date } = useT();
const { data } = await useApi<{ meta: PageMeta; entries: Array<{ date: string; title: string; changes: string[] }> }>('/site/changelog');
usePublicPage(() => data.value.meta);
</script>

<template>
    <div class="mx-auto max-w-3xl px-5 py-12 sm:px-8 sm:py-16">
        <p class="ui-eyebrow">{{ t('Changelog') }}</p>
        <h1 class="mt-3 text-4xl font-extrabold tracking-[-0.04em] text-ink sm:text-5xl">{{ t('What’s new') }}</h1>
        <p class="mt-4 text-base leading-7 text-muted">{{ t('Improvements to :app, newest first.', { app: 'BuildPusher' }) }}</p>
        <ol class="mt-10 grid gap-10 border-l border-line pl-6">
            <li v-for="entry in data.entries" :key="`${entry.date}-${entry.title}`" class="relative">
                <span class="absolute -left-[31px] top-1.5 size-3 rounded-full border-2 border-surface bg-primary" aria-hidden="true" />
                <time class="text-sm font-semibold text-subtle" :datetime="entry.date">{{ date(entry.date) }}</time>
                <h2 class="mt-1 text-xl font-extrabold text-ink">{{ entry.title }}</h2>
                <ul class="mt-3 grid gap-2">
                    <li v-for="change in entry.changes" :key="change" class="flex gap-2 text-sm leading-6 text-muted"><Icon name="check" class="mt-1 size-4 shrink-0 text-success" /><span>{{ change }}</span></li>
                </ul>
            </li>
        </ol>
    </div>
</template>
