<script setup lang="ts">
/** The second step of signing in: a code from the authenticator app, or a recovery code. */
const { t } = useT();
const route = useRoute();
</script>

<template>
    <AuthFrame :eyebrow="t('Account security')" :heading="t('Verify it’s you')" :description="t('Enter the code from your authenticator app, or one of your recovery codes.')">
        <TwoFactorForm :redirect="safeRedirect(route.query.redirect)" />
    </AuthFrame>
</template>
