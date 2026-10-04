<script setup lang="ts">
import type { Option } from '~/types/ui';

/** A load balancer's hostname, health check path and website; `balancer` is null for a new one. */
const props = defineProps<{ balancer: { id: number; hostname: string; healthPath: string; websiteId: number | null } | null; websites: Option[] }>();
const { t } = useT();
const prefix = computed(() => (props.balancer ? `balancer-${props.balancer.id}-` : 'balancer-'));
const website = ref<string | null>(props.balancer?.websiteId != null ? String(props.balancer.websiteId) : '');
</script>

<template>
    <InputField :id="`${prefix}hostname`" name="hostname" :label="t('Hostname')" :model-value="balancer?.hostname" placeholder="shop.example.com" maxlength="253" required />
    <InputField :id="`${prefix}health`" name="health_path" :label="t('Health check path')" :model-value="balancer?.healthPath ?? '/up'" maxlength="255" required />
    <SelectField :id="`${prefix}website`" v-model="website" name="website_id" :label="t('Website (optional)')" :placeholder="t('None')" :options="websites" />
</template>
