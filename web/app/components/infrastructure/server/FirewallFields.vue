<script setup lang="ts">
/** A firewall rule's fields; `rule` is null for a new one. */
const props = defineProps<{ rule: { name: string; port: string; protocol: string; source: string | null } | null; prefix: string }>();
const { t } = useT();
const protocol = ref<string | null>(props.rule?.protocol ?? 'tcp');
const protocols = [{ value: 'tcp', label: 'TCP' }, { value: 'udp', label: 'UDP' }];
</script>

<template>
    <div class="grid items-start gap-5 sm:grid-cols-2">
        <div class="sm:col-span-2"><InputField :id="`${prefix}-name`" name="name" :label="t('Name')" :model-value="rule?.name" placeholder="Meilisearch" maxlength="60" required /></div>
        <InputField :id="`${prefix}-port`" name="port" :label="t('Port or range')" :model-value="rule?.port" placeholder="7700" maxlength="11" required />
        <SelectField :id="`${prefix}-protocol`" v-model="protocol" name="protocol" :label="t('Protocol')" :options="protocols" />
        <div class="sm:col-span-2">
            <InputField :id="`${prefix}-source`" name="source" :label="t('Only from (optional)')" :model-value="rule?.source" placeholder="203.0.113.10 or 10.0.0.0/16" :description="t('Leave empty to allow anyone.')" maxlength="43" />
        </div>
    </div>
</template>
