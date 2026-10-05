<script setup lang="ts">
import type { Option } from '~/types/ui';

/** The fields for adopting an application already in /var/www on an app server. */
defineProps<{ hosts: Option[] }>();
const { t } = useT();
</script>

<template>
    <div class="grid items-start gap-4">
        <InputField id="import-name" name="name" :label="t('Name')" maxlength="255" required autofocus />
        <SelectField id="import-server" name="server_id" :label="t('Server')" :options="hosts" required />
        <InputField id="import-url" name="url" :label="t('Domain')" maxlength="255" placeholder="shop.example.com" required />
        <InputField id="import-slug" name="deployment_slug" :label="t('Directory in /var/www')" maxlength="32" placeholder="shop" :description="t('Lowercase letters, numbers and dashes.')" required />
        <div><TextareaField id="import-description" name="description" :label="t('Description')" rows="2" maxlength="2000" /></div>
    </div>
</template>
