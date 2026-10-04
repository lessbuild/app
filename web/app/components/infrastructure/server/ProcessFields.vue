<script setup lang="ts">
import type { Option } from '~/types/ui';

/** A process's fields; `process` is null for a new one (or a preset's values). */
const props = defineProps<{
    process: { name: string; command: string; copies: number; user: string; directory: string | null; stopWaitSeconds: number } | null;
    users: Option[];
    prefix: string;
    serverName: string;
}>();
const { t } = useT();
const user = ref<string | null>(props.process?.user ?? props.serverName);
</script>

<template>
    <div class="grid items-start gap-5 sm:grid-cols-2">
        <InputField :id="`${prefix}-name`" name="name" :label="t('Name')" :model-value="process?.name" placeholder="Queue worker" maxlength="60" required />
        <InputField :id="`${prefix}-processes`" name="processes" type="number" min="1" max="20" :label="t('Copies')" :model-value="String(process?.copies ?? 1)" required />
        <div class="sm:col-span-2">
            <InputField :id="`${prefix}-command`" name="command" :label="t('Command')" :model-value="process?.command" placeholder="php artisan queue:work --sleep=3 --tries=3" maxlength="1000" required class="font-mono" />
        </div>
        <InputField :id="`${prefix}-directory`" name="directory" :label="t('Folder')" :model-value="process?.directory" :placeholder="`/home/${serverName}/example.com/current`" maxlength="255" />
        <SelectField :id="`${prefix}-user`" v-model="user" name="user" :label="t('Run as')" :options="users" />
        <InputField :id="`${prefix}-stop`" name="stop_wait_seconds" type="number" min="1" max="3600" :label="t('Time to finish when stopped (seconds)')" :model-value="String(process?.stopWaitSeconds ?? 10)" />
    </div>
</template>
