<script setup lang="ts">
import type { Invitation } from '~/types/auth';

/** An invitation to join an account: accept it, or sign in or sign up first with the invited address. */
const { t } = useT();
const route = useRoute();
const token = String(route.params.token);
const { data } = await useApi<{ invitation: Invitation | null; signedInAs: string | null }>(`/invitations/${encodeURIComponent(token)}`);
const back = encodeURIComponent(`/invitations/${token}`);
const invitation = computed(() => data.value.invitation);
</script>

<template>
    <AuthFrame
        v-if="invitation === null"
        :heading="t('This invitation isn’t available')"
        :description="t('It may have expired, been used, or been withdrawn. Ask the person who invited you for a new link.')"
    >
        <NuxtLink to="/dashboard" class="ui-btn ui-btn-secondary w-full justify-center">{{ t('Go to your projects') }}</NuxtLink>
    </AuthFrame>
    <AuthFrame
        v-else
        :eyebrow="t('Invitation')"
        :title="t('Invitation')"
        :heading="t('Join :account', { account: invitation.accountName })"
        :description="invitation.invitedBy ? t(':name invited you to join as :role.', { name: invitation.invitedBy, role: invitation.role }) : t('You were invited to join as :role.', { role: invitation.role })"
    >
        <div v-if="data.signedInAs === null" class="grid gap-4">
            <p class="text-sm text-muted">{{ t('Sign in or create an account with :email to accept.', { email: invitation.email }) }}</p>
            <div class="grid gap-2 sm:grid-cols-2">
                <NuxtLink :to="`/login?redirect=${back}`" class="ui-btn ui-btn-primary justify-center">{{ t('Sign in') }}</NuxtLink>
                <NuxtLink :to="`/register?redirect=${back}`" class="ui-btn ui-btn-secondary justify-center">{{ t('Create an account') }}</NuxtLink>
            </div>
        </div>
        <div v-else class="grid gap-4">
            <div v-if="data.signedInAs.toLowerCase() !== invitation.email.toLowerCase()" class="ui-alert ui-alert--warning ui-alert-warning" role="status">
                {{ t('You’re signed in as :you, but the invitation was sent to :email.', { you: data.signedInAs, email: invitation.email }) }}
            </div>
            <ApiForm :action="`/api/app/invitations/${token}`" :after="(result) => (typeof result.redirect === 'string' ? result.redirect : '/dashboard')">
                <SubmitButton class="w-full justify-center">{{ t('Accept and join') }}</SubmitButton>
            </ApiForm>
        </div>
    </AuthFrame>
</template>
