<script setup lang="ts">
import type { PageMeta } from '~/types/site';

/** Every release, newest first. */
definePageMeta({ layout: 'public' });
const { t, date } = useT();
const { data } = await useApi<{ meta: PageMeta; entries: Array<{ date: string; title: string; changes: string[] }> }>('/site/changelog');
usePublicPage(() => data.value.meta);
</script>

<template>
    <div>
        <SiteHero :kicker="t('Changelog')" :title="t('What’s new')" :description="t('Improvements to :app, newest first.', { app: 'BuildPusher' })" />
        <SiteSection>
            <ol class="grid gap-0">
                <li v-for="entry in data.entries" :key="`${entry.date}-${entry.title}`" class="grid gap-3 border-b border-line py-10 first:pt-0 last:border-0 last:pb-0 md:grid-cols-[12rem_1fr] md:gap-10">
                    <time class="site-kicker pt-1" :datetime="entry.date">{{ date(entry.date) }}</time>
                    <div>
                        <h2 class="text-xl font-semibold text-ink">{{ entry.title }}</h2>
                        <ul class="mt-3 grid gap-2">
                            <li v-for="change in entry.changes" :key="change" class="flex gap-2 text-sm leading-6 text-muted"><Icon name="check" class="mt-1 size-4 shrink-0 text-primary" /><span>{{ change }}</span></li>
                        </ul>
                    </div>
                </li>
            </ol>
        </SiteSection>
    </div>
</template>
