<script setup lang="ts">
import type { ProviderType } from '~/types/providers';

/**
 * Connecting a provider, in a dialog over any page (`?dialog=add-provider`). It loads the provider types when it opens
 * unless the page passes them; `back` is the setup guide to return to afterwards. With `stay` (opened over another
 * page, such as the new server form) connecting one closes it and keeps the person where they were.
 */
const props = defineProps<{ types?: ProviderType[]; back?: string | null; stay?: boolean }>();
const { t } = useT();
const { data, failed } = useDialogData<{ types: ProviderType[] }>('add-provider', () => '/account/providers', () => (props.types ? { types: props.types } : undefined));
</script>

<template>
    <UiDialog id="add-provider" :title="t('Connect a provider')" :description="t('Use an API token scoped to what we need. We check it straight away and then on a schedule.')" size="large">
        <template #trigger="{ open }"><slot name="trigger" :open="open" /></template>
        <template #default="{ close }">
            <ApiForm v-if="data" action="/api/app/account/providers" :stay="stay" @success="stay && close()">
                <input v-if="back" type="hidden" name="_return" :value="back">
                <div class="grid items-start gap-5 sm:grid-cols-2"><ProviderFields :types="data.types" /></div>
                <div class="flex justify-end"><SubmitButton>{{ t('Connect provider') }}</SubmitButton></div>
            </ApiForm>
            <DialogLoading v-else :failed="failed" />
        </template>
    </UiDialog>
</template>
