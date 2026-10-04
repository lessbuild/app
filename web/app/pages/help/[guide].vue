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
    <div class="mx-auto grid max-w-6xl gap-10 px-5 py-12 sm:px-8 sm:py-16 lg:grid-cols-[1fr_18rem]">
        <article aria-labelledby="guide-heading">
            <NuxtLink to="/help" class="text-sm font-bold text-muted hover:text-ink"><span aria-hidden="true">←</span> {{ t('Help centre') }}</NuxtLink>
            <p class="ui-eyebrow mt-6">{{ data.group }}</p>
            <h1 id="guide-heading" class="mt-3 text-4xl font-extrabold tracking-[-0.04em] text-ink">{{ data.title }}</h1>
            <p class="mt-4 text-base leading-7 text-muted">{{ data.summary }}</p>
            <ol class="mt-8 grid gap-4">
                <li v-for="(step, index) in data.steps" :key="step.title" class="ui-card flex gap-4 p-5">
                    <span class="grid size-8 shrink-0 place-items-center rounded-full bg-primary-soft text-xs font-extrabold text-primary">{{ index + 1 }}</span>
                    <div>
                        <h2 class="font-extrabold text-ink">{{ step.title }}</h2>
                        <p class="mt-1 text-sm leading-6 text-muted">{{ step.text }}</p>
                    </div>
                </li>
            </ol>
            <p class="mt-8 text-sm text-muted">
                {{ t('Still stuck?') }} <a :href="`mailto:${data.contactEmail}`" class="font-semibold text-primary hover:underline">{{ t('Email us') }}</a>{{ t(', or send feedback from inside the app.') }}
            </p>
        </article>
        <aside v-if="data.related.length > 0" aria-labelledby="related-heading">
            <h2 id="related-heading" class="text-xs font-extrabold uppercase tracking-[0.16em] text-subtle">{{ t('More about :group', { group: data.group }) }}</h2>
            <ul class="mt-4 grid gap-2">
                <li v-for="other in data.related" :key="other.slug"><NuxtLink :to="`/help/${other.slug}`" class="block rounded-control px-3 py-2 text-sm font-semibold text-muted hover:bg-surface-muted hover:text-ink">{{ other.title }}</NuxtLink></li>
            </ul>
        </aside>
    </div>
</template>
