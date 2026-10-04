<script setup lang="ts">
/** The report's period: a preset, or custom dates that override it, and what to compare with. */
const period = defineModel<{ days: string | null; from: string; to: string; compare: string | null }>({ required: true });
defineProps<{ today: string | null; compare?: boolean }>();
const { t } = useT();
const presets = computed(() => [
    { value: '1', label: t('Today') }, { value: '7', label: t('Last 7 days') }, { value: '30', label: t('Last 30 days') },
    { value: '90', label: t('Last 90 days') }, { value: '365', label: t('Last 12 months') },
]);
const comparisons = computed(() => [{ value: 'previous', label: t('Previous period') }, { value: 'year', label: t('Same period last year') }, { value: 'none', label: t('No comparison') }]);
</script>

<template>
    <SelectField v-model="period.days" name="days" :label="t('Period')" :options="presets" />
    <InputField v-model="period.from" name="from" type="date" :label="t('From')" :max="today ?? undefined" />
    <InputField v-model="period.to" name="to" type="date" :label="t('To')" :max="today ?? undefined" />
    <SelectField v-if="compare !== false" v-model="period.compare" name="compare" :label="t('Compare with')" :options="comparisons" />
</template>
