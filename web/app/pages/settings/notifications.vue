<script setup lang="ts">
/** The emails the person gets (the issue digest, the weekly report, getting-started tips) and push notifications. */
definePageMeta({ layout: 'app', area: 'settings' });
const { t, dateTime } = useT();
type Device = { id: number; device: string | null; createdAt: string | null; lastUsedAt: string | null };
const { data } = await useApi<{
    account: { id: string; name: string } | null;
    digestAvailable: boolean;
    digestEnabled: boolean;
    gettingStartedEmails: boolean;
    weeklyReportEmails: boolean;
    pushKey: string | null;
    pushDevices: Device[];
}>('/settings/notifications');
const pushProblem = ref<string | null>(null);
const enabling = ref(false);
const testing = ref(false);

/** Turn on push for this browser, or say why it couldn't. */
async function turnOnPush() {
    if (!data.value.pushKey) {
        return;
    }
    enabling.value = true;
    const problem = await enablePush(data.value.pushKey);
    pushProblem.value = problem === 'unsupported'
        ? t('This browser can’t get push notifications. On iPhone and iPad, add BuildPusher to your Home Screen first.')
        : problem === 'blocked'
            ? t('Notifications are blocked for this site. Allow them in the browser’s settings, then try again.')
            : problem === 'failed' ? t('Push notifications couldn’t be turned on here.') : null;
    if (!problem) {
        await refreshPage();
    }
    enabling.value = false;
}

/** Send a test notification to every device. */
async function test() {
    testing.value = true;
    const result = await send<{ message: string }>('POST', '/settings/push-devices/test').catch(() => null);
    if (result) {
        flash(result.message, 'info');
    }
    testing.value = false;
}
</script>

<template>
    <div class="space-y-10">
        <PageHeader :title="t('Notifications')" :description="data.account ? t('Emails you get from :account. Switch accounts to change another’s.', { account: data.account.name }) : t('Emails you get from your accounts.')" />

        <SettingsSection v-if="data.account" :title="t('Daily issue digest')" :description="t('Each morning at 08:00 UTC: new and resolved issues across the account’s projects, and how many are still open. Owners get it unless they turn it off.')">
            <ApiForm v-if="data.digestAvailable" action="/api/app/settings/notifications" method="PUT" class="p-4 sm:p-6">
                <CheckboxField name="issue_digest" unchecked-value="0" :checked="data.digestEnabled" :label="t('Email me the daily issue digest')" />
                <div><SubmitButton variant="secondary">{{ t('Save') }}</SubmitButton></div>
            </ApiForm>
            <p v-else class="p-4 text-sm text-muted sm:p-6">{{ t('The digest is for members who can use Monitoring in this account.') }}</p>
        </SettingsSection>

        <SettingsSection :title="t('Weekly report')" :description="t('Each Monday at 08:00 UTC: last week’s deploys, incidents, uptime and visits for each project in your accounts, compared with the week before. Nothing is sent for a quiet week.')">
            <ApiForm action="/api/app/settings/notifications/weekly-report" method="PUT" class="p-4 sm:p-6">
                <CheckboxField name="weekly_report_emails" unchecked-value="0" :checked="data.weeklyReportEmails" :label="t('Email me the weekly report')" />
                <div><SubmitButton variant="secondary">{{ t('Save') }}</SubmitButton></div>
            </ApiForm>
        </SettingsSection>

        <SettingsSection :title="t('Getting started')" :description="t('A welcome when you join, and one reminder a few days later if a project’s setup has stalled.')">
            <ApiForm action="/api/app/settings/notifications/getting-started" method="PUT" class="p-4 sm:p-6">
                <CheckboxField name="getting_started_emails" unchecked-value="0" :checked="data.gettingStartedEmails" :label="t('Email me getting-started tips')" />
                <div><SubmitButton variant="secondary">{{ t('Save') }}</SubmitButton></div>
            </ApiForm>
        </SettingsSection>

        <SettingsSection :title="t('Always sent')" :description="t('These can’t be turned off.')">
            <p class="p-4 text-sm text-muted sm:p-6">{{ t('Security emails, invitations and, for owners, Monitoring usage alerts at 80% and 100% of the monthly allowance.') }}</p>
        </SettingsSection>

        <SettingsSection v-if="data.pushKey" id="push" :title="t('Push notifications')" :description="t('Get alerts and incidents on your phone or computer, even when BuildPusher isn’t open. On iPhone and iPad, first add BuildPusher to your Home Screen (Share → Add to Home Screen) and turn this on from there. Alert destinations of the Push type then reach these devices.')">
            <div class="grid gap-4 p-4 sm:p-6">
                <p v-if="data.pushDevices.length === 0" class="text-sm text-muted">{{ t('No devices yet.') }}</p>
                <div v-for="device in data.pushDevices" :key="device.id" class="flex flex-wrap items-center justify-between gap-3 text-sm">
                    <span>
                        <span class="font-bold text-ink">{{ device.device ?? t('A device') }}</span>
                        <span class="text-xs text-muted">
                            <template v-if="device.createdAt"> · {{ t('added :time', { time: dateTime(device.createdAt) }) }}</template>
                            <template v-if="device.lastUsedAt"> · {{ t('last notified :time', { time: dateTime(device.lastUsedAt) }) }}</template>
                        </span>
                    </span>
                    <DeleteDialog :id="`remove-device-${device.id}`" :title="t('Remove')" :description="device.device ?? t('A device')" :action="`/api/app/settings/push-devices/${device.id}`" :submit-label="t('Remove')">
                        <template #trigger="{ open }"><UiButton variant="quiet" size="sm" @click="open">{{ t('Remove') }}</UiButton></template>
                    </DeleteDialog>
                </div>
                <p v-if="pushProblem" class="text-sm text-danger" role="alert">{{ pushProblem }}</p>
                <div class="flex flex-wrap gap-2">
                    <UiButton :disabled="enabling" :aria-busy="enabling || undefined" @click="turnOnPush">{{ enabling ? t('Working…') : t('Turn on for this device') }}</UiButton>
                    <UiButton v-if="data.pushDevices.length > 0" variant="quiet" :disabled="testing" @click="test">{{ t('Send a test') }}</UiButton>
                </div>
            </div>
        </SettingsSection>
    </div>
</template>
