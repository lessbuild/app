<script setup lang="ts">
/**
 * API tokens for scripts and CI: create one with only the scopes it needs (its value is shown once), see who made
 * each and when it was last used, and revoke any.
 */
definePageMeta({ layout: 'app', area: 'account' });
const { t, tc, dateTime } = useT();
type Token = { id: number; name: string; owner: string; ownedByViewer: boolean; scopes: string[]; lastUsedAt: string | null; expiresAt: string | null; createdAt: string | null };
type Scope = { value: string; label: string; group: string };
const { data } = await useApi<{ account: { id: string; name: string }; tokens: Token[]; scopes: Scope[]; expiryChoices: string[]; cliInstallUrl: string }>('/account/api-tokens');
const created = ref<{ name: string; value: string } | null>(null);
const copied = ref(false);
const expires = ref('90');

/** The scopes grouped by the part of the platform they cover, each with its read and (if any) write scope. */
const groups = computed(() => {
    const result = new Map<string, { read?: Scope; write?: Scope }>();
    for (const scope of data.value.scopes) {
        const entry = result.get(scope.group) ?? {};
        entry[scope.value.endsWith(':write') ? 'write' : 'read'] = scope;
        result.set(scope.group, entry);
    }
    return [...result.entries()];
});
const scopeLabel = (value: string) => data.value.scopes.find((scope) => scope.value === value)?.label ?? value;
const expiryOptions = computed(() => data.value.expiryChoices.map((choice) => ({ value: choice, label: choice === 'never' ? t('Never') : tc('In :count day|In :count days', Number(choice)) })));
const expired = (token: Token) => token.expiresAt !== null && new Date(token.expiresAt).getTime() < Date.now();

/** Keep the new token's value to show once, and load the list again. */
function keep(result: Record<string, unknown>): null {
    created.value = result.token as { name: string; value: string };
    copied.value = false;
    navigateTo({ query: { dialog: 'new-token' } });
    refreshPage();
    return null;
}

async function copy() {
    if (created.value) {
        await navigator.clipboard.writeText(created.value.value).catch(() => null);
        copied.value = true;
    }
}
</script>

<template>
    <div class="space-y-10">
        <PageHeader :eyebrow="data.account.name" :title="t('API tokens')" :description="t('Tokens let scripts and CI call the :app API as you, inside :account.', { app: 'BuildPusher', account: data.account.name })">
            <template #actions>
                <UiButton variant="primary" :to="{ query: { dialog: 'create-token' } }"><Icon name="plus" class="h-4 w-4" />{{ t('Create a token') }}</UiButton>
            </template>
        </PageHeader>

        <SettingsSection :title="t('Tokens in this account')" :description="t('Everyone’s tokens for :account. Revoke any you don’t recognise.', { account: data.account.name })">
            <p v-if="data.tokens.length === 0" class="p-4 text-sm text-muted sm:p-6">{{ t('No tokens yet.') }}</p>
            <ul v-else class="divide-y divide-line">
                <li v-for="token in data.tokens" :key="token.id" class="flex flex-wrap items-start justify-between gap-3 px-4 py-4 sm:px-6">
                    <div class="min-w-0">
                        <p class="flex flex-wrap items-center gap-2 font-bold text-ink">{{ token.name }}<Badge v-if="token.ownedByViewer">{{ t('Yours') }}</Badge></p>
                        <p class="mt-1 text-xs text-muted">
                            {{ token.owner }}
                            · <template v-if="token.lastUsedAt">{{ t('Last used :time', { time: dateTime(token.lastUsedAt) }) }}</template><template v-else>{{ t('never') }}</template>
                            · <template v-if="token.expiresAt === null">{{ t('Never expires') }}</template>
                            <span v-else-if="expired(token)" class="font-bold text-danger">{{ t('Expired') }}</span>
                            <template v-else>{{ t('Expires :time', { time: dateTime(token.expiresAt) }) }}</template>
                        </p>
                        <ul class="mt-2 flex flex-wrap gap-1" :aria-label="t('Scopes')">
                            <li v-for="scope in token.scopes" :key="scope"><Badge>{{ scopeLabel(scope) }}</Badge></li>
                        </ul>
                    </div>
                    <DeleteDialog :id="`revoke-${token.id}`" :title="t('Revoke “:name”?', { name: token.name })" :action="`/api/app/account/api-tokens/${token.id}`" :warning="t('Anything using this token stops working straight away.')" :submit-label="t('Revoke')">
                        <template #trigger="{ open }"><UiButton variant="quiet" size="sm" @click="open">{{ t('Revoke') }}</UiButton></template>
                    </DeleteDialog>
                </li>
            </ul>
        </SettingsSection>

        <SettingsSection :title="t('Command-line tool')" :description="t('Deploy, follow logs and roll back from your terminal or CI with a token that has the Deploy scopes.')">
            <div class="grid gap-3 p-4 sm:p-6">
                <CodeBlock :code="`curl -fsSL ${data.cliInstallUrl} | sh`" :aria-label="t('Install command')" />
                <p class="text-sm text-muted">{{ t('Then run buildpusher login and paste a token.') }}</p>
            </div>
        </SettingsSection>

        <UiDialog id="create-token" :title="t('Create a token')" :description="t('Give each script its own token with only the scopes it needs. A token stops working if you leave the account or lose the right to manage tokens.')" size="large">
            <ApiForm action="/api/app/account/api-tokens" :after="keep">
                <InputField name="name" :label="t('Token name')" :description="t('What uses it, such as “GitHub Actions deploy”.')" maxlength="100" required autofocus />
                <fieldset class="grid gap-2">
                    <legend class="ui-label">{{ t('Scopes') }}</legend>
                    <p class="ui-help">{{ t('Read and write includes read.') }}</p>
                    <div class="ui-card divide-y divide-line overflow-hidden">
                        <div v-for="[group, scopes] in groups" :key="group" class="flex flex-wrap items-center justify-between gap-3 px-4 py-2.5">
                            <span class="text-sm font-bold text-ink">{{ group }}</span>
                            <span class="flex flex-wrap gap-4">
                                <label v-if="scopes.read" class="inline-flex items-center gap-2 text-sm"><input type="checkbox" class="ui-check" name="scopes[]" :value="scopes.read.value">{{ t('Read') }}</label>
                                <label v-if="scopes.write" class="inline-flex items-center gap-2 text-sm"><input type="checkbox" class="ui-check" name="scopes[]" :value="scopes.write.value">{{ t('Read and write') }}</label>
                            </span>
                        </div>
                    </div>
                    <FieldError name="scopes" />
                </fieldset>
                <SelectField v-model="expires" name="expires" :label="t('Expires')" :options="expiryOptions" required />
                <div class="flex justify-end"><SubmitButton>{{ t('Create token') }}</SubmitButton></div>
            </ApiForm>
        </UiDialog>

        <UiDialog v-if="created" id="new-token" :title="t('Your new token “:name”', { name: created.name })" :description="t('This is the only time it is shown. Send it as a bearer token: Authorization: Bearer <token>.')">
            <div class="grid gap-3">
                <Alert tone="warning">{{ t('Copy it now') }}</Alert>
                <CodeBlock :code="created.value" />
                <div class="flex justify-end">
                    <UiButton variant="primary" @click="copy"><Icon :name="copied ? 'check' : 'clipboard'" class="h-4 w-4" />{{ copied ? t('Copied') : t('Copy') }}</UiButton>
                </div>
            </div>
        </UiDialog>
    </div>
</template>
