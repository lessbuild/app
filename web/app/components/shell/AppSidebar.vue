<script setup lang="ts">
import type { Shell } from '~/types/shell';

/**
 * The signed-in app's sidebar: the account (and switching to another), search, the platform's services, the project
 * or area's sections, the account's pages and the person's menu. On phones it opens as a drawer (`mobile`), which
 * closes as soon as a link is chosen.
 */
const props = defineProps<{ shell: Shell; mobile?: boolean }>();
const emit = defineEmits<{ close: [] }>();
const { t } = useT();
const route = useRoute();
const root = ref<HTMLElement | null>(null);
const accountOpen = ref(true);
const primary = computed(() => currentNavUrl(props.shell.primaryNav, route));
const section = computed(() => currentNavUrl(props.shell.sectionNav, route));
const accountPage = computed(() => currentNavUrl(props.shell.accountLinks, route));
const otherAccounts = computed(() => props.shell.accounts.filter((account) => account.id !== props.shell.account?.id));
const name = computed(() => props.shell.account?.name ?? 'BuildPusher');

// Menus are <details>; close them when the page changes.
watch(() => route.fullPath, () => root.value?.querySelectorAll('details[open]').forEach((menu) => menu.removeAttribute('open')));

/** Open the command palette (closing the drawer first). */
function search() {
    emit('close');
    window.dispatchEvent(new Event('buildpusher:command-palette'));
}

/**
 * Close the drawer as soon as a link in it is chosen, rather than after the page changes.
 *
 * @param event The click.
 */
function closeOnLink(event: MouseEvent) {
    if (props.mobile && (event.target as HTMLElement).closest('a')) {
        emit('close');
    }
}

/**
 * Switch to another of the person's accounts and load its dashboard in full, so nothing from the old one lingers.
 *
 * @param id The account to switch to.
 */
async function switchAccount(id: string) {
    const result = await send<{ redirect: string }>('POST', `/accounts/${id}/switch`).catch(() => null);
    if (result) {
        window.location.assign(local(result.redirect));
    }
}
</script>

<template>
    <aside ref="root" class="flex shrink-0 flex-col bg-[var(--acme-panel)]" :class="mobile ? 'w-[22rem] max-w-[90vw]' : 'w-[var(--acme-sidebar)] border-r border-line'" :aria-label="t('Sidebar')" @click="closeOnLink">
        <div class="flex items-center justify-between gap-2 px-4 pb-4 pt-5">
            <details class="relative min-w-0">
                <summary class="flex min-w-0 cursor-pointer list-none items-center gap-2.5 rounded-lg px-1.5 py-1 hover:bg-[var(--acme-hover)]" :aria-label="t('Account: :name', { name })">
                    <span class="grid size-7 shrink-0 place-items-center rounded-md bg-ink text-sm font-medium text-surface" aria-hidden="true">{{ name.slice(0, 1).toUpperCase() }}</span>
                    <span class="truncate text-[1.0625rem] font-medium text-ink">{{ name }}</span>
                    <Icon name="chevron-down" class="size-4 shrink-0 text-muted" />
                </summary>
                <div class="absolute left-0 top-full z-50 mt-2 grid w-64 gap-0.5 rounded-xl border border-line bg-surface p-1.5 shadow-panel">
                    <p class="acme-section-label px-2.5 pb-1 pt-1.5">{{ t('Accounts') }}</p>
                    <p class="acme-menu-item font-medium"><Icon name="check" class="size-4" /><span class="truncate">{{ name }}</span></p>
                    <button v-for="account in otherAccounts" :key="account.id" type="button" class="acme-menu-item pl-[2.125rem]" @click="switchAccount(account.id)">
                        <span class="truncate">{{ account.name }}</span>
                    </button>
                    <div class="mt-1 border-t border-line pt-1">
                        <NuxtLink to="/dashboard" class="acme-menu-item">{{ t('All projects') }}</NuxtLink>
                    </div>
                </div>
            </details>
            <button v-if="mobile" type="button" class="ui-icon-btn shrink-0" :aria-label="t('Close navigation')" @click="emit('close')"><Icon name="close" class="size-5" /></button>
        </div>

        <div class="px-4">
            <button type="button" class="flex w-full items-center justify-between gap-2 rounded-lg border border-line bg-surface px-3 py-2 text-[0.9375rem] text-muted shadow-[var(--shadow-soft-value)] hover:text-ink" @click="search">
                <span class="flex items-center gap-2"><Icon name="search" class="size-4" />{{ t('Search') }}</span>
                <kbd class="ui-kbd">⌘K</kbd>
            </button>
        </div>

        <nav class="mt-4 flex-1 overflow-y-auto pb-4" :aria-label="t('Platform')">
            <ul class="space-y-0.5 px-4">
                <li v-for="item in shell.primaryNav" :key="item.url">
                    <NuxtLink :to="item.url" class="acme-nav-item" :aria-current="item.url === primary ? 'page' : undefined">
                        <Icon :name="item.icon ?? 'grid'" class="size-[1.125rem] text-ink/70" />
                        <span class="truncate">{{ item.label }}</span>
                    </NuxtLink>
                </li>
            </ul>

            <div v-if="shell.sectionNav.length > 0" class="mt-4 border-t border-line px-4 pt-4">
                <NuxtLink v-if="shell.project" :to="`/projects/${shell.project.id}`" class="mb-1 flex min-w-0 items-center gap-2 rounded-md px-2.5 py-1.5 text-sm font-medium text-ink hover:bg-[var(--acme-hover)]">
                    <Icon name="layers" class="size-4 shrink-0 text-muted" />
                    <span class="truncate">{{ shell.project.name }}</span>
                </NuxtLink>
                <p v-else class="acme-section-label mb-1 px-2.5 py-1.5">{{ shell.sectionLabel }}</p>
                <ul class="space-y-0.5" :class="shell.project && 'ml-4 border-l border-line pl-2'" :aria-label="shell.sectionLabel">
                    <li v-for="item in shell.sectionNav" :key="item.url">
                        <NuxtLink :to="item.url" class="acme-sub-item truncate" :aria-current="item.url === section ? 'page' : undefined">{{ item.label }}</NuxtLink>
                    </li>
                </ul>
            </div>

            <div v-if="shell.accountLinks.length > 0" class="mt-4 border-t border-line px-4 pt-4">
                <button type="button" class="mb-1 flex w-full items-center justify-between rounded-md px-2.5 py-1.5 hover:bg-[var(--acme-hover)]" :aria-expanded="accountOpen" @click="accountOpen = !accountOpen">
                    <span class="acme-section-label">{{ t('Account') }}</span>
                    <Icon name="chevron-down" class="size-4 text-muted transition-transform" :class="!accountOpen && '-rotate-90'" />
                </button>
                <ul v-show="accountOpen" class="space-y-0.5" :aria-label="t('Account')">
                    <li v-for="item in shell.accountLinks" :key="item.url">
                        <NuxtLink :to="item.url" class="acme-nav-item" :aria-current="item.url === accountPage ? 'page' : undefined">
                            <Icon :name="item.icon ?? 'grid'" class="size-[1.125rem] text-ink/70" />
                            <span class="truncate">{{ item.label }}</span>
                        </NuxtLink>
                    </li>
                </ul>
            </div>
        </nav>

        <div class="border-t border-line p-4">
            <details class="relative">
                <summary class="acme-nav-item cursor-pointer list-none" :aria-label="t('Account menu for :name', { name: shell.user.name })">
                    <span class="ui-avatar size-6 shrink-0 text-xs" aria-hidden="true">{{ shell.user.name.slice(0, 1).toUpperCase() }}</span>
                    <span class="flex-1 truncate">{{ shell.user.name }}</span>
                    <Icon name="settings" class="size-[1.125rem] text-muted" />
                </summary>
                <div class="absolute inset-x-0 bottom-full z-50 mb-2 grid gap-0.5 rounded-xl border border-line bg-surface p-1.5 shadow-panel">
                    <p class="truncate px-2.5 pb-1.5 pt-1 text-xs text-muted">{{ shell.user.email }}</p>
                    <NuxtLink to="/settings/profile" class="acme-menu-item"><Icon name="user-circle" class="size-4 text-muted" />{{ t('Your settings') }}</NuxtLink>
                    <NuxtLink to="/help" class="acme-menu-item"><Icon name="information-circle" class="size-4 text-muted" />{{ t('Help centre') }}</NuxtLink>
                    <NuxtLink :to="{ query: { ...route.query, dialog: 'feedback' } }" class="acme-menu-item"><Icon name="inbox" class="size-4 text-muted" />{{ t('Send feedback') }}</NuxtLink>
                    <a v-if="shell.user.isPlatformAdmin" href="/admin" class="acme-menu-item"><Icon name="shield" class="size-4 text-muted" />{{ t('Platform admin') }}</a>
                    <div class="mt-1 border-t border-line pt-1"><SignOutButton action="/api/app/auth/logout" class="acme-menu-item" /></div>
                </div>
            </details>
        </div>
    </aside>
</template>
