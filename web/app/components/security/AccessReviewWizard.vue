<script setup lang="ts">
import type { AccessGrant, AccessMember, AccessToken } from '~/types/security';

/**
 * An access review, one kind of access per step (members, API tokens, SSH access): tick what should go, then check
 * the list and complete the review. What's ticked is removed, and the review is kept as evidence.
 */
const props = defineProps<{ projectId: string; members: AccessMember[]; tokens: AccessToken[]; grants: AccessGrant[] }>();
const emit = defineEmits<{ done: [] }>();
const { t } = useT();
const step = ref(0);
const busy = ref(false);
const error = ref<string | null>(null);
const removeMembers = ref<string[]>([]);
const removeTokens = ref<number[]>([]);
const removeGrants = ref<number[]>([]);
const steps = computed(() => [
    { id: 'members', title: t('Members'), description: t('Tick anyone who no longer needs access to the account.') },
    { id: 'tokens', title: t('API tokens'), description: t('Tick tokens nothing uses any more. Revoked tokens stop working at once.') },
    { id: 'ssh', title: t('SSH access'), description: t('Tick personal SSH access to the project’s servers that should go.') },
    { id: 'confirm', title: t('Check and complete') },
]);
const removing = computed(() => [
    ...props.members.filter((member) => removeMembers.value.includes(member.id)).map((member) => t('Member :name', { name: member.name })),
    ...props.tokens.filter((token) => removeTokens.value.includes(token.id)).map((token) => t('API token :name', { name: token.name })),
    ...props.grants.filter((grant) => removeGrants.value.includes(grant.id)).map((grant) => t(':name on :server', { name: grant.name, server: grant.server })),
]);

/** Save the review, removing what's ticked. */
async function complete() {
    busy.value = true;
    error.value = null;
    try {
        const result = await send<{ message: string }>('POST', `/projects/${props.projectId}/security/access/reviews`, { members: removeMembers.value, tokens: removeTokens.value, grants: removeGrants.value });
        flash(result.message);
        emit('done');
        await refreshPage();
    } catch (problem) {
        error.value = problem instanceof Error ? problem.message : t('Something went wrong. Try again.');
    }
    busy.value = false;
}
</script>

<template>
    <Wizard v-model="step" :steps="steps" :busy="busy" :error="error" :finish-label="t('Complete review')" @finish="complete">
        <template #members>
            <fieldset class="grid gap-1">
                <legend class="sr-only">{{ t('Members to remove') }}</legend>
                <label v-for="member in props.members" :key="member.id" class="flex items-center justify-between gap-3 rounded-lg px-3 py-2 hover:bg-surface-muted" :class="member.isYou && 'cursor-not-allowed opacity-60'">
                    <span class="min-w-0">
                        <span class="block font-bold text-ink">{{ member.name }} <span v-if="member.isYou" class="font-normal text-muted">({{ t('you') }})</span></span>
                        <span class="block text-xs text-muted">{{ member.email }} · {{ member.role }} · {{ member.twoFactor ? t('Two-factor on') : t('Two-factor off') }}</span>
                    </span>
                    <input v-model="removeMembers" type="checkbox" :value="member.id" :disabled="member.isYou" class="h-4 w-4 accent-[var(--ui-primary)]" :aria-label="t('Remove :name', { name: member.name })">
                </label>
            </fieldset>
        </template>
        <template #tokens>
            <p v-if="props.tokens.length === 0" class="text-sm text-muted">{{ t('No API tokens.') }}</p>
            <fieldset v-else class="grid gap-1">
                <legend class="sr-only">{{ t('API tokens to revoke') }}</legend>
                <label v-for="token in props.tokens" :key="token.id" class="flex items-center justify-between gap-3 rounded-lg px-3 py-2 hover:bg-surface-muted">
                    <span class="min-w-0">
                        <span class="block font-bold text-ink">{{ token.name }}</span>
                        <span class="block text-xs text-muted">
                            {{ token.owner ?? '—' }} ·
                            <Rich v-if="token.lastUsedAt" :text="t('last used :time')"><template #time><RelativeTime :at="token.lastUsedAt" /></template></Rich>
                            <template v-else>{{ t('never used') }}</template>
                        </span>
                    </span>
                    <input v-model="removeTokens" type="checkbox" :value="token.id" class="h-4 w-4 accent-[var(--ui-primary)]" :aria-label="t('Revoke :name', { name: token.name })">
                </label>
            </fieldset>
        </template>
        <template #ssh>
            <p v-if="props.grants.length === 0" class="text-sm text-muted">{{ t('No one has personal SSH access to this project’s servers.') }}</p>
            <fieldset v-else class="grid gap-1">
                <legend class="sr-only">{{ t('SSH access to remove') }}</legend>
                <label v-for="grant in props.grants" :key="grant.id" class="flex items-center justify-between gap-3 rounded-lg px-3 py-2 hover:bg-surface-muted">
                    <span><span class="font-bold text-ink">{{ grant.name }}</span> <span class="text-muted">· {{ grant.server }}</span></span>
                    <input v-model="removeGrants" type="checkbox" :value="grant.id" class="h-4 w-4 accent-[var(--ui-primary)]" :aria-label="t('Remove :name’s access to :server', { name: grant.name, server: grant.server })">
                </label>
            </fieldset>
        </template>
        <template #confirm>
            <p class="text-sm text-muted">{{ t('Reviewed: :members members, :tokens API tokens and :grants SSH grants.', { members: props.members.length, tokens: props.tokens.length, grants: props.grants.length }) }}</p>
            <Alert v-if="removing.length > 0" tone="warning" class="mt-3">
                <p class="font-semibold">{{ t('Completing the review removes:') }}</p>
                <ul class="mt-1 list-disc ps-5"><li v-for="item in removing" :key="item">{{ item }}</li></ul>
            </Alert>
            <p v-else class="mt-3 text-sm text-ink">{{ t('Nothing is removed: the review records that everyone’s access is still needed.') }}</p>
        </template>
    </Wizard>
</template>
