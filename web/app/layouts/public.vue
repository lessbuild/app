<script setup lang="ts">
import type { SiteFrame } from '~/types/site';

/**
 * The public site's frame (Acme's Stratus homepage): the header with its menus, the page in framed columns, and a
 * three-column footer. Sets data-frame="site" on <html> for the site's white page and violet accent.
 */
const { t } = useT();
const { data } = await useApi<SiteFrame>('/site');
const year = new Date().getFullYear();
const resources = computed(() => [
    { label: t('Pricing'), to: '/pricing' },
    { label: t('Help centre'), to: '/help' },
    { label: t('Changelog'), to: '/changelog' },
    { label: t('Roadmap'), to: '/roadmap' },
    { label: t('API reference'), to: '/docs/api' },
    { label: t('Status'), to: '/status' },
]);

useHead({ htmlAttrs: { 'data-frame': 'site' } });
</script>

<template>
    <div class="min-h-screen overflow-x-clip bg-page text-ink">
        <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-50 focus:rounded-control focus:bg-surface focus:px-3 focus:py-2">{{ t('Skip to content') }}</a>
        <SiteHeader :frame="data" />

        <main id="main-content" tabindex="-1" class="outline-none"><slot /></main>

        <footer class="border-t border-line">
            <div class="site-frame grid gap-10 py-14 md:grid-cols-[1.5fr_1fr_1fr]">
                <div>
                    <NuxtLink to="/" class="flex items-center gap-2 font-semibold text-ink">
                        <span class="grid size-7 place-items-center rounded-lg bg-primary text-sm text-on-primary" aria-hidden="true">B</span>BuildPusher
                    </NuxtLink>
                    <p class="mt-3 max-w-xs text-sm leading-6 text-muted">{{ data.summary }}</p>
                </div>
                <div>
                    <h2 class="text-sm font-semibold text-ink">{{ t('Services') }}</h2>
                    <ul class="mt-3 space-y-2 text-sm text-muted" :aria-label="t('Services')">
                        <li v-for="service in data.services" :key="service.key"><NuxtLink :to="`/features/${service.key}`" class="hover:text-ink">{{ service.name }}</NuxtLink></li>
                    </ul>
                </div>
                <div>
                    <h2 class="text-sm font-semibold text-ink">{{ t('Resources') }}</h2>
                    <ul class="mt-3 space-y-2 text-sm text-muted" :aria-label="t('Resources')">
                        <li v-for="link in resources" :key="link.to"><NuxtLink :to="link.to" class="hover:text-ink">{{ link.label }}</NuxtLink></li>
                    </ul>
                </div>
            </div>
            <div class="border-t border-line">
                <div class="site-frame flex flex-wrap justify-between gap-4 py-5 text-sm text-muted">
                    <p>© {{ year }} BuildPusher.</p>
                    <p class="flex gap-4">
                        <NuxtLink to="/privacy" class="hover:text-ink">{{ t('Privacy') }}</NuxtLink>
                        <NuxtLink to="/terms" class="hover:text-ink">{{ t('Terms') }}</NuxtLink>
                        <a :href="`mailto:${data.contactEmail}`" class="hover:text-ink">{{ data.contactEmail }}</a>
                    </p>
                </div>
            </div>
        </footer>
    </div>
</template>
