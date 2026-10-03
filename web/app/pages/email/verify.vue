<script setup lang="ts">
import type { Me } from '~/types/auth';

/** Ask a new person to open the link in their verification email. */
const { t } = useT();
const { data: me } = await useApi<Me>('/auth/me');
if (me.value.emailVerified) {
    await navigateTo('/dashboard');
}
await setLocale(me.value.locale);
</script>

<template>
    <AuthFrame :heading="t('Check your inbox')" :description="t('We sent a verification link to :email. Open it to finish setting up your account.', { email: me.email })">
        <VerifyEmailActions />
    </AuthFrame>
</template>
