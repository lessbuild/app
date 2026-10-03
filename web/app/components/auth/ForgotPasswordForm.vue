<script setup lang="ts">
/** Ask for a reset link; afterwards say it's on its way (whether or not the address has an account). */
const { t } = useT();
const sent = ref(false);
</script>

<template>
    <div v-if="sent" class="ui-alert ui-alert--success ui-alert-success" role="status">{{ t('A link to choose a new password is on its way. It works for an hour.') }}</div>
    <ApiForm v-else action="/api/app/auth/forgot-password" :after="() => { sent = true; return null; }">
        <InputField name="email" :label="t('Email address')" type="email" autocomplete="email" required autofocus />
        <SubmitButton class="w-full justify-center">{{ t('Email reset link') }}</SubmitButton>
    </ApiForm>
</template>
