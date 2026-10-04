<script setup lang="ts">
/**
 * The signed-in frame (the Acme look): a sidebar with the account, the platform's services and the sections, and the
 * page beside it under a slim bar of what's new, search, notifications and the theme. On large screens it's a rounded
 * frame inset from the window; below that the sidebar opens as a drawer. Only the page scrolls (router.options.ts
 * brings it back to the top between pages). The signed-in middleware has loaded the shell.
 */
const { t } = useT();
const shell = useShell();
const route = useRoute();
const drawer = ref(false);

useHead({ htmlAttrs: { 'data-frame': 'app' } });
watch(() => route.fullPath, () => (drawer.value = false));

/**
 * Close the drawer with Escape.
 *
 * @param event The key press.
 */
function escape(event: KeyboardEvent) {
    if (event.key === 'Escape') {
        drawer.value = false;
    }
}

onMounted(() => window.addEventListener('keydown', escape));
onBeforeUnmount(() => window.removeEventListener('keydown', escape));
</script>

<template>
    <div v-if="shell" class="h-dvh lg:p-3 xl:p-4">
        <a href="#main-content" class="ui-skip-link">{{ t('Skip to main content') }}</a>
        <div class="flex h-full overflow-hidden bg-[var(--acme-panel)] lg:rounded-2xl lg:border lg:border-line lg:shadow-sm">
            <AppSidebar :shell="shell" class="hidden lg:flex" />

            <Transition name="acme-fade">
                <div v-if="drawer" class="fixed inset-0 z-50 lg:hidden" role="dialog" aria-modal="true" :aria-label="t('Navigation')">
                    <div class="absolute inset-0 bg-black/30" @click="drawer = false" />
                    <AppSidebar :shell="shell" mobile class="relative h-full shadow-2xl" @close="drawer = false" />
                </div>
            </Transition>

            <div class="flex min-w-0 flex-1 flex-col">
                <header class="flex h-14 shrink-0 items-center gap-2 border-b border-line px-3 sm:px-6 lg:px-8">
                    <button type="button" class="ui-icon-btn lg:hidden" :aria-label="t('Open navigation')" :aria-expanded="drawer" @click="drawer = true"><Icon name="menu" class="size-5" /></button>
                    <NuxtLink to="/dashboard" class="flex min-w-0 items-center gap-2 font-medium text-ink lg:hidden" :aria-label="t(':app home', { app: 'BuildPusher' })">
                        <span class="grid size-6 shrink-0 place-items-center rounded-md bg-ink text-sm text-surface" aria-hidden="true">↗</span>
                        <span class="truncate">BuildPusher</span>
                    </NuxtLink>
                    <p v-if="shell.account" class="hidden min-w-0 items-center gap-1.5 text-sm text-muted lg:flex">
                        <span class="truncate">{{ shell.account.name }}</span>
                        <template v-if="shell.project">
                            <Icon name="chevron-right" class="size-3.5 shrink-0" />
                            <NuxtLink :to="`/projects/${shell.project.id}`" class="truncate font-medium text-ink hover:underline">{{ shell.project.name }}</NuxtLink>
                        </template>
                    </p>
                    <div class="ml-auto flex shrink-0 items-center gap-1">
                        <WhatsNewDialog :unseen="shell.unseenChanges" />
                        <CommandPalette :shell="shell" />
                        <NotificationsBell :unread="shell.unreadNotifications" />
                        <ThemeToggle />
                    </div>
                </header>
                <main id="main-content" tabindex="-1" class="flex-1 overflow-y-auto outline-none" data-scroll-frame>
                    <div class="mx-auto w-full max-w-content space-y-6 px-4 pb-10 pt-6 sm:px-6 sm:pt-8 lg:px-10">
                        <Alert v-if="shell.limitWarning" :tone="shell.limitWarning.tone" role="status">
                            {{ shell.limitWarning.message }} <NuxtLink :to="shell.limitWarning.url" class="font-semibold underline">{{ shell.limitWarning.linkLabel }}</NuxtLink>
                        </Alert>
                        <slot />
                    </div>
                    <AppFooter :operational="shell.platformOperational" />
                </main>
            </div>
        </div>
        <ConfirmIdentityDialog />
        <Toaster />
    </div>
</template>
