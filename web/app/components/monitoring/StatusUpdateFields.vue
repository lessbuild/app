<script setup lang="ts">
import type { Option } from '~/types/ui';

/** A status update's fields; `update` is null for a new one. Times are UTC. */
type StatusUpdate = { id: number; kind: string; status: string; severity: string; title: string; message: string; startsAt: string; endsAt: string | null; rootCause: string | null; remediation: string | null; followUp: string | null };
const props = defineProps<{ update: StatusUpdate | null; statuses: Record<string, Option[]> }>();
const { t } = useT();
const prefix = computed(() => (props.update ? `update-${props.update.id}` : 'update-new'));
const kind = ref<string | null>(props.update?.kind ?? 'incident');
const status = ref<string | null>(props.update?.status ?? 'investigating');
const severity = ref<string | null>(props.update?.severity ?? 'minor');
const kinds = computed(() => [{ value: 'incident', label: t('Incident') }, { value: 'maintenance', label: t('Maintenance') }]);
const statusOptions = computed(() => props.statuses[kind.value ?? 'incident'] ?? []);
const severities = computed(() => [{ value: 'minor', label: t('Minor') }, { value: 'major', label: t('Major') }, { value: 'critical', label: t('Critical: shows as a major outage') }]);
/** An ISO time as a datetime-local value in UTC. */
const local = (iso: string | null | undefined) => (iso ? new Date(iso).toISOString().slice(0, 16) : '');
const reviewed = computed(() => !!(props.update?.rootCause || props.update?.remediation || props.update?.followUp));

// A kind has its own statuses: switching kind moves to its first one.
watch(kind, () => {
    if (!statusOptions.value.some((option) => option.value === status.value)) {
        status.value = statusOptions.value[0]?.value ?? null;
    }
});
</script>

<template>
    <div class="grid items-start gap-4 sm:grid-cols-2">
        <SelectField :id="`${prefix}-kind`" v-model="kind" name="kind" :label="t('Type')" :options="kinds" required />
        <SelectField :id="`${prefix}-status`" v-model="status" name="status" :label="t('Status')" :options="statusOptions" required />
        <SelectField :id="`${prefix}-severity`" v-model="severity" name="severity" :label="t('Impact')" :options="severities" required />
        <InputField :id="`${prefix}-title`" name="title" :label="t('Title')" :model-value="update?.title" maxlength="255" required />
        <div class="sm:col-span-2"><TextareaField :id="`${prefix}-message`" name="message" :label="t('Message')" :model-value="update?.message" maxlength="5000" rows="3" required /></div>
        <InputField :id="`${prefix}-starts`" name="starts_at" type="datetime-local" :label="t('Starts (UTC)')" :model-value="update ? local(update.startsAt) : local(new Date().toISOString())" required />
        <InputField :id="`${prefix}-ends`" name="ends_at" type="datetime-local" :label="t('Ends (UTC)')" :model-value="local(update?.endsAt)" :description="t('Optional. For maintenance, when it should be over.')" />
        <details class="sm:col-span-2" :open="reviewed">
            <summary class="cursor-pointer text-sm font-bold text-primary">{{ t('Incident review (optional)') }}</summary>
            <div class="mt-3 grid gap-4">
                <TextareaField :id="`${prefix}-root-cause`" name="root_cause" :label="t('What happened')" :model-value="update?.rootCause" maxlength="5000" rows="2" />
                <TextareaField :id="`${prefix}-remediation`" name="remediation" :label="t('What we did')" :model-value="update?.remediation" maxlength="5000" rows="2" />
                <TextareaField :id="`${prefix}-follow-up`" name="follow_up" :label="t('What’s next')" :model-value="update?.followUp" maxlength="5000" rows="2" />
            </div>
        </details>
    </div>
</template>
