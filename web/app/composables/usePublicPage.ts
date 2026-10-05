// The head of a public page: its title, description, one canonical address, link previews and schema.org JSON-LD, and
// permission for search engines to index it where the site is indexable (production; previews stay out of search).

import type { PageMeta } from '~/types/site';

/** Describe a public page in the document head from the meta block its endpoint returns. */
export function usePublicPage(meta: MaybeRefOrGetter<PageMeta>) {
    const value = computed(() => toValue(meta));
    const { locale } = useT();
    const indexable = useRuntimeConfig().public.indexable;
    useHead({
        title: () => value.value.title,
        link: [{ rel: 'canonical', href: () => value.value.canonical }],
        meta: [{ name: 'robots', content: indexable ? 'index, follow, max-image-preview:large, max-snippet:-1' : 'noindex, nofollow' }],
        script: [{ type: 'application/ld+json', innerHTML: () => JSON.stringify(value.value.structuredData).replace(/</g, '\\u003c') }],
    });
    useSeoMeta({
        description: () => value.value.description,
        ogSiteName: 'BuildPusher',
        ogTitle: () => value.value.title,
        ogDescription: () => value.value.description,
        ogUrl: () => value.value.canonical,
        ogImage: () => value.value.image,
        ogImageWidth: 1200,
        ogImageHeight: 630,
        ogImageAlt: () => value.value.title,
        ogLocale: () => toValue(locale).replace('-', '_'),
        ogType: 'website',
        twitterCard: 'summary_large_image',
        twitterTitle: () => value.value.title,
        twitterDescription: () => value.value.description,
        twitterImage: () => value.value.image,
    });
}
