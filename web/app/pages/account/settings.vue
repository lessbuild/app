<script setup lang="ts">
/** The account's name, and deleting the account. */
definePageMeta({ layout: 'app', area: 'account' });
const { t } = useT();
const { data } = await useApi<{ account: { id: string; name: string }; canDelete: boolean }>('/account/settings');
const name = ref(data.value.account.name);
watch(() => data.value.account.name, (value) => (name.value = value));
</script>

<template>
    <div class="space-y-10">
        <PageHeader :eyebrow="data.account.name" :title="t('Account settings')" :description="t('The name everyone in :account sees, and deleting it.', { account: data.account.name })" />

        <SettingsSection :title="t('Account name')" :description="t('Shown in the account switcher, invitations and emails.')">
            <ApiForm action="/api/app/account/settings" method="PUT" class="p-4 sm:p-6">
                <InputField v-model="name" name="name" :label="t('Account name')" maxlength="100" required />
                <div class="flex justify-end"><SubmitButton>{{ t('Save name') }}</SubmitButton></div>
            </ApiForm>
        </SettingsSection>

        <SettingsSection v-if="data.canDelete" :title="t('Delete this account')" :description="t('Deletes its members’ access, invitations, API tokens and audit log straight away. This can’t be undone.')">
            <div class="flex justify-end p-4 sm:p-6">
                <DeleteDialog id="delete-account" :title="t('Delete :name', { name: data.account.name })" action="/api/app/account/settings" :warning="t('Deletes its members’ access, invitations, API tokens and audit log straight away. This can’t be undone.')">
                    <template #trigger="{ open }"><UiButton variant="danger" @click="open">{{ t('Delete this account') }}</UiButton></template>
                    <InputField name="confirm_name" :label="t('Type :name to confirm', { name: data.account.name })" autocomplete="off" required />
                </DeleteDialog>
            </div>
        </SettingsSection>
    </div>
</template>
