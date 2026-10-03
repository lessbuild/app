<script setup lang="ts">
/** Create an account: the person's details, an access invitation or referral if there is one, and the bot checks. */
const props = defineProps<{ invite?: string; invitedEmail: string | null; referral?: string; turnstileSiteKey: string | null }>();
const { t } = useT();
const email = ref(props.invitedEmail ?? '');

useHead(() => ({
    script: props.turnstileSiteKey ? [{ src: 'https://challenges.cloudflare.com/turnstile/v0/api.js', async: true, defer: true }] : [],
}));
</script>

<template>
    <ApiForm action="/api/app/auth/register" :after="() => '/email/verify'">
        <input v-if="invite" type="hidden" name="invite" :value="invite">
        <input v-if="referral" type="hidden" name="referral" :value="referral">
        <InputField name="name" :label="t('Your name')" autocomplete="name" required autofocus />
        <InputField v-model="email" name="email" :label="t('Work email')" type="email" autocomplete="email" required />
        <PasswordField name="password" :label="t('Password')" :description="t('At least 8 characters. A short sentence is easy to remember and hard to guess.')" autocomplete="new-password" required />
        <PasswordField name="password_confirmation" :label="t('Confirm password')" autocomplete="new-password" required />
        <!-- People never see this field; bots that fill every field give themselves away. -->
        <div class="absolute -left-[9999px]" aria-hidden="true">
            <label for="website">Website</label>
            <input id="website" type="text" name="website" tabindex="-1" autocomplete="off">
        </div>
        <div v-if="turnstileSiteKey" class="cf-turnstile" :data-sitekey="turnstileSiteKey" data-theme="auto" />
        <SubmitButton class="w-full justify-center">{{ t('Create account') }}</SubmitButton>
        <p class="text-center text-xs leading-5 text-muted">
            <Rich :text="t('By creating an account you agree to the :terms and :privacy.')">
                <template #terms><NuxtLink to="/terms" class="font-semibold text-primary hover:underline">{{ t('terms of service') }}</NuxtLink></template>
                <template #privacy><NuxtLink to="/privacy" class="font-semibold text-primary hover:underline">{{ t('privacy policy') }}</NuxtLink></template>
            </Rich>
        </p>
    </ApiForm>
</template>
