<script setup lang="ts">
import type { PageMeta } from '~/types/site';

/** Every release, newest first (the Acme theme's changelog page). */
definePageMeta({ layout: 'public' });
const { t, date } = useT();
const { data } = await useApi<{ meta: PageMeta; entries: Array<{ date: string; title: string; changes: string[] }> }>('/site/changelog');
usePublicPage(() => data.value.meta);
</script>

<template>
    <div class="mx-auto max-w-5xl px-5 py-16">
        <h1 class="text-4xl font-semibold tracking-tight text-ink sm:text-5xl">{{ t('Changelog') }}</h1>
        <p class="mt-3 text-lg text-muted">{{ t('Improvements to :app, newest first.', { app: 'BuildPusher' }) }}</p>
        <div class="mt-14 space-y-16">
            <article v-for="entry in data.entries" :id="entry.date" :key="`${entry.date}-${entry.title}`" class="grid gap-4 md:grid-cols-[12rem_1fr] md:gap-10">
                <div class="md:sticky md:top-24 md:h-fit"><time class="text-sm text-muted" :datetime="entry.date">{{ date(entry.date) }}</time></div>
                <div>
                    <AcmeCover :seed="entry.title" icon="sparkle" aspect="aspect-[16/7]" class="mb-6" :label="entry.title" />
                    <h2 class="mt-3 text-2xl font-semibold text-ink">{{ entry.title }}</h2>
                    <ul class="mt-4 space-y-2 text-ink"><li v-for="change in entry.changes" :key="change" class="flex gap-2.5"><AcmeIcon name="check" :size="16" class="mt-1 shrink-0 text-emerald-600" />{{ change }}</li></ul>
                </div>
            </article>
        </div>
    </div>
</template>
