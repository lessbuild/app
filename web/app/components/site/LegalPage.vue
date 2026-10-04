<script setup lang="ts">
import type { PageMeta } from '~/types/site';

/** The privacy policy or the terms of service: its sections, when it took effect, and where to send questions. */
const props = defineProps<{ page: 'privacy' | 'terms' }>();
type LegalCopy = { title: string; description: string; sections: Array<[string, string]> };
const { t, date } = useT();
const { data } = await useApi<{ meta: PageMeta; copy: LegalCopy; effectiveDate: string; contactEmail: string; other: { page: string; title: string } }>(() => `/site/legal/${props.page}`);
usePublicPage(() => data.value.meta);
</script>

<template>
    <article class="mx-auto max-w-3xl px-5 py-12 sm:px-8 sm:py-16" aria-labelledby="legal-heading">
        <p class="ui-eyebrow">{{ t(':app legal', { app: 'BuildPusher' }) }}</p>
        <h1 id="legal-heading" class="mt-3 text-4xl font-extrabold tracking-[-0.04em] text-ink sm:text-5xl">{{ data.copy.title }}</h1>
        <p class="mt-4 text-base leading-7 text-muted">{{ data.copy.description }}</p>
        <p class="mt-3 text-sm font-semibold text-subtle">{{ t('Effective :date', { date: date(data.effectiveDate) }) }}</p>
        <div class="mt-10 grid gap-8">
            <section v-for="[heading, text] in data.copy.sections" :key="heading">
                <h2 class="text-lg font-extrabold text-ink">{{ heading }}</h2>
                <p class="mt-2 leading-7 text-muted">{{ text }}</p>
            </section>
            <section>
                <h2 class="text-lg font-extrabold text-ink">{{ t('Contact') }}</h2>
                <p class="mt-2 leading-7 text-muted">{{ t('Questions and requests about your information can be sent to') }} <a :href="`mailto:${data.contactEmail}`" class="font-semibold text-primary hover:underline">{{ data.contactEmail }}</a>.</p>
            </section>
        </div>
        <p class="mt-12 border-t border-line pt-6 text-sm text-muted">
            {{ t('See also') }} <NuxtLink :to="`/${data.other.page}`" class="font-semibold text-primary hover:underline">{{ data.other.title }}</NuxtLink>.
        </p>
    </article>
</template>
