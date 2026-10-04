<script setup lang="ts">
import type { EnvironmentPage } from '~/types/deploy';

/**
 * An environment's variables: changes waiting for a second person, secrets synced from a password manager, the
 * variables themselves (secret values never shown), adding one and replacing them all from a .env file.
 */
const props = defineProps<{ page: EnvironmentPage; base: string }>();
const { t, date } = useT();
const providers = computed(() => Object.entries(props.page.secretProviders).map(([value, label]) => ({ value, label })));
const provider = ref<string | null>(providers.value[0]?.value ?? null);
const scopeLabel = (scope: string) => props.page.scopes[scope] ?? scope;
</script>

<template>
    <div class="space-y-10">
        <SettingsSection
            v-if="page.pendingChanges.length > 0"
            id="pending-variable-changes"
            :title="t('Waiting for approval')"
            :description="t('Variable changes someone asked for. Someone other than the person who asked approves or rejects each one.')"
        >
            <div class="grid gap-3 p-4 sm:p-6">
                <div v-for="change in page.pendingChanges" :key="change.id" class="flex flex-wrap items-center justify-between gap-3 text-sm">
                    <span>
                        <span class="font-bold">{{ change.summary }}</span>
                        <span class="text-muted"> · {{ change.requester ?? t('Someone') }}<template v-if="change.createdAt"> · <RelativeTime :at="change.createdAt" /></template></span>
                    </span>
                    <Badge v-if="change.mine" tone="warning">{{ t('Waiting for someone else') }}</Badge>
                    <ApiForm v-else-if="page.canManage" :action="`${base}/variable-changes/${change.id}`" class="flex gap-2">
                        <SubmitButton name="decision" value="approve" size="sm">{{ t('Approve') }}</SubmitButton>
                        <SubmitButton name="decision" value="reject" variant="quiet" size="sm">{{ t('Reject') }}</SubmitButton>
                    </ApiForm>
                </div>
            </div>
        </SettingsSection>

        <SettingsSection
            id="variables"
            :title="t('Variables')"
            :description="t('Written into .env on each deploy (runtime), exported while building (build), or both. Secrets aren’t shown again.')"
        >
            <div class="grid gap-4 p-4 sm:p-6">
                <ul v-if="page.variables.length > 0" class="divide-y divide-line text-sm">
                    <li v-for="variable in page.variables" :key="variable.id" class="flex flex-wrap items-center justify-between gap-3 py-2">
                        <span class="min-w-0 break-all">
                            <span class="font-mono font-bold">{{ variable.key }}</span>
                            <span class="font-mono text-muted"> = {{ variable.secret ? '••••••••' : variable.value }}</span>
                            <span class="text-xs text-muted">
                                · {{ scopeLabel(variable.scope) }} · v{{ variable.version }}<template v-if="variable.rotationDueAt"> · {{ t('rotate by :date', { date: date(variable.rotationDueAt) }) }}</template>
                            </span>
                        </span>
                        <ApiForm v-if="page.canManage" :action="`${base}/variables/${variable.id}`" method="DELETE" :confirm="t('Remove :name?', { name: variable.key })">
                            <SubmitButton variant="quiet" size="sm">{{ t('Remove') }}</SubmitButton>
                        </ApiForm>
                    </li>
                </ul>
                <p v-else class="text-sm text-muted">{{ t('No variables yet.') }}</p>
                <template v-if="page.canManage">
                    <AddVariableDialog :project-id="page.overview.project.id" :environment-id="page.environment.id" :page="page">
                        <template #trigger="{ open }"><div><UiButton @click="open"><Icon name="plus" class="h-4 w-4" />{{ t('Add a variable') }}</UiButton></div></template>
                    </AddVariableDialog>
                    <Disclosure :title="t('Replace all from a .env file')">
                        <ApiForm :action="`${base}/variables`" method="PUT" class="grid gap-3" :confirm="t('Every variable not listed is removed.')">
                            <TextareaField name="variables" :label="t('KEY=value lines')" rows="6" class="font-mono" :description="t('Every variable not listed is removed.')" />
                            <div><SubmitButton variant="secondary">{{ t('Replace variables') }}</SubmitButton></div>
                        </ApiForm>
                    </Disclosure>
                </template>
            </div>
        </SettingsSection>

        <SettingsSection
            id="secret-syncs"
            :title="t('Secrets from a password manager')"
            :description="t('Keep secrets in Doppler, 1Password or AWS Secrets Manager and sync them in as secret variables every hour (or now). Variables you set here by hand are never overwritten; keys removed from the source are removed here. The next deploy uses the new values.')"
        >
            <div class="grid gap-4 p-4 sm:p-6">
                <div v-for="sync in page.secretSyncs" :key="sync.id" class="flex flex-wrap items-center justify-between gap-3 text-sm">
                    <div class="min-w-0">
                        <p>
                            <span class="font-bold text-ink">{{ sync.name }}</span>
                            <span class="text-muted"> · {{ sync.provider }}<template v-if="sync.lastSyncedAt"> · <Rich :text="t('synced :time')"><template #time><RelativeTime :at="sync.lastSyncedAt" /></template></Rich></template></span>
                        </p>
                        <p v-if="sync.lastError" class="text-xs text-danger">{{ sync.lastError }}</p>
                        <p v-else-if="sync.lastResult" class="text-xs text-muted">
                            {{ t(':added added, :updated updated, :removed removed', { added: sync.lastResult.added, updated: sync.lastResult.updated, removed: sync.lastResult.removed }) }}
                            <template v-if="sync.lastResult.skipped.length > 0"> · {{ t('left alone: :keys', { keys: sync.lastResult.skipped.join(', ') }) }}</template>
                        </p>
                    </div>
                    <span v-if="page.canManage" class="flex gap-2">
                        <ApiForm :action="`${base}/secret-syncs/${sync.id}`"><SubmitButton variant="secondary" size="sm">{{ t('Sync now') }}</SubmitButton></ApiForm>
                        <ApiForm :action="`${base}/secret-syncs/${sync.id}`" method="DELETE" :confirm="t('Disconnect :name?', { name: sync.name })"><SubmitButton variant="quiet" size="sm">{{ t('Disconnect') }}</SubmitButton></ApiForm>
                    </span>
                </div>
                <FormDialog v-if="page.canManage" id="connect-secret-sync" :title="t('Secrets from a password manager')" :action="`${base}/secret-syncs`" :submit="t('Connect and sync')" size="wide">
                    <template #trigger="{ open }"><div><UiButton @click="open">{{ t('Connect a password manager') }}</UiButton></div></template>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <SelectField v-model="provider" name="provider" :label="t('Source')" :options="providers" />
                        <InputField name="name" :label="t('Name')" maxlength="80" placeholder="Production secrets" />
                        <template v-if="provider !== 'aws'">
                            <div class="sm:col-span-2"><InputField name="token" type="password" autocomplete="off" :label="t('Doppler service token or 1Password Connect token')" maxlength="500" /></div>
                        </template>
                        <template v-if="provider === 'onepassword'">
                            <div class="sm:col-span-2"><InputField name="host" type="url" :label="t('1Password: Connect server')" placeholder="https://connect.example.com" maxlength="255" /></div>
                            <InputField name="vault" :label="t('1Password: vault ID')" maxlength="100" />
                            <InputField name="item" :label="t('1Password: item ID')" maxlength="100" />
                        </template>
                        <template v-if="provider === 'aws'">
                            <InputField name="region" :label="t('AWS: region')" placeholder="eu-west-2" maxlength="30" />
                            <InputField name="secret_id" :label="t('AWS: secret name or ARN')" maxlength="512" />
                            <InputField name="access_key" :label="t('AWS: access key ID')" autocomplete="off" maxlength="128" />
                            <InputField name="secret_key" type="password" autocomplete="off" :label="t('AWS: secret access key')" maxlength="256" />
                        </template>
                    </div>
                </FormDialog>
            </div>
        </SettingsSection>
    </div>
</template>
