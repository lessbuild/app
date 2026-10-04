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
    <article aria-labelledby="legal-heading">
        <section class="border-b border-line">
            <div class="site-frame py-14 lg:py-20">
                <p class="site-kicker">{{ t(':app legal', { app: 'BuildPusher' }) }}</p>
                <h1 id="legal-heading" class="site-h1 mt-3">{{ data.copy.title }}</h1>
                <p class="mt-5 max-w-2xl text-lg leading-8 text-muted">{{ data.copy.description }}</p>
                <p class="mt-3 text-sm text-muted">{{ t('Effective :date', { date: date(data.effectiveDate) }) }}</p>
            </div>
        </section>
        <SiteSection>
            <div class="grid max-w-3xl gap-8">
                <section v-for="[heading, text] in data.copy.sections" :key="heading">
                    <h2 class="text-lg font-semibold text-ink">{{ heading }}</h2>
                    <p class="mt-2 leading-7 text-muted">{{ text }}</p>
                </section>
                <section>
                    <h2 class="text-lg font-semibold text-ink">{{ t('Contact') }}</h2>
                    <p class="mt-2 leading-7 text-muted">{{ t('Questions and requests about your information can be sent to') }} <a :href="`mailto:${data.contactEmail}`" class="font-medium text-primary hover:underline">{{ data.contactEmail }}</a>.</p>
                </section>
                <p class="border-t border-line pt-6 text-sm text-muted">
                    {{ t('See also') }} <NuxtLink :to="`/${data.other.page}`" class="font-medium text-primary hover:underline">{{ data.other.title }}</NuxtLink>.
                </p>
            </div>
        </SiteSection>
    </article>
</template>
