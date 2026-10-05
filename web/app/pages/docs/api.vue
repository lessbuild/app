<script setup lang="ts">
import type { PageMeta } from '~/types/site';
import type { AcmeTone } from '~/utils/acme';

/**
 * The public API's operations by group, from its OpenAPI description (the Acme theme's docs page): sections down the
 * side, filtered as you type, and the one in view marked under "On this page".
 */
definePageMeta({ layout: 'public' });
type ApiPage = { meta: PageMeta; description: string; groups: Array<{ tag: string; operations: Array<{ method: string; path: string; summary: string; scopes: string[] }> }>; openApiUrl: string };
const { t } = useT();
const { data } = await useApi<ApiPage>('/docs/api');
usePublicPage(() => data.value.meta);
const query = ref('');
const active = ref('introduction');
/** An id for a group's section. */
const slug = (text: string) => `api-${text.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`;
const sections = computed(() => [{ id: 'introduction', label: t('Introduction') }, ...data.value.groups.map((group) => ({ id: slug(group.tag), label: group.tag }))]);
const filtered = computed(() => sections.value.filter((section) => section.label.toLowerCase().includes(query.value.trim().toLowerCase())));
/** The badge colour for an HTTP method. */
const tone = (method: string) => ({ GET: 'blue', POST: 'green', PUT: 'amber', PATCH: 'amber', DELETE: 'red' } as Record<string, AcmeTone>)[method] ?? 'gray';

let observer: IntersectionObserver | null = null;
onMounted(() => {
    // Mark the section in view under "On this page".
    observer = new IntersectionObserver((entries) => {
        const visible = entries.filter((entry) => entry.isIntersecting).sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top)[0];
        if (visible) {
            active.value = visible.target.id;
        }
    }, { rootMargin: '-80px 0px -60% 0px' });
    document.querySelectorAll('[data-api-section]').forEach((element) => observer?.observe(element));
});
onBeforeUnmount(() => observer?.disconnect());
</script>

<template>
    <div class="mx-auto flex max-w-7xl">
        <nav class="sticky top-16 hidden h-[calc(100dvh-4rem)] w-60 shrink-0 overflow-y-auto border-r border-line p-4 lg:block" :aria-label="t('API reference')">
            <AcmeSearchInput v-model="query" :label="t('Search the reference')" class="mb-5" />
            <h2 class="section-label mb-2 px-2">{{ t('API reference') }}</h2>
            <a v-for="section in filtered" :key="section.id" :href="`#${section.id}`" :class="['block rounded-md px-2 py-1.5 text-sm', active === section.id ? 'bg-accent/10 font-medium text-ink' : 'text-muted hover:text-ink']">{{ section.label }}</a>
            <p v-if="filtered.length === 0" class="px-2 text-sm text-muted">{{ t('No results for “:query”.', { query }) }}</p>
        </nav>
        <div class="min-w-0 flex-1 px-4 py-10 sm:px-10">
            <article class="max-w-3xl space-y-16">
                <section id="introduction" data-api-section class="scroll-mt-24">
                    <p class="text-sm font-medium text-accent">{{ t('API reference') }}</p>
                    <h1 class="mt-2 text-4xl font-semibold tracking-tight text-ink">{{ t('Introduction') }}</h1>
                    <p class="mt-4 text-lg leading-relaxed text-muted">{{ data.description }}</p>
                    <AcmeAlert tone="info" :title="t('API tokens')" class="mt-6">{{ t('Create tokens under Account → API tokens.') }} <a :href="data.openApiUrl" class="font-medium underline">{{ t('OpenAPI description (JSON)') }}</a></AcmeAlert>
                </section>
                <section v-for="group in data.groups" :id="slug(group.tag)" :key="group.tag" data-api-section class="scroll-mt-24">
                    <h2 class="text-2xl font-semibold text-ink">{{ group.tag }}</h2>
                    <div class="mt-6 divide-y divide-line border-y border-line">
                        <div v-for="operation in group.operations" :key="`${operation.method}-${operation.path}`" class="py-4">
                            <h3 class="flex flex-wrap items-center gap-3 font-semibold text-ink">{{ operation.summary }} <AcmeBadge :tone="tone(operation.method)" class="font-mono">{{ operation.method }}</AcmeBadge></h3>
                            <p class="mt-1 break-all font-mono text-sm text-muted">{{ operation.path }}</p>
                            <p v-if="operation.scopes.length > 0" class="mt-2 flex flex-wrap items-center gap-1.5 text-xs text-muted">{{ t('Scope') }}: <code v-for="scope in operation.scopes" :key="scope" class="rounded bg-black/[.05] px-1 font-mono dark:bg-white/10">{{ scope }}</code></p>
                        </div>
                    </div>
                </section>
            </article>
        </div>
        <aside class="sticky top-16 hidden h-fit w-56 shrink-0 p-6 xl:block" :aria-label="t('On this page')">
            <h2 class="section-label mb-3">{{ t('On this page') }}</h2>
            <a v-for="section in sections" :key="section.id" :href="`#${section.id}`" :class="['block border-l-2 py-1 pl-3 text-sm', active === section.id ? 'border-accent font-medium text-ink' : 'border-line text-muted hover:text-ink']">{{ section.label }}</a>
        </aside>
    </div>
</template>
