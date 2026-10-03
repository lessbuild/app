<script setup lang="ts">
/** Choose a new password, from the link in the reset email. */
const { t } = useT();
const route = useRoute();
</script>

<template>
    <AuthFrame :heading="t('Choose a new password')">
        <ResetPasswordForm :token="String(route.params.token)" :email="typeof route.query.email === 'string' ? route.query.email : ''" />
    </AuthFrame>
</template>
