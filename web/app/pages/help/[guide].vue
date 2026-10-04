<script setup lang="ts">
import type { PageMeta } from '~/types/site';

/** A help guide (the Acme theme's article page): breadcrumbs, its steps, an "on this page" list and related guides. */
definePageMeta({ layout: 'public' });
type GuidePage = { meta: PageMeta; slug: string; title: string; summary: string; group: string; steps: Array<{ title: string; text: string }>; related: Array<{ slug: string; title: string }>; contactEmail: string };
const { t } = useT();
const route = useRoute();
const { data } = await useApi<GuidePage>(() => `/help/${route.params.guide}`);
usePublicPage(() => data.value.meta);
/** An id for a step's heading. */
const anchor = (index: number) => `step-${index + 1}`;
</script>

<template>
    <section class="border-b border-line">
        <div class="site-frame grid gap-10 py-12 lg:grid-cols-[1fr_16rem]">
            <article class="min-w-0 max-w-2xl" aria-labelledby="guide-heading">
                <nav class="flex flex-wrap items-center gap-1.5 text-sm text-muted" :aria-label="t('Breadcrumb')"><NuxtLink to="/help" class="hover:text-ink">{{ t('Help centre') }}</NuxtLink><AcmeIcon name="chevronRight" :size="13" /><span>{{ data.group }}</span></nav>
                <h1 id="guide-heading" class="mt-4 text-[clamp(1.75rem,3.5vw,2.5rem)] font-semibold leading-tight tracking-[-0.03em] text-ink">{{ data.title }}</h1>
                <p class="mt-3 text-lg text-muted">{{ data.summary }}</p>
                <div class="mt-10 space-y-10">
                    <section v-for="(step, index) in data.steps" :id="anchor(index)" :key="step.title" class="scroll-mt-20">
                        <h2 class="flex items-center gap-3 text-xl font-semibold tracking-tight text-ink"><span class="grid size-7 shrink-0 place-items-center rounded-full bg-primary-soft font-mono text-xs font-semibold text-primary">{{ index + 1 }}</span>{{ step.title }}</h2>
                        <p class="mt-3 text-[1.0625rem] leading-relaxed text-ink">{{ step.text }}</p>
                    </section>
                </div>
                <div class="mt-12 flex flex-wrap items-center gap-3 border-t border-line pt-6 text-sm">
                    <span class="text-muted">{{ t('Still stuck?') }}</span>
                    <a :href="`mailto:${data.contactEmail}`" class="font-medium text-ink underline underline-offset-2">{{ t('Email us') }}</a>
                    <span class="text-muted">{{ t(', or send feedback from inside the app.') }}</span>
                </div>
            </article>
            <aside class="space-y-8 lg:sticky lg:top-20 lg:self-start">
                <div v-if="data.steps.length > 1">
                    <h2 class="site-kicker">{{ t('On this page') }}</h2>
                    <ul class="mt-3 space-y-2 border-l border-line text-sm"><li v-for="(step, index) in data.steps" :key="step.title"><a :href="`#${anchor(index)}`" class="-ml-px block border-l border-transparent pl-3 text-muted hover:border-primary hover:text-primary">{{ step.title }}</a></li></ul>
                </div>
                <div v-if="data.related.length > 0">
                    <h2 class="site-kicker">{{ t('More about :group', { group: data.group }) }}</h2>
                    <ul class="mt-3 space-y-2"><li v-for="other in data.related" :key="other.slug"><NuxtLink :to="`/help/${other.slug}`" class="block rounded-lg border border-line bg-surface p-3 text-sm transition hover:shadow-sm"><span class="font-medium text-ink">{{ other.title }}</span></NuxtLink></li></ul>
                </div>
            </aside>
        </div>
    </section>
</template>
