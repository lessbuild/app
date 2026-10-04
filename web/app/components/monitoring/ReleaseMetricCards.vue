<script setup lang="ts">
import type { ReleaseMetrics } from '~/types/monitoring';

/** Requests, error rate, average response and exceptions for a release. */
defineProps<{ metrics: ReleaseMetrics }>();
const { t, number, locale } = useT();
const decimal = (value: number, digits: number) => new Intl.NumberFormat(locale.value, { minimumFractionDigits: digits, maximumFractionDigits: digits }).format(value);
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard :label="t('Requests')" :value="number(metrics.requests)" />
        <StatCard :label="t('Error rate')" :value="metrics.errorRate === null ? '—' : `${decimal(metrics.errorRate, 2)}%`" />
        <StatCard :label="t('Average response')" :value="metrics.averageDuration === null ? '—' : `${decimal(metrics.averageDuration, 1)} ms`" />
        <StatCard :label="t('Exceptions')" :value="number(metrics.exceptions)" />
    </div>
</template>
