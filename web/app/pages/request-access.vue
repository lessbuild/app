<script setup lang="ts">
/** Ask for an invitation while sign-up is closed; with sign-up open, go straight to it. */
const { t } = useT();
const { data: form } = await useApi<{ registrationOpen: boolean; teamSizes: string[] }>('/access-requests');
if (form.value.registrationOpen) {
    await navigateTo('/register', { replace: true });
}
</script>

<template>
    <AuthFrame :heading="t('Request access')" :description="t('We’re letting people in a few at a time. Tell us a little about what you’d build and we’ll email you an invitation.')">
        <AccessRequestForm :team-sizes="form.teamSizes" />
        <template #footer>
            {{ t('Already have an account?') }} <NuxtLink to="/login" class="font-bold text-primary underline">{{ t('Sign in') }}</NuxtLink>
        </template>
    </AuthFrame>
</template>
