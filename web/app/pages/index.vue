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
        <section class="relative overflow-hidden border-b border-line" aria-labelledby="home-heading">
            <div class="site-dots pointer-events-none absolute inset-0" aria-hidden="true" />
            <div class="site-frame relative grid items-center gap-12 py-16 lg:grid-cols-[1.1fr_1fr] lg:py-24">
                <div>
                    <p class="inline-flex items-center gap-2 rounded-full border border-line bg-surface px-3 py-1 text-xs font-medium text-ink"><span class="size-1.5 rounded-full bg-primary" aria-hidden="true" />{{ home.hero.badge }}</p>
                    <h1 id="home-heading" class="mt-6 text-[clamp(2.75rem,5.5vw,4.25rem)] font-semibold leading-none tracking-[-0.04em] text-ink">{{ home.hero.headline }}<br><span class="text-primary">{{ home.hero.accent }}</span></h1>
                    <p class="mt-6 max-w-lg text-lg leading-8 text-muted">{{ home.summary }}</p>
                    <div class="mt-8 flex flex-wrap gap-2">
                        <NuxtLink to="/register" class="site-btn">{{ t('Start free') }} <Icon name="arrow-right" class="size-3.5" /></NuxtLink>
                        <a href="#services" class="site-btn-2">{{ t('Explore the services') }} <Icon name="arrow-down" class="size-3.5" /></a>
                    </div>
                    <ul class="mt-6 flex flex-wrap gap-x-5 gap-y-2 text-sm text-muted">
                        <li v-for="point in home.hero.points" :key="point" class="flex items-center gap-1.5"><Icon name="check" class="size-3.5 text-primary" />{{ point }}</li>
                    </ul>
                </div>
                <div class="site-card hidden overflow-hidden shadow-[0_30px_60px_-30px_rgb(40_30_100/.35)] sm:block" aria-hidden="true">
                    <p class="flex items-center gap-1.5 border-b border-line px-4 py-2.5 text-xs"><span class="size-2.5 rounded-full bg-[#fca5a5]" /><span class="size-2.5 rounded-full bg-[#fcd34d]" /><span class="size-2.5 rounded-full bg-[#86efac]" /><span class="ml-auto rounded-full bg-surface-muted px-2 py-0.5 font-mono text-muted">buildpusher / storefront / production</span></p>
                    <div class="grid gap-3 p-4 sm:grid-cols-2">
                        <div class="rounded-xl bg-primary-soft p-4 text-sm">
                            <p class="flex items-center justify-between text-[0.6875rem] font-semibold uppercase tracking-wider text-primary">{{ t('Deploy') }}<Icon name="cloud-upload" class="size-3.5" /></p>
                            <p class="mt-3 font-semibold text-ink">{{ t('Release #1841') }}</p>
                            <p class="text-xs text-muted">main · a71c8ef</p>
                            <ul class="mt-3 space-y-1.5 text-xs text-ink">
                                <li v-for="row in glimpse" :key="row.label" class="flex justify-between gap-3"><span class="flex items-center gap-1.5"><span class="size-1.5 rounded-full bg-success" />{{ row.label }}</span><span class="font-medium">{{ row.state }}</span></li>
                            </ul>
                        </div>
                        <div class="rounded-xl border border-line p-4 text-sm">
                            <p class="flex items-center justify-between text-[0.6875rem] font-semibold uppercase tracking-wider text-success">{{ t('Monitoring') }}<Icon name="pulse" class="size-3.5" /></p>
                            <p class="mt-3 font-semibold text-ink">{{ t('Systems healthy') }}</p>
                            <p class="text-xs text-muted">{{ t('Latest check · 184ms') }}</p>
                            <dl class="mt-3 grid grid-cols-3 gap-1.5 text-xs">
                                <div v-for="check in checks" :key="check.label" class="rounded-md bg-surface-muted p-2"><dt class="text-muted">{{ check.label }}</dt><dd class="font-semibold text-ink">{{ check.value }}</dd></div>
                            </dl>
                        </div>
                    </div>
                    <p class="flex items-center justify-between gap-3 border-t border-line px-4 py-2.5 text-xs text-muted">{{ t('Release a71c8ef marked on Monitoring and Analytics.') }}<span class="flex shrink-0 items-center gap-1 text-success"><span class="size-1.5 rounded-full bg-success" />{{ t('Operational') }}</span></p>
                </div>
            </div>
        </section>

        <SectionRule :number="1" :label="t('Services')" :note="t('Turn on the services each project needs')" />
        <SiteSection id="services" tint labelledby="services-heading" class="scroll-mt-16">
            <div class="grid gap-6 lg:grid-cols-[1.4fr_1fr] lg:items-end">
                <h2 id="services-heading" class="site-h2">{{ t('Choose the tool for the work in front of you.') }}</h2>
                <p class="text-muted">{{ t('Turn on the services each project needs. They share your team, your alerts and your bill.') }}</p>
            </div>
            <div class="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <ServiceCard v-for="service in home.services" :key="service.key" :service-key="service.key" :name="service.name" :copy="service.copy" />
                <NuxtLink to="/pricing" class="site-night flex flex-col justify-between p-6">
                    <span>
                        <span class="font-mono text-[0.6875rem] uppercase tracking-wider text-white/50">{{ t('Better together') }}</span>
                        <span class="mt-3 block text-lg font-semibold">{{ t('One account, one bill.') }}</span>
                        <span class="mt-1.5 block text-sm text-white/65">{{ t('Every service has a free tier. Start with one and turn on the rest when you need them.') }}</span>
                    </span>
                    <span class="mt-6 flex items-center gap-1 text-sm font-medium">{{ t('See pricing') }} <Icon name="arrow-right" class="size-3.5" /></span>
                </NuxtLink>
            </div>
        </SiteSection>

        <SectionRule :number="2" :label="t('Product explorer')" :note="t('Each service’s part in a project')" />
        <SiteSection>
            <ServiceExplorer :services="home.services" />
        </SiteSection>

        <SectionRule :number="3" :label="t('How the pieces fit')" :note="t('From commit to confidence')" />
        <SiteSection tint labelledby="workflow-heading" frame-class="grid gap-10 lg:grid-cols-[1fr_1.4fr]">
            <div>
                <h2 id="workflow-heading" class="site-h2">{{ t('A clear path from commit to confidence.') }}</h2>
                <p class="mt-4 text-muted">{{ t('Each service does its own job, and they share what they know: a deploy becomes a release marker, an incident points at the release that caused it, and traffic reports show what shipped.') }}</p>
            </div>
            <ol class="grid gap-3 sm:grid-cols-2">
                <li v-for="step in home.workflow" :key="step.number" class="site-card p-5">
                    <p class="flex items-center justify-between"><span class="grid size-9 place-items-center rounded-lg bg-primary-soft text-primary" aria-hidden="true"><Icon :name="step.icon" class="size-4" /></span><span class="font-mono text-xs text-muted">{{ step.number }}</span></p>
                    <h3 class="mt-4 font-semibold text-ink">{{ step.title }}</h3>
                    <p class="mt-1 text-sm leading-6 text-muted">{{ step.text }}</p>
                </li>
            </ol>
        </SiteSection>

        <SectionRule :number="4" :label="t('Better together')" :note="t('One platform, not separate tools bolted together')" />
        <SiteSection labelledby="together-heading">
            <h2 id="together-heading" class="site-h2 max-w-xl">{{ t('Built as one platform, not bolted together.') }}</h2>
            <div class="mt-10 grid gap-4 md:grid-cols-2">
                <article v-for="item in home.integrations" :key="item.title" class="site-card p-6"><h3 class="font-semibold text-ink">{{ item.title }}</h3><p class="mt-2 text-sm leading-6 text-muted">{{ item.text }}</p></article>
            </div>
        </SiteSection>

        <SiteCta :kicker="t('Start free')" :title="t('Every service has a free tier. Upgrade the ones that grow.')" :text="t('No card to start. Each service has its own plan on one monthly bill, and you can change or cancel any of them whenever you like.')">
            <template #actions>
                <NuxtLink to="/register" class="site-btn-light">{{ t('Create your account') }}</NuxtLink>
                <NuxtLink to="/pricing" class="site-btn-outline-light">{{ t('See pricing') }}</NuxtLink>
            </template>
            <template #aside>
                <ul class="space-y-2 text-sm">
                    <li v-for="item in yours" :key="item.title" class="rounded-xl border border-white/10 bg-white/[.04] p-4"><span class="font-semibold">{{ item.title }}</span><p class="mt-0.5 text-white/60">{{ item.text }}</p></li>
                </ul>
            </template>
        </SiteCta>
    </div>
</template>
