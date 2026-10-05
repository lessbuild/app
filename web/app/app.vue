<script setup lang="ts">
import themeBoot from '~/assets/js/theme-boot.js?raw';

/**
 * The document around every page, with the Signal theme settings, applied before the first paint, and whether search
 * engines may index it (production only; a page can still ask not to be).
 */
const { locale } = useT();
const config = useRuntimeConfig();

useHead({
    // The site's name after the page's, unless the title already has it ("BuildPusher vs Ploi").
    titleTemplate: (title) => (!title ? 'BuildPusher' : title.includes('BuildPusher') ? title : `${title} · BuildPusher`),
    htmlAttrs: {
        lang: locale,
        class: 'min-h-full',
        'data-storage-namespace': 'buildpusher-signal',
        'data-default-preset': 'modern',
        'data-default-appearance': 'system',
        'data-default-palette': 'graphite',
        'data-default-density': 'comfortable',
        'data-default-corners': 'subtle',
        'data-default-font': 'system',
        'data-default-motion': 'system',
        'data-default-contrast': 'default',
    },
    bodyAttrs: { class: 'antialiased transition-colors' },
    meta: [{ name: 'robots', content: config.public.indexable ? 'index, follow' : 'noindex, nofollow' }],
    script: [{ innerHTML: themeBoot, tagPosition: 'head' }],
});
</script>

<template>
    <NuxtLayout>
        <NuxtPage />
    </NuxtLayout>
</template>
