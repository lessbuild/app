<script setup lang="ts">
/**
 * The Acme theme's settings page around your own settings and the account's: a "Settings" heading, the sections in a
 * grouped list beside the page (a row of pills on phones), and the page's own heading above its rows. The account
 * group lists only the pages the person may open.
 */
defineProps<{ title: string; description?: string | null }>();
const { t } = useT();
const shell = useShell();
// The account pages that are settings, with the theme's icons; the shell says which the person may open.
const accountIcons: Record<string, string> = {
    '/account/settings': 'workspace', '/account/members': 'people', '/account/billing': 'billing', '/account/security': 'lock',
    '/account/api-tokens': 'lockKey', '/account/webhooks': 'link', '/account/audit-log': 'clock',
};
const groups = computed(() => [
    { title: t('Personal'), items: [
        { label: t('Profile'), to: '/settings/profile', icon: 'user' },
        { label: t('Security'), to: '/settings/security', icon: 'key' },
        { label: t('Sessions'), to: '/settings/sessions', icon: 'monitor' },
        { label: t('Notifications'), to: '/settings/notifications', icon: 'bell' },
        { label: t('Privacy'), to: '/settings/privacy', icon: 'eye' },
    ] },
    ...(shell.value?.account ? [{
        title: shell.value.account.name,
        items: shell.value.accountLinks.filter((link) => accountIcons[link.url]).map((link) => ({ label: link.url === '/account/settings' ? t('Account details') : link.label, to: link.url, icon: accountIcons[link.url] })),
    }] : []),
]);
</script>

<template>
    <div>
        <p class="pb-4 text-2xl font-semibold tracking-tight text-ink sm:text-[1.75rem] lg:pb-6">{{ t('Settings') }}</p>
        <div class="flex flex-col gap-6 lg:flex-row lg:gap-10">
            <AcmeSubNav :groups="groups" :label="t('Settings')" class="lg:w-64 lg:shrink-0" />
            <section class="min-w-0 flex-1 lg:pt-1">
                <div class="border-b border-line pb-7">
                    <h1 class="text-xl font-semibold tracking-tight text-ink">{{ title }}</h1>
                    <p v-if="description" class="mt-1 text-muted">{{ description }}</p>
                    <div v-if="$slots.actions" class="mt-4 flex flex-wrap gap-2"><slot name="actions" /></div>
                </div>
                <div class="space-y-6 pt-6"><slot /></div>
            </section>
        </div>
    </div>
</template>
