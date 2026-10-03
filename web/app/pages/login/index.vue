<script setup lang="ts">
import type { SignInOptions } from '~/types/auth';

/** Sign in with a password, a passkey, single sign-on or a social provider. */
const { t } = useT();
const route = useRoute();
const { data: options } = await useApi<SignInOptions>('/auth/options');
</script>

<template>
    <AuthFrame
        :heading="t('Sign in')"
        :description="t('One account for Deploy, Monitoring, Analytics and everything else on :app.', { app: 'BuildPusher' })"
        :status="options.status"
    >
        <div v-if="options.error" class="ui-alert ui-alert--danger ui-alert-danger mb-5" role="alert">{{ options.error }}</div>
        <div class="grid gap-5">
            <SignInForm :redirect="safeRedirect(route.query.redirect)" />
            <SocialProviders :providers="options.socialProviders" />
        </div>
        <template #footer>
            {{ t('New here?') }} <NuxtLink to="/register" class="font-bold text-primary underline">{{ t('Create an account') }}</NuxtLink>
        </template>
    </AuthFrame>
</template>
