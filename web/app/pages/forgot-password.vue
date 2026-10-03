<script setup lang="ts">
/** Ask for a link to choose a new password. */
const { t } = useT();
</script>

<template>
    <AuthFrame :heading="t('Reset your password')" :description="t('Enter your email and we’ll send a link to choose a new password.')">
        <ForgotPasswordForm />
        <template #footer>
            <NuxtLink to="/login" class="font-bold text-primary underline">{{ t('Back to sign in') }}</NuxtLink>
        </template>
    </AuthFrame>
</template>
