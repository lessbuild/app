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
        <SiteHero :kicker="t('Help centre')" :title="t('How can we help?')">
            <p class="mt-5 max-w-2xl text-lg leading-8 text-muted">
                {{ t('Short, step-by-step guides to every part of :app. For automation, see the', { app: 'BuildPusher' }) }}
                <NuxtLink to="/docs/api" class="font-medium text-primary hover:underline">{{ t('API reference') }}</NuxtLink>.
            </p>
            <label class="mt-8 block max-w-xl">
                <span class="sr-only">{{ t('Search the guides') }}</span>
                <span class="relative block">
                    <Icon name="search" class="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-muted" />
                    <input v-model="search" type="search" class="ui-input pl-10" :placeholder="t('Search the guides, e.g. “domain” or “rollback”')" autocomplete="off">
                </span>
            </label>
        </SiteHero>
        <div aria-live="polite">
            <SiteSection v-for="(group, index) in groups" :key="group.key" :tint="index % 2 === 1" pad="md" :labelledby="`help-${group.key}`">
                <div class="flex items-center gap-3">
                    <span class="grid size-10 place-items-center rounded-lg bg-primary-soft text-primary" aria-hidden="true"><Icon :name="group.icon" class="size-[1.125rem]" /></span>
                    <div>
                        <h2 :id="`help-${group.key}`" class="text-xl font-semibold text-ink">{{ group.title }}</h2>
                        <p class="text-sm text-muted">{{ group.summary }}</p>
                    </div>
                </div>
                <ul class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <li v-for="guide in group.guides" :key="guide.slug">
                        <NuxtLink :to="`/help/${guide.slug}`" class="site-card site-card-link block h-full p-5">
                            <span class="block font-semibold text-ink">{{ guide.title }}</span>
                            <span class="mt-2 block text-sm leading-6 text-muted">{{ guide.summary }}</span>
                        </NuxtLink>
                    </li>
                </ul>
            </SiteSection>
            <SiteSection v-if="groups.length === 0" pad="md">
                <p class="text-sm text-muted">
                    {{ t('No guides match that. Try another word, or') }} <a :href="`mailto:${data.contactEmail}`" class="font-medium text-primary hover:underline">{{ t('email us') }}</a>.
                </p>
            </SiteSection>
        </div>
    </div>
</template>
