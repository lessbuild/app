<script setup lang="ts">
import type { SiteFrame } from '~/types/site';

/**
 * The public site's header (Acme's Stratus header): the brand, a Services and a Resources menu, Pricing and the API,
 * and signing in. Menus open on hover, click or keyboard and close on Escape, a click elsewhere or a new page. The bar
 * gains a border and blur once the page scrolls; phones get a full menu with collapsible sections.
 */
const props = defineProps<{ frame: SiteFrame }>();
const { t } = useT();
const route = useRoute();
const root = ref<HTMLElement | null>(null);
const open = ref<string | null>(null);
const mobile = ref(false);
const mobileSection = ref<string | null>(null);
const scrolled = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;

type MenuItem = { icon: string; title: string; text: string; to: string };
type Menu = { key: string; label: string; columns: Array<{ title: string; items: MenuItem[] }>; feature?: { title: string; text: string; to: string } };

const services = computed<Array<MenuItem & { key: string }>>(() => props.frame.services.map((service) => ({ key: service.key, icon: service.icon, title: service.name, text: service.eyebrow, to: `/features/${service.key}` })));
// Services are grouped by what they're for; a service that isn't offered (such as Audit while it's off) is left out.
const pick = (keys: string[]) => services.value.filter((service) => keys.includes(service.key));
const menus = computed<Menu[]>(() => [
    {
        key: 'services',
        label: t('Services'),
        columns: [
            { title: t('Ship and run'), items: pick(['deploy', 'infrastructure', 'security']) },
            { title: t('Understand and improve'), items: pick(['monitoring', 'analytics', 'audit']) },
        ],
        feature: { title: t('Every service has a free tier'), text: t('Turn on only what a project needs.'), to: '/pricing' },
    },
    {
        key: 'resources',
        label: t('Resources'),
        columns: [
            { title: t('Learn'), items: [
                { icon: 'information-circle', title: t('Help centre'), text: t('Guides and answers'), to: '/help' },
                { icon: 'code', title: t('API reference'), text: t('Every endpoint, with examples'), to: '/docs/api' },
            ] },
            { title: t('Updates'), items: [
                { icon: 'sparkles', title: t('Changelog'), text: t('What shipped lately'), to: '/changelog' },
                { icon: 'list', title: t('Roadmap'), text: t('What’s coming next'), to: '/roadmap' },
                { icon: 'pulse', title: t('Status'), text: t('Live platform health'), to: '/status' },
            ] },
        ],
    },
]);
const active = computed(() => menus.value.find((menu) => menu.key === open.value) ?? null);

/**
 * Open a menu, cancelling a pending close.
 *
 * @param key The menu.
 */
function show(key: string) {
    clearTimeout(timer);
    open.value = key;
}

/** Close the open menu after a moment, so moving the pointer from the button to the panel keeps it open. */
function hideSoon() {
    clearTimeout(timer);
    timer = setTimeout(() => (open.value = null), 120);
}

/**
 * Close menus with Escape.
 *
 * @param event The key press.
 */
function onKey(event: KeyboardEvent) {
    if (event.key === 'Escape') {
        open.value = null;
        mobile.value = false;
    }
}

/**
 * Close the open menu on a click outside the header.
 *
 * @param event The click.
 */
function onClick(event: MouseEvent) {
    if (root.value && !root.value.contains(event.target as Node)) {
        open.value = null;
    }
}

/** Note whether the page has scrolled, for the bar's border. */
function onScroll() {
    scrolled.value = window.scrollY > 8;
}

onMounted(() => {
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
    document.addEventListener('keydown', onKey);
    document.addEventListener('click', onClick);
});
onBeforeUnmount(() => {
    window.removeEventListener('scroll', onScroll);
    document.removeEventListener('keydown', onKey);
    document.removeEventListener('click', onClick);
});
watch(() => route.fullPath, () => {
    open.value = null;
    mobile.value = false;
});
</script>

<template>
    <header ref="root" :class="['sticky top-0 z-40 border-b transition-colors', scrolled || open || mobile ? 'border-line bg-surface/85 backdrop-blur-xl' : 'border-transparent']">
        <div class="site-frame flex h-16 items-center gap-6">
            <NuxtLink to="/" class="flex shrink-0 items-center gap-2 font-semibold text-ink" :aria-label="t(':app home', { app: 'BuildPusher' })">
                <span class="relative grid size-7 place-items-center" aria-hidden="true"><span class="absolute inset-x-0 top-1 h-2.5 rounded-full bg-primary" /><span class="absolute inset-x-1 bottom-1 h-2.5 rounded-full bg-primary/40" /></span>
                <span class="text-lg tracking-tight">BuildPusher</span>
            </NuxtLink>
            <nav class="hidden items-center gap-1 text-sm md:flex" :aria-label="t('Primary navigation')">
                <div v-for="menu in menus" :key="menu.key" @mouseenter="show(menu.key)" @mouseleave="hideSoon">
                    <button type="button" :class="['flex items-center gap-1 rounded-md px-3 py-1.5 text-ink transition', open === menu.key ? 'bg-[var(--acme-hover)]' : 'opacity-75 hover:opacity-100']" :aria-expanded="open === menu.key" @click="open = open === menu.key ? null : menu.key">
                        {{ menu.label }}<Icon name="chevron-down" :class="['size-3.5 transition-transform', open === menu.key && 'rotate-180']" />
                    </button>
                </div>
                <NuxtLink to="/pricing" class="rounded-md px-3 py-1.5 text-ink opacity-75 transition hover:opacity-100" :aria-current="route.path === '/pricing' ? 'page' : undefined">{{ t('Pricing') }}</NuxtLink>
                <NuxtLink to="/docs/api" class="rounded-md px-3 py-1.5 text-ink opacity-75 transition hover:opacity-100" :aria-current="route.path === '/docs/api' ? 'page' : undefined">{{ t('API') }}</NuxtLink>
            </nav>
            <div class="ml-auto flex items-center gap-2">
                <template v-if="frame.signedIn">
                    <NuxtLink to="/dashboard" class="site-btn site-btn-sm hidden md:inline-flex">{{ t('Open the app') }}</NuxtLink>
                </template>
                <template v-else>
                    <NuxtLink to="/login" class="site-btn-2 site-btn-sm hidden md:inline-flex">{{ t('Sign in') }}</NuxtLink>
                    <NuxtLink to="/register" class="site-btn site-btn-sm hidden md:inline-flex">{{ t('Start free') }}</NuxtLink>
                </template>
                <ThemeToggle />
                <button type="button" class="ui-icon-btn md:hidden" :aria-label="mobile ? t('Close menu') : t('Open menu')" :aria-expanded="mobile" @click="mobile = !mobile"><Icon :name="mobile ? 'close' : 'menu'" class="size-5" /></button>
            </div>
        </div>

        <Transition enter-from-class="opacity-0 -translate-y-1" enter-active-class="transition duration-150" leave-to-class="opacity-0" leave-active-class="transition duration-100">
            <div v-if="active" class="absolute inset-x-0 top-full hidden px-6 md:block" @mouseenter="show(active.key)" @mouseleave="hideSoon">
                <div :class="['mx-auto mt-1 grid max-w-4xl gap-2 rounded-xl border border-line bg-surface p-3 shadow-2xl', active.feature ? 'md:grid-cols-[1fr_1fr_14rem]' : 'md:grid-cols-2']">
                    <div v-for="column in active.columns" :key="column.title" class="p-2">
                        <p class="px-2 pb-2 text-xs text-muted">{{ column.title }}</p>
                        <NuxtLink v-for="item in column.items" :key="item.to" :to="item.to" class="site-mega-link">
                            <span class="grid size-9 shrink-0 place-items-center rounded-lg border border-line text-ink"><Icon :name="item.icon" class="size-4" /></span>
                            <span><span class="block text-sm font-medium text-ink">{{ item.title }}</span><span class="text-xs text-muted">{{ item.text }}</span></span>
                        </NuxtLink>
                    </div>
                    <NuxtLink v-if="active.feature" :to="active.feature.to" class="flex flex-col rounded-lg border border-line bg-surface-muted p-4">
                        <span class="text-xs text-muted">{{ t('Featured') }}</span>
                        <span class="mt-auto pt-10 text-sm font-semibold text-ink">{{ active.feature.title }}</span>
                        <span class="mt-1 text-xs text-muted">{{ active.feature.text }}</span>
                        <span class="mt-3 text-xs font-medium text-ink">{{ t('Learn more') }} <span aria-hidden="true">→</span></span>
                    </NuxtLink>
                </div>
            </div>
        </Transition>

        <nav v-if="mobile" class="max-h-[calc(100dvh-4rem)] overflow-y-auto border-t border-line px-6 pb-6 md:hidden" :aria-label="t('Mobile navigation')">
            <div v-for="menu in menus" :key="menu.key" class="border-b border-line">
                <button type="button" class="flex w-full items-center justify-between py-4 font-medium text-ink" :aria-expanded="mobileSection === menu.key" @click="mobileSection = mobileSection === menu.key ? null : menu.key">
                    {{ menu.label }}<Icon name="chevron-down" :class="['size-4 transition-transform', mobileSection === menu.key && 'rotate-180']" />
                </button>
                <div v-if="mobileSection === menu.key" class="pb-3">
                    <NuxtLink v-for="item in menu.columns.flatMap((column) => column.items)" :key="item.to" :to="item.to" class="flex items-center gap-3 py-2 text-sm text-ink"><Icon :name="item.icon" class="size-4 text-muted" />{{ item.title }}</NuxtLink>
                </div>
            </div>
            <NuxtLink to="/pricing" class="block border-b border-line py-4 font-medium text-ink">{{ t('Pricing') }}</NuxtLink>
            <NuxtLink to="/docs/api" class="block border-b border-line py-4 font-medium text-ink">{{ t('API') }}</NuxtLink>
            <div class="mt-6 grid gap-2">
                <NuxtLink v-if="frame.signedIn" to="/dashboard" class="site-btn">{{ t('Open the app') }}</NuxtLink>
                <template v-else>
                    <NuxtLink to="/register" class="site-btn">{{ t('Start free') }}</NuxtLink>
                    <NuxtLink to="/login" class="site-btn-2">{{ t('Sign in') }}</NuxtLink>
                </template>
            </div>
        </nav>
    </header>
</template>
