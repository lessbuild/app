<script setup lang="ts">
/** The person's name, email address and language, across every service. */
definePageMeta({ layout: 'app', area: 'settings' });
const { t } = useT();
const { data } = await useApi<{ name: string; email: string; locale: string | null; locales: Record<string, string> }>('/settings/profile');
const locale = ref(data.value.locale ?? '');
const options = computed(() => Object.entries(data.value.locales).map(([value, label]) => ({ value, label })));

/** Saved: load the shell again, so a new language applies straight away. */
function saved(): null {
    flash(t('Profile saved.'));
    refreshPage();
    return null;
}
</script>

<template>
    <div class="space-y-8">
        <PageHeader :title="t('Profile')" :description="t('Your name and email address across every :app service.', { app: 'BuildPusher' })" />
        <SettingsSection :title="t('Profile')" :description="t('Changing your email address asks you to verify the new one.')">
            <ApiForm action="/api/app/auth/user/profile-information" method="PUT" :after="saved" class="p-4 sm:p-6">
                <InputField name="name" :label="t('Name')" :model-value="data.name" autocomplete="name" maxlength="255" required />
                <InputField name="email" type="email" :label="t('Email address')" :model-value="data.email" autocomplete="email" maxlength="255" required />
                <SelectField v-model="locale" name="locale" :label="t('Language')" :description="t('Automatic follows your browser’s language.')" :placeholder="t('Automatic')" :options="options" />
                <div class="flex justify-end"><SubmitButton>{{ t('Save profile') }}</SubmitButton></div>
            </ApiForm>
        </SettingsSection>
    </div>
</template>
