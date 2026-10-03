<script setup lang="ts">
/** The authenticator code, or (behind a link, since it's rarely needed) a recovery code. */
defineProps<{ redirect: string }>();
const { t } = useT();
const recovery = ref(false);
</script>

<template>
    <ApiForm action="/api/app/auth/two-factor-challenge" :after="() => redirect">
        <InputField v-if="recovery" key="recovery" name="recovery_code" :label="t('Recovery code')" autocomplete="off" required autofocus />
        <InputField v-else key="code" name="code" :label="t('Authentication code')" autocomplete="one-time-code" inputmode="numeric" pattern="[0-9]*" maxlength="6" required autofocus />
        <SubmitButton class="w-full justify-center">{{ t('Verify and sign in') }}</SubmitButton>
        <button type="button" class="ui-link justify-self-center text-sm font-bold" @click="recovery = !recovery">
            {{ recovery ? t('Use a code from your app instead') : t('Lost your device? Use a recovery code') }}
        </button>
    </ApiForm>
</template>
