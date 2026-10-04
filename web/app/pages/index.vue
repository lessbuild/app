<script setup lang="ts">
import type { ServiceCopy, PageMeta } from '~/types/site';

/**
 * The root address. On a status page's custom domain it's that status page (without the site's frame); otherwise it's
 * the public home page, and signed-in people go on to their dashboard.
 */
definePageMeta({
    layout: 'public',
    middleware: [async (to) => {
        const config = useRuntimeConfig();
        const host = useRequestURL().hostname.toLowerCase();
        if (host === config.public.appHost || !/^[a-z0-9.-]+$/.test(host)) {
            return;
        }
        const slug = await useApiReader()<{ slug: string }>(`/status-domains/${host}`).then((found) => found.slug, () => null);
        if (slug) {
            to.meta.statusSlug = slug;
            setPageLayout('plain');
        }
    }],
});
type HomePage = {
    redirect?: string;
    meta: PageMeta;
    summary: string;
    hero: { badge: string; headline: string; accent: string; points: string[] };
    services: Array<{ key: string; name: string; copy: ServiceCopy }>;
    workflow: Array<{ number: string; title: string; text: string; icon: string }>;
    integrations: Array<{ title: string; text: string }>;
};
const { t } = useT();
const route = useRoute();
const statusSlug = computed(() => (typeof route.meta.statusSlug === 'string' ? route.meta.statusSlug : null));
const home = statusSlug.value ? null : (await useApi<HomePage>('/site/home')).data;
if (home?.value.redirect) {
    await navigateTo(local(home.value.redirect), { replace: true });
}
if (home && !home.value.redirect) {
    usePublicPage(() => home.value.meta);
}
const glimpse = computed(() => [
    { label: t('Approval'), state: t('Passed') }, { label: t('Activate release'), state: t('Complete') }, { label: t('Verify health'), state: t('Passed') },
]);
const checks = computed(() => [{ label: t('API'), value: '99.99%' }, { label: t('Worker'), value: t('Up') }, { label: t('TLS'), value: t('Valid') }]);
const yours = computed(() => [
    { title: t('Your cloud'), text: t('Servers run in the provider accounts you connect.') },
    { title: t('Your data'), text: t('Secrets and scripts are encrypted; analytics sets no cookies.') },
    { title: t('Your team'), text: t('Roles, per-service access and a full audit log.') },
]);
</script>

<template>
    <StatusPageView v-if="statusSlug" :slug="statusSlug" />
    <div v-else-if="home && !home.redirect">
        <section class="relative overflow-hidden border-b border-line bg-surface">
            <div class="surface-grid absolute inset-0 opacity-50" aria-hidden="true" />
            <div class="relative mx-auto grid max-w-6xl items-center gap-12 px-5 py-12 sm:px-8 sm:py-24 lg:grid-cols-[0.9fr_1.1fr] lg:gap-16 lg:py-28">
                <div>
                    <p class="ui-badge ui-badge-primary"><Icon name="layers" class="h-3.5 w-3.5" /> {{ home.hero.badge }}</p>
                    <h1 class="mt-6 max-w-2xl text-4xl font-extrabold tracking-[-0.05em] text-ink sm:text-6xl sm:leading-[1.04]">{{ home.hero.headline }}<br><span class="text-primary">{{ home.hero.accent }}</span></h1>
                    <p class="mt-6 max-w-xl text-base leading-7 text-muted sm:text-lg">{{ home.summary }}</p>
                    <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                        <NuxtLink to="/register" class="ui-btn ui-btn-primary ui-btn-lg">{{ t('Start free') }} <Icon name="arrow-right" class="h-4 w-4" /></NuxtLink>
                        <a href="#services" class="ui-btn ui-btn-secondary ui-btn-lg">{{ t('Explore the services') }} <Icon name="arrow-down" class="h-4 w-4" /></a>
                    </div>
                    <ul class="mt-8 flex flex-wrap items-center gap-x-6 gap-y-3 text-xs font-semibold text-muted">
                        <li v-for="point in home.hero.points" :key="point" class="inline-flex items-center gap-2"><Icon name="check" class="h-4 w-4 text-success" /> {{ point }}</li>
                    </ul>
                </div>
                <div class="relative mx-auto hidden w-full max-w-2xl sm:block" aria-hidden="true">
                    <div class="absolute -inset-8 rounded-full bg-primary/10 blur-3xl" />
                    <div class="ui-panel relative overflow-hidden p-3 sm:p-4">
                        <div class="flex items-center justify-between border-b border-line px-2 pb-3">
                            <div class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full bg-danger" /><span class="h-2.5 w-2.5 rounded-full bg-warning" /><span class="h-2.5 w-2.5 rounded-full bg-success" /></div>
                            <span class="rounded-full bg-surface-muted px-3 py-1 text-[10px] font-bold text-muted">buildpusher / storefront / production</span>
                        </div>
                        <div class="grid gap-3 p-2 pt-4 sm:grid-cols-2">
                            <div class="rounded-card border border-line bg-surface-muted p-4">
                                <div class="flex items-center justify-between"><span class="text-[10px] font-extrabold uppercase tracking-[0.16em] text-subtle">{{ t('Deploy') }}</span><span class="grid h-8 w-8 place-items-center rounded-card bg-surface text-primary"><Icon name="cloud-upload" class="h-4 w-4" /></span></div>
                                <p class="mt-6 text-lg font-extrabold text-ink">{{ t('Release #1841') }}</p>
                                <p class="mt-1 text-xs text-muted">main · a71c8ef</p>
                                <div class="mt-5 space-y-3">
                                    <div v-for="row in glimpse" :key="row.label" class="flex items-center justify-between gap-3 text-xs"><span class="flex min-w-0 items-center gap-2 text-muted"><span class="h-1.5 w-1.5 shrink-0 rounded-full bg-success" /><span class="truncate">{{ row.label }}</span></span><span class="font-bold text-ink">{{ row.state }}</span></div>
                                </div>
                            </div>
                            <div class="rounded-card border border-line bg-surface p-4">
                                <div class="flex items-center justify-between"><span class="text-[10px] font-extrabold uppercase tracking-[0.16em] text-subtle">{{ t('Monitoring') }}</span><span class="grid h-8 w-8 place-items-center rounded-card bg-primary-soft text-primary"><Icon name="pulse" class="h-4 w-4" /></span></div>
                                <p class="mt-6 text-lg font-extrabold text-ink">{{ t('Systems healthy') }}</p>
                                <p class="mt-1 text-xs text-muted">{{ t('Latest check · 184ms') }}</p>
                                <div class="mt-5 grid grid-cols-3 gap-2">
                                    <div v-for="check in checks" :key="check.label" class="rounded-card border border-line bg-surface-muted p-2.5"><p class="text-[10px] font-bold uppercase tracking-[0.12em] text-subtle">{{ check.label }}</p><p class="mt-2 text-sm font-extrabold text-ink">{{ check.value }}</p></div>
                                </div>
                            </div>
                        </div>
                        <div class="mt-1 flex flex-wrap items-center justify-between gap-2 border-t border-line px-2 pt-3 text-[11px] text-muted"><span>{{ t('Release a71c8ef marked on Monitoring and Analytics.') }}</span><span class="inline-flex items-center gap-1.5 font-bold text-success"><Icon name="check-circle" class="h-3.5 w-3.5" /> {{ t('Operational') }}</span></div>
                    </div>
                </div>
            </div>
        </section>

        <section id="services" class="scroll-mt-20 bg-surface-muted/40" aria-labelledby="services-heading">
            <div class="mx-auto max-w-6xl px-5 py-12 sm:px-8 sm:py-24">
                <div class="grid gap-4 lg:grid-cols-[1fr_0.9fr] lg:items-end">
                    <div>
                        <p class="ui-eyebrow">{{ t(':app services', { app: 'BuildPusher' }) }}</p>
                        <h2 id="services-heading" class="mt-3 text-3xl font-extrabold tracking-[-0.04em] text-ink sm:text-4xl">{{ t('Choose the tool for the work in front of you.') }}</h2>
                    </div>
                    <p class="text-base leading-7 text-muted">{{ t('Turn on the services each project needs. They share your team, your alerts and your bill.') }}</p>
                </div>
                <div class="mt-9 hidden gap-4 sm:grid sm:grid-cols-2 xl:grid-cols-4">
                    <ServiceCard v-for="service in home.services" :key="service.key" :service-key="service.key" :name="service.name" :copy="service.copy" />
                </div>
                <ServiceExplorer :services="home.services" class="mt-8 sm:mt-14" />
            </div>
        </section>

        <section id="workflow" class="border-y border-line bg-surface-muted/60">
            <div class="mx-auto grid max-w-6xl gap-8 px-5 py-12 sm:gap-12 sm:px-8 sm:py-24 lg:grid-cols-[0.8fr_1.2fr] lg:items-start">
                <div>
                    <p class="ui-eyebrow">{{ t('How the pieces fit') }}</p>
                    <h2 class="mt-3 text-3xl font-extrabold tracking-[-0.04em] text-ink sm:text-4xl">{{ t('A clear path from commit to confidence.') }}</h2>
                    <p class="mt-4 text-base leading-7 text-muted">{{ t('Each service does its own job, and they share what they know: a deploy becomes a release marker, an incident points at the release that caused it, and traffic reports show what shipped.') }}</p>
                </div>
                <ol class="grid grid-cols-2 gap-3 sm:gap-4">
                    <li v-for="step in home.workflow" :key="step.number" class="ui-card bg-surface/70 p-4 sm:p-5">
                        <div class="flex items-center justify-between"><span class="grid h-10 w-10 place-items-center rounded-card bg-surface-muted text-primary" aria-hidden="true"><Icon :name="step.icon" class="h-[18px] w-[18px]" /></span><span class="text-xs font-extrabold text-subtle">{{ step.number }}</span></div>
                        <h3 class="mt-4 text-base font-extrabold text-ink sm:mt-6">{{ step.title }}</h3>
                        <p class="mt-2 hidden text-sm leading-6 text-muted sm:block">{{ step.text }}</p>
                    </li>
                </ol>
            </div>
        </section>

        <section class="mx-auto hidden max-w-6xl px-5 py-16 sm:block sm:px-8 sm:py-24" aria-labelledby="together-heading">
            <div class="max-w-2xl">
                <p class="ui-eyebrow">{{ t('Better together') }}</p>
                <h2 id="together-heading" class="mt-3 text-3xl font-extrabold tracking-[-0.04em] text-ink sm:text-4xl">{{ t('Built as one platform, not bolted together.') }}</h2>
            </div>
            <div class="mt-10 grid gap-4 sm:grid-cols-2">
                <div v-for="item in home.integrations" :key="item.title" class="ui-card p-5"><p class="font-extrabold text-ink">{{ item.title }}</p><p class="mt-2 text-sm leading-6 text-muted">{{ item.text }}</p></div>
            </div>
        </section>

        <section class="mx-auto max-w-6xl px-5 py-12 sm:px-8 sm:pb-24 sm:pt-0">
            <div class="ui-emphasis relative overflow-hidden rounded-panel px-6 py-10 sm:px-12 sm:py-16">
                <div class="absolute -right-20 -top-24 h-72 w-72 rounded-full bg-primary/30 blur-3xl" aria-hidden="true" />
                <div class="relative grid gap-10 lg:grid-cols-[1fr_0.8fr] lg:items-end">
                    <div>
                        <p class="text-xs font-extrabold uppercase tracking-[0.15em] text-brand-300">{{ t('Start free') }}</p>
                        <h2 class="mt-4 max-w-2xl text-3xl font-extrabold tracking-[-0.04em] sm:text-4xl">{{ t('Every service has a free tier. Upgrade the ones that grow.') }}</h2>
                        <p class="ui-emphasis-muted mt-4 max-w-xl text-base leading-7">{{ t('No card to start. Each service has its own plan on one monthly bill, and you can change or cancel any of them whenever you like.') }}</p>
                        <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                            <NuxtLink to="/register" class="ui-btn ui-btn-lg border border-white bg-white text-slate-950 hover:bg-white/90">{{ t('Create your account') }}</NuxtLink>
                            <NuxtLink to="/pricing" class="ui-btn ui-btn-lg border border-white/25 bg-transparent text-white hover:bg-white/10">{{ t('See pricing') }}</NuxtLink>
                        </div>
                    </div>
                    <div class="hidden gap-3 sm:grid sm:grid-cols-3 lg:grid-cols-1">
                        <div v-for="item in yours" :key="item.title" class="rounded-card border border-white/10 bg-white/5 p-4"><p class="text-sm font-extrabold text-white">{{ item.title }}</p><p class="mt-1 text-xs leading-5 text-white/65">{{ item.text }}</p></div>
                    </div>
                </div>
            </div>
        </section>
    </div>
</template>
