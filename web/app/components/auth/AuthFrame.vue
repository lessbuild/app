<script setup lang="ts">
/**
 * The frame for sign-in pages (in the public site's Acme look): the brand, the heading and form, and an optional line
 * underneath (the `footer` slot), beside a dark panel on what the platform keeps yours on large screens. Also sets the
 * document title and the site's palette.
 */
const props = withDefaults(defineProps<{ heading: string; title?: string; eyebrow?: string; description?: string; status?: string | null }>(), {
    title: undefined, eyebrow: undefined, description: undefined, status: null,
});
const { t } = useT();

const points = computed(() => [
    { title: t('Your cloud'), text: t('Servers run in the provider accounts you connect.') },
    { title: t('Your data'), text: t('Secrets and scripts are encrypted; analytics sets no cookies.') },
    { title: t('Your team'), text: t('Roles, per-service access and a full audit log.') },
]);

useHead({ title: () => props.title ?? props.heading, htmlAttrs: { 'data-frame': 'site' } });
</script>

<template>
    <div class="grid min-h-screen bg-page lg:grid-cols-[1fr_minmax(0,32rem)] xl:grid-cols-[1fr_minmax(0,38rem)]">
        <main id="main-content" class="flex min-w-0 flex-col px-5 py-8 sm:px-10">
            <NuxtLink to="/" external class="inline-flex w-fit items-center gap-2 rounded-md font-semibold text-ink focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-focus">
                <span class="relative grid size-7 place-items-center" aria-hidden="true"><span class="absolute inset-x-0 top-1 h-2.5 rounded-full bg-primary" /><span class="absolute inset-x-1 bottom-1 h-2.5 rounded-full bg-primary/40" /></span>
                <span class="text-lg tracking-tight">BuildPusher</span>
            </NuxtLink>
            <div class="mx-auto my-auto w-full max-w-md py-10">
                <p v-if="eyebrow" class="site-kicker">{{ eyebrow }}</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-[-0.03em] text-ink">{{ heading }}</h1>
                <p v-if="description" class="mt-2 leading-7 text-muted">{{ description }}</p>
                <div v-if="status" class="ui-alert ui-alert--success ui-alert-success mt-5" role="status">{{ status }}</div>
                <div class="mt-8"><slot /></div>
                <div v-if="$slots.footer" class="mt-6 text-sm text-muted"><slot name="footer" /></div>
            </div>
            <p class="text-xs text-muted">
                <NuxtLink to="/privacy" class="hover:text-ink">{{ t('Privacy') }}</NuxtLink>
                <span aria-hidden="true"> · </span>
                <NuxtLink to="/terms" class="hover:text-ink">{{ t('Terms') }}</NuxtLink>
                <span aria-hidden="true"> · </span>
                <NuxtLink to="/help" class="hover:text-ink">{{ t('Help centre') }}</NuxtLink>
            </p>
        </main>
        <aside class="hidden p-3 lg:block" aria-hidden="true">
            <div class="site-night flex h-full flex-col justify-end rounded-2xl p-10">
                <p class="font-mono text-[0.6875rem] uppercase tracking-wider text-white/50">BuildPusher</p>
                <p class="mt-3 max-w-sm text-3xl font-semibold leading-tight tracking-tight">{{ t('Ship with confidence.') }} <span class="text-white/55">{{ t('Know what happens next.') }}</span></p>
                <ul class="mt-8 space-y-2 text-sm">
                    <li v-for="point in points" :key="point.title" class="rounded-xl border border-white/10 bg-white/[.04] p-4"><span class="font-semibold">{{ point.title }}</span><p class="mt-0.5 text-white/60">{{ point.text }}</p></li>
                </ul>
            </div>
        </aside>
    </div>
</template>
