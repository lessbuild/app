<script setup lang="ts">
import type { PageMeta } from '~/types/site';

/** The help centre: every guide by group, filtered as you type. */
definePageMeta({ layout: 'public' });
type HelpPage = { meta: PageMeta; groups: Array<{ key: string; title: string; summary: string; icon: string; guides: Array<{ slug: string; title: string; summary: string; text: string }> }>; contactEmail: string };
const { t } = useT();
const { data } = await useApi<HelpPage>('/help');
usePublicPage(() => data.value.meta);
const search = ref('');
const words = computed(() => search.value.toLowerCase().split(/\s+/).filter(Boolean));
/** The groups with the guides that match every word typed, leaving out groups with none. */
const groups = computed(() => data.value.groups
    .map((group) => ({ ...group, guides: group.guides.filter((guide) => words.value.every((word) => guide.text.includes(word))) }))
    .filter((group) => group.guides.length > 0));
</script>

<template>
    <div>
        <section class="border-b border-line bg-surface">
            <div class="mx-auto max-w-6xl px-5 py-12 sm:px-8 sm:py-16">
                <p class="ui-eyebrow">{{ t('Help centre') }}</p>
                <h1 class="mt-3 text-4xl font-extrabold tracking-[-0.04em] text-ink sm:text-5xl">{{ t('How can we help?') }}</h1>
                <p class="mt-4 max-w-2xl text-base leading-7 text-muted">
                    {{ t('Short, step-by-step guides to every part of :app. For automation, see the', { app: 'BuildPusher' }) }}
                    <NuxtLink to="/docs/api" class="font-semibold text-primary hover:underline">{{ t('API reference') }}</NuxtLink>.
                </p>
                <label class="mt-8 block max-w-xl">
                    <span class="sr-only">{{ t('Search the guides') }}</span>
                    <input v-model="search" type="search" class="ui-input" :placeholder="t('Search the guides, e.g. “domain” or “rollback”')" autocomplete="off">
                </label>
            </div>
        </section>
        <div class="mx-auto grid max-w-6xl gap-10 px-5 py-12 sm:px-8 sm:py-16" aria-live="polite">
            <section v-for="group in groups" :key="group.key" :aria-labelledby="`help-${group.key}`">
                <div class="flex items-center gap-3">
                    <span class="grid size-10 place-items-center rounded-xl bg-primary-soft text-primary" aria-hidden="true"><Icon :name="group.icon" class="size-5" /></span>
                    <div>
                        <h2 :id="`help-${group.key}`" class="text-xl font-extrabold text-ink">{{ group.title }}</h2>
                        <p class="text-sm text-muted">{{ group.summary }}</p>
                    </div>
                </div>
                <ul class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <li v-for="guide in group.guides" :key="guide.slug">
                        <NuxtLink :to="`/help/${guide.slug}`" class="ui-card ui-card--interactive block h-full p-5">
                            <span class="block font-extrabold text-ink">{{ guide.title }}</span>
                            <span class="mt-2 block text-sm leading-6 text-muted">{{ guide.summary }}</span>
                        </NuxtLink>
                    </li>
                </ul>
            </section>
            <p v-if="groups.length === 0" class="text-sm text-muted">
                {{ t('No guides match that. Try another word, or') }} <a :href="`mailto:${data.contactEmail}`" class="font-semibold text-primary hover:underline">{{ t('email us') }}</a>.
            </p>
        </div>
    </div>
</template>
