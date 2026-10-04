// The head of a public page: its title, description, one canonical address, link previews and schema.org JSON-LD, and
// permission for search engines to index it (the app's own pages are noindex).

import type { PageMeta } from '~/types/site';

/** Describe a public page in the document head from the meta block its endpoint returns. */
export function usePublicPage(meta: MaybeRefOrGetter<PageMeta>) {
    const value = computed(() => toValue(meta));
    useHead({
        title: () => value.value.title,
        link: [{ rel: 'canonical', href: () => value.value.canonical }],
        meta: [{ name: 'robots', content: 'index, follow' }],
        script: [{ type: 'application/ld+json', innerHTML: () => JSON.stringify(value.value.structuredData).replace(/</g, '\\u003c') }],
    });
    useSeoMeta({
        description: () => value.value.description,
        ogTitle: () => value.value.title,
        ogDescription: () => value.value.description,
        ogUrl: () => value.value.canonical,
        ogImage: () => value.value.image,
        ogType: 'website',
        twitterCard: 'summary_large_image',
    });
}
