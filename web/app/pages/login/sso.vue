<script setup lang="ts">
/** Start single sign-on from a work email address. */
const { t } = useT();
</script>

<template>
    <AuthFrame :heading="t('Sign in with single sign-on')" :description="t('Enter your work email and we’ll send you to your company’s sign-in page.')">
        <SsoForm />
        <template #footer>
            <NuxtLink to="/login" class="font-bold text-primary underline">{{ t('Sign in with a password instead') }}</NuxtLink>
        </template>
    </AuthFrame>
</template>
