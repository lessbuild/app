<script setup lang="ts">
/** Choose a new password from the emailed link, then sign in with it. */
const props = defineProps<{ token: string; email: string }>();
const { t } = useT();
const address = ref(props.email);
</script>

<template>
    <ApiForm action="/api/app/auth/reset-password" :after="() => '/login'">
        <input type="hidden" name="token" :value="token">
        <InputField v-model="address" name="email" :label="t('Email address')" type="email" autocomplete="username" required />
        <PasswordField name="password" :label="t('New password')" autocomplete="new-password" required autofocus />
        <PasswordField name="password_confirmation" :label="t('Confirm new password')" autocomplete="new-password" required />
        <SubmitButton class="w-full justify-center">{{ t('Save password') }}</SubmitButton>
    </ApiForm>
</template>
