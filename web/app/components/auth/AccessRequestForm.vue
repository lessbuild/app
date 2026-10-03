<script setup lang="ts">
/** Ask for access while sign-up is closed; then say a receipt is on its way. */
defineProps<{ teamSizes: string[] }>();
const { t } = useT();
const message = ref<string | null>(null);

/** Show the receipt message instead of the form. */
function done(data: Record<string, unknown>): null {
    message.value = typeof data.message === 'string' ? data.message : t('Thanks. We’ve emailed you a receipt and will be in touch.');
    return null;
}
</script>

<template>
    <div v-if="message" class="ui-alert ui-alert--success ui-alert-success" role="status">{{ message }}</div>
    <ApiForm v-else action="/api/app/access-requests" :after="done">
        <InputField name="name" :label="t('Your name')" autocomplete="name" maxlength="120" required autofocus />
        <InputField name="email" :label="t('Work email')" type="email" autocomplete="email" maxlength="255" required />
        <InputField name="company" :label="t('Company (optional)')" autocomplete="organization" maxlength="120" />
        <SelectField name="team_size" :label="t('Team size')" :placeholder="t('Rather not say')" :options="teamSizes.map((size) => ({ value: size, label: size }))" />
        <TextareaField name="use_case" :label="t('What would you use it for?')" rows="4" maxlength="2000" required />
        <SubmitButton class="w-full justify-center">{{ t('Request access') }}</SubmitButton>
    </ApiForm>
</template>
