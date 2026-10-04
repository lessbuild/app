<script setup lang="ts">
import type { PageMeta } from '~/types/site';

/** A help guide: its steps, and the other guides in its group. */
definePageMeta({ layout: 'public' });
type GuidePage = { meta: PageMeta; slug: string; title: string; summary: string; group: string; steps: Array<{ title: string; text: string }>; related: Array<{ slug: string; title: string }>; contactEmail: string };
const { t } = useT();
const route = useRoute();
const { data } = await useApi<GuidePage>(() => `/help/${route.params.guide}`);
usePublicPage(() => data.value.meta);
</script>

<template>
    <SiteSection frame-class="grid gap-10 lg:grid-cols-[1fr_18rem]">
        <article aria-labelledby="guide-heading">
            <NuxtLink to="/help" class="inline-flex items-center gap-1 text-sm text-muted hover:text-ink"><Icon name="chevron-left" class="size-3.5" />{{ t('Help centre') }}</NuxtLink>
            <p class="site-kicker mt-6">{{ data.group }}</p>
            <h1 id="guide-heading" class="site-h2 mt-3">{{ data.title }}</h1>
            <p class="mt-4 text-lg leading-8 text-muted">{{ data.summary }}</p>
            <ol class="mt-8 grid gap-3">
                <li v-for="(step, index) in data.steps" :key="step.title" class="site-card flex gap-4 p-5">
                    <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-primary-soft font-mono text-xs font-semibold text-primary">{{ String(index + 1).padStart(2, '0') }}</span>
                    <div>
                        <h2 class="font-semibold text-ink">{{ step.title }}</h2>
                        <p class="mt-1 text-sm leading-6 text-muted">{{ step.text }}</p>
                    </div>
                </li>
            </ol>
            <p class="mt-8 text-sm text-muted">
                {{ t('Still stuck?') }} <a :href="`mailto:${data.contactEmail}`" class="font-medium text-primary hover:underline">{{ t('Email us') }}</a>{{ t(', or send feedback from inside the app.') }}
            </p>
        </article>
        <aside v-if="data.related.length > 0" class="lg:border-l lg:border-line lg:pl-8" aria-labelledby="related-heading">
            <h2 id="related-heading" class="site-kicker">{{ t('More about :group', { group: data.group }) }}</h2>
            <ul class="mt-4 grid gap-1">
                <li v-for="other in data.related" :key="other.slug"><NuxtLink :to="`/help/${other.slug}`" class="block rounded-md px-3 py-2 text-sm text-muted hover:bg-[var(--acme-hover)] hover:text-ink">{{ other.title }}</NuxtLink></li>
            </ul>
        </aside>
    </SiteSection>
</template>
