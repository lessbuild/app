<script setup lang="ts">
import type { Option } from '~/types/ui';

/** A cron job's fields; `job` is null for a new one. Common schedules are offered as suggestions. */
const props = defineProps<{ job: { command: string; frequency: string; user: string } | null; users: Option[]; presets: Record<string, string>; prefix: string; serverName: string }>();
const { t } = useT();
const user = ref<string | null>(props.job?.user ?? props.serverName);
</script>

<template>
    <div class="grid items-start gap-5 sm:grid-cols-2">
        <div class="sm:col-span-2">
            <InputField :id="`${prefix}-command`" name="command" :label="t('Command')" :model-value="job?.command" :placeholder="`php /home/${serverName}/example.com/current/artisan schedule:run`" maxlength="1000" required class="font-mono" />
        </div>
        <InputField
            :id="`${prefix}-frequency`"
            name="frequency"
            :label="t('Schedule (cron)')"
            :model-value="job?.frequency ?? '* * * * *'"
            :description="t('Every minute is * * * * *; every night at 3 is 0 3 * * *.')"
            :list="`${prefix}-presets`"
            maxlength="100"
            required
            class="font-mono"
        />
        <datalist :id="`${prefix}-presets`"><option v-for="(label, expression) in presets" :key="expression" :value="expression">{{ label }}</option></datalist>
        <SelectField :id="`${prefix}-user`" v-model="user" name="user" :label="t('Run as')" :options="users" />
    </div>
</template>
