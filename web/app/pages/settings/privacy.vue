<script setup lang="ts">
/** Download what BuildPusher holds about the person, or delete their user account (with what that does). */
definePageMeta({ layout: 'app', area: 'settings' });
const { t } = useT();
const { data } = await useApi<{ email: string; toDelete: string[]; toLeave: string[]; blockedBy: string[] }>('/settings/privacy');
</script>

<template>
    <SettingsFrame :title="t('Privacy')" :description="t('Download what we hold about you, or delete your user account.')" >

        <AcmeCard :padded="false" :title="t('Download your data')" :description="t('A JSON file with your profile, accounts and roles, sign-in methods (never secrets), API token names, sign-in history and your activity. Project and service data is exported per account.')">
            <div class="p-4 sm:p-6"><a href="/api/app/settings/privacy/export" class="ui-btn ui-btn-secondary" download>{{ t('Download my data') }}</a></div>
        </AcmeCard>

        <AcmeCard :padded="false" :title="t('Delete your user account')" :description="t('This can’t be undone. You are signed out everywhere and your sign-in methods, history and API tokens are erased.')">
            <div class="grid gap-4 px-5 pb-5 sm:px-6 sm:pb-6">
                <AcmeAlert v-if="data.blockedBy.length > 0" tone="warning">
                    {{ t('You’re the only owner of accounts other people use.') }} {{ t('Make someone else an owner of these first, or remove the other members:') }} {{ data.blockedBy.join(', ') }}
                </AcmeAlert>
                <dl class="grid gap-3 text-sm sm:grid-cols-[10rem_1fr]">
                    <dt class="font-bold text-muted">{{ t('Deleted with you') }}</dt>
                    <dd class="text-ink">{{ data.toDelete.length ? `${data.toDelete.join(', ')} (${t('you are its only member, so it and all its data are deleted')})` : t('No accounts.') }}</dd>
                    <dt class="font-bold text-muted">{{ t('You leave') }}</dt>
                    <dd class="text-ink">{{ data.toLeave.length ? `${data.toLeave.join(', ')} (${t('keeps working for its other members')})` : t('No shared accounts.') }}</dd>
                </dl>
                <div v-if="data.blockedBy.length === 0">
                    <DeleteDialog id="delete-user" :title="t('Delete my user account')" action="/api/app/settings/privacy/user" :warning="t('This can’t be undone. You are signed out everywhere and your sign-in methods, history and API tokens are erased.')" :submit-label="t('Delete my user account')">
                        <template #trigger="{ open }"><AcmeBtn variant="danger" @click="open">{{ t('Delete my user account') }}</AcmeBtn></template>
                        <InputField name="confirm_email" :label="t('Type :email to confirm', { email: data.email })" autocomplete="off" required />
                    </DeleteDialog>
                </div>
            </div>
        </AcmeCard>
    </SettingsFrame>
</template>
