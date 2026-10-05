<script setup lang="ts">
import type { Option } from '~/types/ui';

/**
 * The fields for importing a server: its name and type, how to reach it over SSH, and how we log in: with a key made
 * for this import (added to root's authorized_keys) or a root private key pasted in.
 */
const props = defineProps<{ types: Option[]; publicKey?: string; ubuntuVersions?: string[] }>();
const { t } = useT();
const source = ref<string | number>(props.publicKey ? 'ours' : 'pasted');
const sources = computed(() => [{ value: 'ours', label: t('Add our key') }, { value: 'pasted', label: t('Paste a private key') }]);
</script>

<template>
    <div class="grid items-start gap-4 sm:grid-cols-2">
        <InputField id="import-server-name" name="name" :label="t('Name')" maxlength="31" placeholder="legacy-web" required autofocus />
        <SelectField id="import-server-type" name="type" :label="t('Type')" :options="types" model-value="app" required />
        <InputField id="import-server-ip" name="public_ip" :label="t('Public IP address')" placeholder="203.0.113.10" required />
        <InputField id="import-server-port" name="ssh_port" type="number" :label="t('SSH port')" model-value="22" min="1" max="65535" required />
        <div class="grid gap-3 sm:col-span-2">
            <input type="hidden" name="key_source" :value="source">
            <AcmeSegmented v-if="publicKey" v-model="source" :label="t('How we log in')" :options="sources" />
            <div v-if="publicKey && source === 'ours'" class="rounded-xl bg-black/[.03] p-4 dark:bg-white/[.04]">
                <p class="text-sm font-medium text-ink">{{ t('Add this key to root’s authorized_keys first') }}</p>
                <div class="mt-2 flex items-center gap-2">
                    <code class="min-w-0 flex-1 truncate rounded-lg border border-line bg-surface px-3 py-2 font-mono text-xs">{{ publicKey }}</code>
                    <AcmeBtn size="sm" icon="copy" @click="copyText(publicKey, t('Key copied'))">{{ t('Copy') }}</AcmeBtn>
                </div>
                <p class="mt-2 text-xs text-muted">{{ t('For example: echo “…” >> /root/.ssh/authorized_keys. It’s made for this import only.') }}</p>
            </div>
            <TextareaField
                v-else
                id="import-server-key"
                name="ssh_private_key"
                :label="t('Root SSH private key')"
                rows="6"
                class="font-mono text-xs"
                :description="t('An unencrypted key that logs in as root. It’s stored encrypted and used for provisioning and later operations.')"
                required
            />
        </div>
        <p v-if="ubuntuVersions?.length" class="text-sm text-muted sm:col-span-2">
            {{ t('Supported: Ubuntu :versions on x86-64 or ARM64. Provisioning may reconfigure or restart existing services.', { versions: ubuntuVersions.join(', ') }) }}
        </p>
    </div>
</template>
