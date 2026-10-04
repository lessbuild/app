<script setup lang="ts">
import type { PageMeta } from '~/types/site';

/**
 * The help centre (the Acme theme's help page): a search, the topics, every guide as a card filtered by topic or by the
 * words typed, and a way to get in touch.
 */
definePageMeta({ layout: 'public' });
type HelpPage = { meta: PageMeta; groups: Array<{ key: string; title: string; summary: string; icon: string; guides: Array<{ slug: string; title: string; summary: string; text: string }> }>; contactEmail: string };
const { t } = useT();
const { data } = await useApi<HelpPage>('/help');
usePublicPage(() => data.value.meta);
const search = ref('');
const all = computed(() => t('All'));
const topic = ref('');
const topics = computed(() => [all.value, ...data.value.groups.map((group) => group.title)]);
const popular = computed(() => [t('domain'), t('rollback'), t('backups'), t('alerts')]);
const words = computed(() => search.value.toLowerCase().split(/\s+/).filter(Boolean));
/** Every guide with its topic, in the chosen topic and matching every word typed. */
const guides = computed(() => data.value.groups
    .flatMap((group) => group.guides.map((guide) => ({ ...guide, topic: group.title, icon: group.icon })))
    .filter((guide) => (!topic.value || topic.value === all.value || guide.topic === topic.value) && words.value.every((word) => guide.text.includes(word))));

/**
 * Show one topic's guides and scroll to them.
 *
 * @param title The topic.
 */
function browse(title: string) {
    topic.value = title;
    search.value = '';
    nextTick(() => document.getElementById('articles')?.scrollIntoView({ behavior: 'smooth' }));
}

/** Clear the search and the topic. */
function reset() {
    search.value = '';
    topic.value = all.value;
}
</script>

<template>
    <div>
        <section class="relative overflow-hidden border-b border-line">
            <div class="site-dots pointer-events-none absolute inset-0" aria-hidden="true" />
            <div class="site-frame relative py-16 sm:py-20">
                <p class="site-kicker">{{ t('Help centre') }}</p>
                <h1 class="mt-2 text-[clamp(2.5rem,5vw,4rem)] font-semibold leading-none tracking-[-0.04em] text-ink">{{ t('How can we help?') }}</h1>
                <p class="mt-4 max-w-xl text-lg text-muted">
                    {{ t('Short, step-by-step guides to every part of :app. For automation, see the', { app: 'BuildPusher' }) }}
                    <NuxtLink to="/docs/api" class="font-medium text-ink underline underline-offset-2">{{ t('API reference') }}</NuxtLink>.
                </p>
                <div class="mt-8 max-w-xl"><AcmeSearchInput v-model="search" :label="t('Search the guides')" :placeholder="t('Search the guides, e.g. “domain” or “rollback”')" class="[&_input]:h-12 [&_input]:rounded-xl [&_input]:text-base [&_input]:shadow-sm" @update:model-value="topic = all" /></div>
                <p class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted">{{ t('Popular:') }} <button v-for="term in popular" :key="term" type="button" class="font-medium text-ink underline underline-offset-2" @click="search = term">{{ term }}</button></p>
            </div>
        </section>

        <section v-if="!search" class="border-b border-line bg-surface-muted">
            <div class="site-frame py-12">
                <h2 class="font-semibold text-ink">{{ t('Browse by topic') }}</h2>
                <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <button v-for="group in data.groups" :key="group.key" type="button" class="flex gap-3 rounded-xl border border-line bg-surface p-4 text-left transition hover:-translate-y-0.5 hover:shadow-md" @click="browse(group.title)">
                        <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-primary-soft text-primary" aria-hidden="true"><Icon :name="group.icon" class="size-4" /></span>
                        <span><span class="block text-sm font-semibold text-ink">{{ group.title }}</span><span class="text-xs text-muted">{{ group.summary }}</span><span class="mt-1 block text-xs font-medium text-ink">{{ t(':count guides', { count: group.guides.length }) }}</span></span>
                    </button>
                </div>
            </div>
        </section>

        <section id="articles" class="scroll-mt-16 border-b border-line" aria-live="polite">
            <div class="site-frame py-12">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="font-semibold text-ink">{{ search ? t('Results for “:query”', { query: search }) : topic && topic !== all ? topic : t('All guides') }} <span class="font-normal text-muted">· {{ guides.length }}</span></h2>
                    <AcmeChipGroup :model-value="topic || all" :options="topics" :label="t('Topic')" @update:model-value="(value: string) => (topic = value)" />
                </div>
                <div v-if="guides.length > 0" class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <NuxtLink v-for="guide in guides" :key="guide.slug" :to="`/help/${guide.slug}`" class="group overflow-hidden rounded-xl border border-line bg-surface transition hover:-translate-y-0.5 hover:shadow-lg">
                        <AcmeCover :seed="guide.topic" aspect="aspect-[16/6]" rounded="rounded-none" :label="guide.topic"><Icon :name="guide.icon" class="size-7 drop-shadow" /></AcmeCover>
                        <div class="p-5">
                            <span class="rounded-full bg-primary-soft px-2 py-0.5 text-xs font-medium text-primary">{{ guide.topic }}</span>
                            <p class="mt-3 font-semibold leading-snug text-ink group-hover:underline">{{ guide.title }}</p>
                            <p class="mt-1 line-clamp-2 text-sm text-muted">{{ guide.summary }}</p>
                        </div>
                    </NuxtLink>
                </div>
                <div v-else class="mt-6 rounded-xl border border-dashed border-line p-10 text-center">
                    <p class="font-medium text-ink">{{ t('No guides match that.') }}</p>
                    <p class="mt-1 text-sm text-muted">{{ t('Try another word, or get in touch below.') }}</p>
                    <button type="button" class="mt-4 rounded-md border border-line px-3 py-1.5 text-sm text-ink" @click="reset">{{ t('Reset filters') }}</button>
                </div>
            </div>
        </section>

        <section class="border-b border-line bg-surface-muted">
            <div class="site-frame py-14">
                <div id="contact" class="max-w-xl rounded-xl border border-line bg-surface p-6">
                    <h2 class="font-semibold text-ink">{{ t('Still stuck?') }}</h2>
                    <p class="mt-1 text-sm text-muted">{{ t('Email us and a person will reply, or send feedback from inside the app.') }}</p>
                    <a :href="`mailto:${data.contactEmail}`" class="site-btn mt-5 w-full"><AcmeIcon name="send" :size="15" />{{ data.contactEmail }}</a>
                </div>
            </div>
        </section>
    </div>
</template>
