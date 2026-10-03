<script setup lang="ts">
import type { SignInOptions } from '~/types/auth';

/** Create an account: open to everyone, or by access invitation while sign-up is closed. */
const { t } = useT();
const route = useRoute();
const invite = typeof route.query.invite === 'string' && route.query.invite.length === 64 ? route.query.invite : undefined;
const referral = useCookie<string | undefined>('bp_referral').value;
const { data: options } = await useApi<SignInOptions>('/auth/options', { invite });
</script>

<template>
    <AuthFrame :heading="t('Create your account')" :description="t('Start free. Turn on the services you need for each project and pay only for what you use.')">
        <div v-if="!options.registrationOpen && options.invitedEmail === null" class="grid gap-4">
            <div class="ui-alert ui-alert--info" role="status">{{ t('Sign-up is by invitation for now. If a team invited you, use the email they invited.') }}</div>
            <NuxtLink to="/request-access" class="ui-btn ui-btn-primary w-full justify-center">{{ t('Request access') }}</NuxtLink>
        </div>
        <div v-else class="grid gap-5">
            <SignUpForm :invite="invite" :invited-email="options.invitedEmail" :referral="referral" :turnstile-site-key="options.turnstileSiteKey" />
            <SocialProviders :providers="options.socialProviders" />
        </div>
        <template #footer>
            {{ t('Already have an account?') }} <NuxtLink to="/login" class="font-bold text-primary underline">{{ t('Sign in') }}</NuxtLink>
        </template>
    </AuthFrame>
</template>
