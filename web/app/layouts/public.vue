<script setup lang="ts">
import type { SiteFrame } from '~/types/site';

/** The public site's frame: a sticky header with the services, pricing and the API, the page, and a three-column footer. */
const { t } = useT();
const route = useRoute();
const { data } = await useApi<SiteFrame>('/site');
const year = new Date().getFullYear();
const current = (path: string) => (route.path === path ? 'page' : undefined);
</script>

<template>
    <div class="min-h-screen overflow-x-hidden">
        <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-50 focus:rounded-control focus:bg-surface focus:px-3 focus:py-2">{{ t('Skip to content') }}</a>
        <header class="sticky top-0 z-40 border-b border-line bg-surface/90 backdrop-blur">
            <div class="mx-auto flex h-16 max-w-6xl items-center justify-between gap-6 px-5 sm:px-8">
                <NuxtLink to="/" class="flex shrink-0 items-center gap-3 text-base font-extrabold tracking-tight text-ink" :aria-label="t(':app home', { app: 'BuildPusher' })">
                    <span class="grid h-9 w-9 place-items-center rounded-xl bg-ink text-surface shadow-soft" aria-hidden="true"><Icon name="layers" class="h-5 w-5" /></span>
                    <span>BuildPusher</span>
                </NuxtLink>
                <nav class="hidden items-center gap-1 md:flex" :aria-label="t('Primary navigation')">
                    <NuxtLink
                        v-for="service in data.services"
                        :key="service.key"
                        :to="`/features/${service.key}`"
                        :class="['rounded-lg px-3 py-2 text-sm font-semibold transition hover:bg-surface-muted', route.path === `/features/${service.key}` ? 'text-ink' : 'text-muted']"
                        :aria-current="current(`/features/${service.key}`)"
                    >{{ service.name }}</NuxtLink>
                    <NuxtLink to="/pricing" :class="['rounded-lg px-3 py-2 text-sm font-semibold transition hover:bg-surface-muted', route.path === '/pricing' ? 'text-ink' : 'text-muted']" :aria-current="current('/pricing')">{{ t('Pricing') }}</NuxtLink>
                    <NuxtLink to="/docs/api" :class="['rounded-lg px-3 py-2 text-sm font-semibold transition hover:bg-surface-muted', route.path === '/docs/api' ? 'text-ink' : 'text-muted']" :aria-current="current('/docs/api')">{{ t('API') }}</NuxtLink>
                </nav>
                <div class="flex items-center gap-2">
                    <template v-if="data.signedIn">
                        <NuxtLink to="/dashboard" class="ui-btn ui-btn-primary ui-btn-sm">{{ t('Open the app') }}</NuxtLink>
                    </template>
                    <template v-else>
                        <NuxtLink to="/login" class="ui-btn ui-btn-ghost ui-btn-sm hidden sm:inline-flex">{{ t('Sign in') }}</NuxtLink>
                        <NuxtLink to="/register" class="ui-btn ui-btn-primary ui-btn-sm">{{ t('Start free') }}</NuxtLink>
                    </template>
                    <ThemeToggle />
                    <details class="relative md:hidden">
                        <summary class="ui-icon-btn list-none" :aria-label="t('Open navigation')"><Icon name="menu" class="h-5 w-5" /></summary>
                        <nav class="absolute right-0 top-12 grid w-64 gap-1 rounded-panel border border-line bg-surface p-3 shadow-panel" :aria-label="t('Mobile navigation')">
                            <NuxtLink v-for="service in data.services" :key="service.key" :to="`/features/${service.key}`" class="rounded-xl px-3 py-2.5 text-sm font-bold text-muted hover:bg-surface-muted hover:text-ink">{{ service.name }}</NuxtLink>
                            <NuxtLink to="/pricing" class="rounded-xl px-3 py-2.5 text-sm font-bold text-muted hover:bg-surface-muted hover:text-ink">{{ t('Pricing') }}</NuxtLink>
                            <NuxtLink to="/docs/api" class="rounded-xl px-3 py-2.5 text-sm font-bold text-muted hover:bg-surface-muted hover:text-ink">{{ t('API') }}</NuxtLink>
                            <NuxtLink v-if="!data.signedIn" to="/login" class="ui-btn ui-btn-secondary mt-2 w-full">{{ t('Sign in') }}</NuxtLink>
                        </nav>
                    </details>
                </div>
            </div>
        </header>

        <main id="main-content" tabindex="-1"><slot /></main>

        <footer class="border-t border-line bg-surface">
            <div class="mx-auto grid max-w-6xl gap-10 px-5 py-12 sm:px-8 md:grid-cols-[1.4fr_1fr_1fr] md:py-16">
                <div>
                    <NuxtLink to="/" class="flex items-center gap-3 text-base font-extrabold tracking-tight text-ink">
                        <span class="grid h-9 w-9 place-items-center rounded-xl bg-ink text-surface" aria-hidden="true"><Icon name="layers" class="h-5 w-5" /></span>BuildPusher
                    </NuxtLink>
                    <p class="mt-4 max-w-xs text-sm leading-6 text-muted">{{ data.summary }}</p>
                </div>
                <div>
                    <p class="text-xs font-extrabold uppercase tracking-[0.16em] text-subtle">{{ t('Services') }}</p>
                    <nav class="mt-4 flex flex-col items-start gap-3" :aria-label="t('Footer navigation')">
                        <NuxtLink v-for="service in data.services" :key="service.key" :to="`/features/${service.key}`" class="text-sm font-semibold text-muted transition hover:text-ink">{{ service.name }}</NuxtLink>
                    </nav>
                </div>
                <div>
                    <p class="text-xs font-extrabold uppercase tracking-[0.16em] text-subtle">{{ t('Resources') }}</p>
                    <nav class="mt-4 flex flex-col items-start gap-3" :aria-label="t('Resources')">
                        <NuxtLink to="/pricing" class="text-sm font-semibold text-muted transition hover:text-ink">{{ t('Pricing') }}</NuxtLink>
                        <NuxtLink to="/help" class="text-sm font-semibold text-muted transition hover:text-ink">{{ t('Help centre') }}</NuxtLink>
                        <NuxtLink to="/changelog" class="text-sm font-semibold text-muted transition hover:text-ink">{{ t('Changelog') }}</NuxtLink>
                        <NuxtLink to="/roadmap" class="text-sm font-semibold text-muted transition hover:text-ink">{{ t('Roadmap') }}</NuxtLink>
                        <NuxtLink to="/docs/api" class="text-sm font-semibold text-muted transition hover:text-ink">{{ t('API reference') }}</NuxtLink>
                        <NuxtLink to="/status" class="text-sm font-semibold text-muted transition hover:text-ink">{{ t('Status') }}</NuxtLink>
                        <NuxtLink to="/privacy" class="text-sm font-semibold text-muted transition hover:text-ink">{{ t('Privacy') }}</NuxtLink>
                        <NuxtLink to="/terms" class="text-sm font-semibold text-muted transition hover:text-ink">{{ t('Terms') }}</NuxtLink>
                    </nav>
                </div>
            </div>
            <div class="border-t border-line">
                <div class="mx-auto flex max-w-6xl flex-col gap-2 px-5 py-5 text-xs text-subtle sm:flex-row sm:items-center sm:justify-between sm:px-8">
                    <span>© {{ year }} BuildPusher.</span>
                    <span>{{ t('Focused by design') }} <span aria-hidden="true">·</span> {{ t('Accessible by default') }}</span>
                </div>
            </div>
        </footer>
    </div>
</template>
