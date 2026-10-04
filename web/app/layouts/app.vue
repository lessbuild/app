<script setup lang="ts">
/**
 * The signed-in frame: two topbar rows (the platform's services and the person's menu; then the account or project
 * and its section navigation), then the page. The signed-in middleware has loaded the shell.
 */
const { t } = useT();
const shell = useShell();

/** Switch to another of the person's accounts and load its dashboard in full, so nothing from the old one lingers. */
async function switchAccount(id: string) {
    const result = await send<{ redirect: string }>('POST', `/accounts/${id}/switch`).catch(() => null);
    if (result) {
        window.location.assign(local(result.redirect));
    }
}
</script>

<template>
    <div v-if="shell">
        <a href="#main-content" class="ui-skip-link">{{ t('Skip to main content') }}</a>
        <header class="sticky top-0 z-40 border-b border-line bg-surface/90 backdrop-blur">
            <div class="ui-layout-gutter mx-auto max-w-content">
                <div class="flex min-h-16 items-center gap-3">
                    <details class="relative xl:hidden">
                        <summary class="ui-icon-btn list-none" :aria-label="t('Open navigation')"><Icon name="menu" class="h-5 w-5" /></summary>
                        <div class="absolute left-0 top-12 z-50 grid w-72 gap-1 rounded-panel border border-line bg-surface p-3 shadow-panel">
                            <NavLinks :items="shell.primaryNav" :label="t('Platform')" class="flex-col items-stretch" />
                        </div>
                    </details>
                    <NuxtLink to="/dashboard" class="flex min-w-0 shrink-0 items-center gap-2.5 text-sm font-extrabold tracking-tight text-ink" :aria-label="t(':app home', { app: 'BuildPusher' })">
                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-ink text-surface shadow-soft" aria-hidden="true">↗</span>
                        <span class="hidden truncate sm:inline">BuildPusher</span>
                    </NuxtLink>
                    <NavLinks :items="shell.primaryNav" :label="t('Platform')" class="hidden flex-1 pl-2 xl:flex" />
                    <div class="ml-auto flex shrink-0 items-center gap-1.5 sm:gap-2">
                        <NuxtLink to="/changelog" class="ui-icon-btn relative" :aria-label="shell.unseenChanges > 0 ? t('What’s new (:count new)', { count: shell.unseenChanges }) : t('What’s new')">
                            <Icon name="sparkles" class="h-[18px] w-[18px]" />
                            <span v-if="shell.unseenChanges > 0" class="absolute right-1 top-1 size-2 rounded-full bg-primary" aria-hidden="true" />
                        </NuxtLink>
                        <CommandPalette :shell="shell" />
                        <NotificationsBell :unread="shell.unreadNotifications" />
                        <ThemeToggle />
                        <details class="ui-topbar-menu group relative">
                            <summary class="flex min-h-10 cursor-pointer list-none items-center gap-2 rounded-control px-1.5 text-sm font-bold text-ink hover:bg-surface-muted" :aria-label="t('Account menu for :name', { name: shell.user.name })">
                                <span class="ui-avatar ui-avatar-sm text-xs" aria-hidden="true">{{ shell.user.name.slice(0, 1).toUpperCase() }}</span>
                            </summary>
                            <div class="absolute right-0 top-full z-40 mt-2 grid min-w-60 gap-1 rounded-panel border border-line bg-surface p-2 shadow-panel">
                                <div class="border-b border-line px-3 pb-3 pt-2">
                                    <p class="truncate text-sm font-extrabold text-ink">{{ shell.user.name }}</p>
                                    <p class="truncate text-xs text-muted">{{ shell.user.email }}</p>
                                </div>
                                <template v-if="shell.accounts.length > 1">
                                    <p class="px-3 pt-2 text-[10px] font-extrabold uppercase tracking-[0.16em] text-subtle">{{ t('Switch account') }}</p>
                                    <button
                                        v-for="account in shell.accounts.filter((item) => item.id !== shell?.account?.id)"
                                        :key="account.id"
                                        type="button"
                                        class="topbar-nav-link w-full"
                                        @click="switchAccount(account.id)"
                                    >
                                        {{ account.name }}
                                    </button>
                                </template>
                                <template v-if="shell.accountLinks.length > 0">
                                    <p class="px-3 pt-2 text-[10px] font-extrabold uppercase tracking-[0.16em] text-subtle">{{ shell.account?.name }}</p>
                                    <NuxtLink v-for="item in shell.accountLinks" :key="item.url" :to="item.url" class="topbar-nav-link w-full">{{ item.label }}</NuxtLink>
                                </template>
                                <div class="mt-1 grid gap-1 border-t border-line pt-1">
                                    <NuxtLink to="/settings/profile" class="topbar-nav-link w-full">{{ t('Your settings') }}</NuxtLink>
                                    <NuxtLink to="/help" class="topbar-nav-link w-full">{{ t('Help centre') }}</NuxtLink>
                                    <NuxtLink :to="{ query: { ...$route.query, dialog: 'feedback' } }" class="topbar-nav-link w-full">{{ t('Send feedback') }}</NuxtLink>
                                    <SignOutButton action="/api/app/auth/logout" />
                                </div>
                            </div>
                        </details>
                    </div>
                </div>
                <div v-if="shell.account !== null" class="flex min-h-14 flex-wrap items-center justify-between gap-x-4 gap-y-1 border-t border-line py-2">
                    <div class="flex min-w-0 flex-wrap items-center gap-2 text-sm font-bold text-ink">
                        <NuxtLink v-if="shell.project" :to="`/projects/${shell.project.id}`" class="flex min-w-0 items-center gap-2 rounded-control px-2 py-1.5 hover:bg-surface-muted">
                            <Icon name="layers" class="h-4 w-4 text-muted" />
                            <span class="truncate">{{ shell.project.name }}</span>
                        </NuxtLink>
                        <span v-else class="px-2 text-muted">{{ shell.account.name }}</span>
                    </div>
                    <NavLinks v-if="shell.sectionNav.length > 0" :items="shell.sectionNav" :label="shell.sectionLabel" class="max-w-full justify-end" />
                </div>
            </div>
        </header>
        <main id="main-content" tabindex="-1" class="ui-layout-gutter mx-auto w-full max-w-content space-y-6 pb-20 pt-7 sm:pt-9">
            <Alert v-if="shell.limitWarning" :tone="shell.limitWarning.tone" role="status">
                {{ shell.limitWarning.message }} <NuxtLink :to="shell.limitWarning.url" class="font-semibold underline">{{ shell.limitWarning.linkLabel }}</NuxtLink>
            </Alert>
            <slot />
        </main>
        <AppFooter :operational="shell.platformOperational" />
        <ConfirmIdentityDialog />
        <Toaster />
    </div>
</template>
