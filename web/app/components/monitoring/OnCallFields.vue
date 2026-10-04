<script setup lang="ts">
import type { Option } from '~/types/ui';

/** An on-call schedule's fields; `schedule` is null for a new one. Members take turns in the order of the slots. */
const props = defineProps<{
    schedule: { name: string; rotation: string; handoffDay: number; handoffTime: string; timezone: string; startsOn: string; memberIds: string[] } | null;
    members: Option[];
    prefix: string;
}>();
const { t, locale } = useT();
const rotation = ref<string | null>(props.schedule?.rotation ?? 'weekly');
const day = ref<string | null>(String(props.schedule?.handoffDay ?? 1));
const rotations = computed(() => [{ value: 'weekly', label: t('A week') }, { value: 'daily', label: t('A day') }]);
const days = computed(() => [1, 2, 3, 4, 5, 6, 7].map((number) => ({ value: String(number), label: new Intl.DateTimeFormat(locale.value, { weekday: 'long', timeZone: 'UTC' }).format(new Date(Date.UTC(2024, 0, number))) })));
const slots = computed(() => Array.from({ length: Math.min(10, Math.max(1, props.members.length)) }, (_, index) => index));
const today = new Date().toISOString().slice(0, 10);
</script>

<template>
    <div class="grid items-start gap-5 sm:grid-cols-2">
        <div class="sm:col-span-2"><InputField :id="`${prefix}-name`" name="name" :label="t('Name')" :model-value="schedule?.name" placeholder="Primary" maxlength="120" required /></div>
        <SelectField :id="`${prefix}-rotation`" v-model="rotation" name="rotation" :label="t('Each turn lasts')" :options="rotations" />
        <SelectField :id="`${prefix}-day`" v-model="day" name="handoff_day" :label="t('Hand over on (weekly)')" :options="days" :disabled="rotation !== 'weekly'" />
        <InputField :id="`${prefix}-time`" name="handoff_time" type="time" :label="t('Hand over at')" :model-value="schedule?.handoffTime ?? '09:00'" required />
        <InputField :id="`${prefix}-timezone`" name="timezone" :label="t('Time zone')" :model-value="schedule?.timezone ?? 'UTC'" maxlength="64" required />
        <div class="sm:col-span-2"><InputField :id="`${prefix}-starts`" name="starts_on" type="date" :label="t('First turn starts on')" :model-value="schedule?.startsOn ?? today" required /></div>
        <fieldset class="grid gap-3 sm:col-span-2 sm:grid-cols-2">
            <legend class="mb-2 text-sm font-bold text-ink">{{ t('Who takes turns, in order') }}</legend>
            <SelectField
                v-for="slot in slots"
                :id="`${prefix}-member-${slot}`"
                :key="slot"
                name="member_ids[]"
                error-key="member_ids"
                :label="t('Turn :number', { number: slot + 1 })"
                :placeholder="t('No one')"
                :options="members"
                :model-value="schedule?.memberIds[slot] ?? ''"
            />
        </fieldset>
    </div>
</template>
