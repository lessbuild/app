<script setup lang="ts">
import type { Option } from '~/types/ui';

/** The fields for importing a server: its name and type, how to reach it over SSH, and its root key. */
defineProps<{ types: Option[]; ubuntuVersions?: string[] }>();
const { t } = useT();
</script>

<template>
    <div class="grid items-start gap-5 sm:grid-cols-2">
        <InputField id="import-server-name" name="name" :label="t('Name')" maxlength="31" placeholder="legacy-web" required autofocus />
        <SelectField id="import-server-type" name="type" :label="t('Type')" :options="types" model-value="app" required />
        <InputField id="import-server-ip" name="public_ip" :label="t('Public IP address')" placeholder="203.0.113.10" required />
        <InputField id="import-server-port" name="ssh_port" type="number" :label="t('SSH port')" model-value="22" min="1" max="65535" required />
        <div class="sm:col-span-2">
            <TextareaField
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
