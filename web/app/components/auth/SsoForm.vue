<script setup lang="ts">
/** A work email, then off to the company's identity provider. */
const { t } = useT();
</script>

<template>
    <ApiForm action="/api/app/auth/sso" :after="(data) => (typeof data.redirect === 'string' ? data.redirect : null)">
        <InputField name="email" :label="t('Work email address')" type="email" autocomplete="username" required autofocus />
        <SubmitButton class="w-full justify-center">{{ t('Continue') }}</SubmitButton>
    </ApiForm>
</template>
