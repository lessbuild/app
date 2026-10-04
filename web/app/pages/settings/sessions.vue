<script setup lang="ts">
/** Where the person is signed in (signing out any browser they don't recognise), and their recent sign-ins. */
definePageMeta({ layout: 'app', area: 'settings' });
const { t, dateTime } = useT();
type Browser = { id: string; device: string; ipAddress: string | null; lastActiveAt: string; current: boolean };
type SignIn = { succeeded: boolean; method: string | null; twoFactor: boolean; device: string; ipAddress: string | null; at: string };
const { data } = await useApi<{ sessions: Browser[] | null; signIns: SignIn[]; retentionDays: number }>('/settings/sessions');
</script>

<template>
    <SettingsFrame :title="t('Sessions')" :description="t('Where you are signed in, and recent sign-ins to your account.')" >

        <SettingsSection :title="t('Signed-in browsers')" :description="t('Sign out any browser you don’t recognise. This also forgets “Remember me” on your other browsers, so they’ll ask you to sign in next time.')">
            <p v-if="data.sessions === null" class="p-4 text-sm text-muted sm:p-6">{{ t('Browser sessions can’t be listed on this server.') }}</p>
            <ul v-else class="divide-y divide-line">
                <li v-for="browser in data.sessions" :key="browser.id" class="flex flex-wrap items-center justify-between gap-3 px-4 py-4 sm:px-6">
                    <div class="min-w-0">
                        <p class="flex flex-wrap items-center gap-2 font-bold text-ink">{{ browser.device }}<Badge v-if="browser.current" tone="success">{{ t('This browser') }}</Badge></p>
                        <p class="mt-1 text-xs text-muted">
                            {{ browser.ipAddress ?? t('Unknown IP address') }} ·
                            <template v-if="browser.current">{{ t('Active now') }}</template>
                            <template v-else>{{ t('Last active :time', { time: dateTime(browser.lastActiveAt) }) }}</template>
                        </p>
                    </div>
                    <DeleteDialog v-if="!browser.current" :id="`sign-out-${browser.id}`" :title="t('Sign out :device', { device: browser.device })" :action="`/api/app/settings/sessions/${browser.id}`" :submit-label="t('Sign out')">
                        <template #trigger="{ open }"><UiButton variant="quiet" size="sm" @click="open">{{ t('Sign out') }}</UiButton></template>
                    </DeleteDialog>
                </li>
            </ul>
            <template v-if="data.sessions !== null && data.sessions.length > 1" #footer>
                <div class="flex justify-end px-4 py-3 sm:px-6">
                    <DeleteDialog id="sign-out-others" :title="t('Sign out all other browsers')" action="/api/app/settings/sessions" :submit-label="t('Sign out')">
                        <template #trigger="{ open }"><UiButton size="sm" @click="open">{{ t('Sign out all other browsers') }}</UiButton></template>
                    </DeleteDialog>
                </div>
            </template>
        </SettingsSection>

        <SettingsSection :title="t('Sign-in history')" :description="t('Successful and failed sign-ins from the last :days days. If you don’t recognise one, change your password and sign out other browsers.', { days: data.retentionDays })">
            <p v-if="data.signIns.length === 0" class="p-4 text-sm text-muted sm:p-6">{{ t('No sign-ins recorded yet.') }}</p>
            <DataTable v-else :caption="t('Recent sign-ins')" :framed="false">
                <template #head>
                    <tr><th scope="col">{{ t('When') }}</th><th scope="col">{{ t('Result') }}</th><th scope="col">{{ t('Method') }}</th><th scope="col">{{ t('Device') }}</th></tr>
                </template>
                <tr v-for="(signIn, index) in data.signIns" :key="index">
                    <td class="whitespace-nowrap"><RelativeTime :at="signIn.at" /></td>
                    <td><Badge :tone="signIn.succeeded ? 'success' : 'danger'">{{ signIn.succeeded ? t('Signed in') : t('Failed') }}</Badge></td>
                    <td>{{ signIn.method ?? t('Unknown') }}<template v-if="signIn.twoFactor"> {{ t('+ authenticator') }}</template></td>
                    <td>
                        <span class="block">{{ signIn.device }}</span>
                        <span class="block text-xs text-muted">{{ signIn.ipAddress ?? t('Unknown IP address') }}</span>
                    </td>
                </tr>
            </DataTable>
        </SettingsSection>
    </SettingsFrame>
</template>
